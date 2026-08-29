<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\channels;

use Craft;
use craft\helpers\App;
use craft\helpers\Json;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use justinholtweb\schedulr\elements\Notification;
use justinholtweb\schedulr\models\Subscriber;
use justinholtweb\schedulr\Plugin;
use justinholtweb\schedulr\push\Encryptor;
use Throwable;

/**
 * Web push.
 *
 * One message, one device, one HTTPS request to a push service that cannot read what it is
 * relaying. Everything interesting is in `push\Encryptor`; what lives here is the decision this
 * class exists to make: **what a status code means about the device**.
 *
 * That decision is load-bearing because there is no other feedback. A push service returns 201 for
 * a message it will deliver and 201 for a message whose encryption is subtly wrong, so the codes
 * that *aren't* 201 are the entire diagnostic surface:
 *
 * - **404 / 410** — the endpoint is retired. The device is gone.
 * - **429 / 5xx** — the push service is busy or broken. The device is fine.
 * - **413** — the payload was too big. The *message* is wrong, not the device.
 * - **401 / 403** — the VAPID signing is wrong. Every device will fail identically, so this is
 *   logged loudly rather than counted as a per-device failure.
 */
class PushChannel implements ChannelInterface
{
    /** How long a push service should hold a message for a device that is offline. Four weeks. */
    private const TTL = 2419200;

    public static function handle(): string
    {
        return Notification::CHANNEL_PUSH;
    }

    public function canReach(Subscriber $subscriber): bool
    {
        return $subscriber->isPushable();
    }

    public function send(
        Notification $notification,
        Subscriber $subscriber,
        array $overrides = [],
        ?int $variantId = null,
    ): SendResult {
        if (!$this->canReach($subscriber)) {
            return SendResult::skipped('Not reachable by push.');
        }

        $plugin = Plugin::getInstance();

        try {
            $payload = Json::encode($notification->toPayload($overrides, $subscriber->id, $variantId));

            if (strlen($payload) > Encryptor::MAX_PAYLOAD) {
                // Checked before encrypting rather than after: the push service would answer 413
                // per device, so this turns one authoring mistake into one error instead of fifty
                // thousand requests.
                return SendResult::failed('The notification payload is too large to send.', 413);
            }

            $body = Encryptor::encrypt($payload, (string)$subscriber->p256dh, (string)$subscriber->auth);

            $headers = [
                'Authorization' => Encryptor::vapidHeader(
                    (string)$subscriber->endpoint,
                    $this->subject(),
                    $plugin->keys->getPublicKey(),
                    $plugin->keys->getPrivateKey(),
                ),
                'Content-Encoding' => 'aes128gcm',
                'Content-Type' => 'application/octet-stream',
                'TTL' => (string)self::TTL,
                'Urgency' => 'normal',
            ];

            $tag = trim((string)$notification->tag);

            if ($tag !== '') {
                // `Topic` lets the push service *replace* an undelivered message with a newer one
                // on the same subject, rather than delivering a week of stale ones at once when a
                // laptop comes out of a drawer.
                $headers['Topic'] = substr((string)preg_replace('/[^A-Za-z0-9_-]/', '', $tag), 0, 32);
            }

            $response = Craft::createGuzzleClient(['timeout' => 10])->request('POST', (string)$subscriber->endpoint, [
                'headers' => $headers,
                'body' => $body,
                'http_errors' => false,
            ]);

            $code = $response->getStatusCode();

            if ($code >= 200 && $code < 300) {
                return SendResult::delivered($code);
            }

            if ($code === 404 || $code === 410) {
                return SendResult::gone('The push service has retired this subscription.', $code);
            }

            if ($code === 401 || $code === 403) {
                // Not the device's fault and not worth incrementing its failure count: a signing
                // problem fails every device in the batch, and counting it per device would empty
                // the whole list over five sends.
                Plugin::error(sprintf(
                    'A push service rejected Schedulr’s VAPID credentials (%d). Check the push subject setting and the keypair. Response: %s',
                    $code,
                    substr((string)$response->getBody(), 0, 300),
                ));

                return SendResult::failed('The push service rejected this site’s credentials.', $code);
            }

            return SendResult::failed(substr((string)$response->getBody(), 0, 500) ?: 'Unknown push failure.', $code);
        } catch (ConnectException $e) {
            return SendResult::failed('Could not reach the push service: ' . $e->getMessage());
        } catch (RequestException $e) {
            return SendResult::failed($e->getMessage(), $e->getResponse()?->getStatusCode());
        } catch (Throwable $e) {
            Plugin::error('Push send failed: ' . $e->getMessage());

            return SendResult::failed($e->getMessage());
        }
    }

    /**
     * The VAPID `sub` claim.
     *
     * Some push services accept an absent one; enough of them do not that sending without one is a
     * coin flip, so a derived default beats an intermittent failure nobody can reproduce.
     */
    private function subject(): string
    {
        $subject = trim((string)App::parseEnv(Plugin::getInstance()->getSettings()->pushSubject));

        if ($subject !== '') {
            return $subject;
        }

        $from = Craft::$app->getProjectConfig()->get('email.fromEmail');

        if (is_string($from) && $from !== '') {
            return 'mailto:' . App::parseEnv($from);
        }

        $request = Craft::$app->getRequest();
        $host = $request->getIsConsoleRequest()
            ? (string)parse_url(Craft::$app->getSites()->getPrimarySite()->getBaseUrl() ?? '', PHP_URL_HOST)
            : $request->getHostName();

        return 'mailto:webmaster@' . ($host ?: 'example.com');
    }
}
