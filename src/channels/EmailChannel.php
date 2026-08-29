<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\channels;

use Craft;
use craft\helpers\App;
use craft\helpers\Html;
use craft\helpers\Template;
use craft\helpers\UrlHelper;
use justinholtweb\schedulr\elements\Notification;
use justinholtweb\schedulr\models\Subscriber;
use justinholtweb\schedulr\Plugin;
use Throwable;

/**
 * Email.
 *
 * The channel that reaches the people push cannot, which on most sites is the majority. It exists
 * because the alternative — a push-only plugin — writes off everybody who pressed *Block*, everyone
 * on a browser without push, and everyone reading on an iPhone that has not added the site to its
 * home screen.
 *
 * Two things it deliberately does *not* do:
 *
 * - **It does not batch.** One email per recipient, because the alternative is a `Bcc:` list that
 *   discloses the subscriber list to everyone on it the first time somebody replies-all, and
 *   because a per-recipient unsubscribe link is not optional.
 * - **It does not render the notification's push copy verbatim.** A 120-character push title is a
 *   bad subject line and a 400-character body is a bad email, so the notification carries its own
 *   `emailSubject` and `emailBody`, falling back to the push copy when they are empty rather than
 *   refusing to send.
 */
class EmailChannel implements ChannelInterface
{
    public static function handle(): string
    {
        return Notification::CHANNEL_EMAIL;
    }

    public function canReach(Subscriber $subscriber): bool
    {
        return $subscriber->isEmailable();
    }

    public function send(
        Notification $notification,
        Subscriber $subscriber,
        array $overrides = [],
        ?int $variantId = null,
    ): SendResult {
        $address = $subscriber->getEmailAddress();

        if ($address === null || $address === '') {
            return SendResult::skipped('No email address.');
        }

        $settings = Plugin::getInstance()->getSettings();
        $title = trim((string)($overrides['title'] ?? $notification->title));
        $body = (string)($overrides['body'] ?? $notification->body ?? '');

        $subject = trim((string)$notification->emailSubject);
        $subject = $subject !== '' ? $subject : $title;

        $html = trim((string)$notification->emailBody);
        $html = $html !== '' ? $html : Html::tag('p', Html::encode($body));

        $url = Plugin::getInstance()->analytics->trackedUrl(
            (string)($overrides['url'] ?? $notification->url ?? ''),
            $notification->id,
            $variantId,
            $subscriber->id,
            'email',
        );

        try {
            $mailer = Craft::$app->getMailer();
            $message = $mailer->compose();

            $from = trim((string)App::parseEnv($settings->emailFromEmail));

            if ($from !== '') {
                $name = trim((string)App::parseEnv($settings->emailFromName));
                $message->setFrom($name !== '' ? [$from => $name] : $from);
            }

            $message
                ->setTo($address)
                ->setSubject($subject);

            $variables = [
                'notification' => $notification,
                'subscriber' => $subscriber,
                'title' => $title,
                'body' => Template::raw($html),
                'url' => $url,
                'unsubscribeUrl' => $this->unsubscribeUrl($subscriber),
                'siteName' => Craft::$app->getSites()->getCurrentSite()->getName(),
            ];

            $template = trim($settings->emailTemplate);

            if ($template !== '') {
                // Rendered in the *site* template mode: a notification email is site content, and
                // rendering it in CP mode would resolve partials against the wrong root and fail
                // only on the sends triggered from the control panel.
                $message->setHtmlBody(
                    Craft::$app->getView()->renderTemplate($template, $variables, \craft\web\View::TEMPLATE_MODE_SITE),
                );
            } else {
                $message->setHtmlBody($this->fallbackBody($variables));
            }

            // Header, not just a footer link. Gmail and Outlook surface it as a one-click
            // unsubscribe, and a bulk sender without it is a bulk sender in the spam folder.
            $message->setHeader('List-Unsubscribe', '<' . $variables['unsubscribeUrl'] . '>');
            $message->setHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

            if (!$mailer->send($message)) {
                return SendResult::failed('The mailer refused the message.');
            }

            return SendResult::delivered();
        } catch (Throwable $e) {
            Plugin::error('Notification email failed: ' . $e->getMessage());

            return SendResult::failed($e->getMessage());
        }
    }

    /**
     * A per-subscriber unsubscribe URL.
     *
     * Signed with Craft's security component rather than carrying a bare ID, so the link in one
     * person's email cannot be edited into a link that unsubscribes somebody else — which is what
     * `?id=41` in a mailing list footer always turns out to be.
     */
    private function unsubscribeUrl(Subscriber $subscriber): string
    {
        $token = Craft::$app->getSecurity()->hashData((string)$subscriber->id);

        // `token` is reserved by Craft for preview and share tokens: a request carrying `?token=`
        // is rejected in `Application::init()` with "Invalid token" before any controller runs.
        return UrlHelper::siteUrl('schedulr/unsubscribe', ['sr_u' => $token]);
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function fallbackBody(array $variables): string
    {
        // Deliberately plain and table-free. A bundled template that tries to be a designed email
        // is a template every site has to override, and one that ships broken in Outlook is worse
        // than one that ships boring.
        $parts = [
            Html::tag('h1', Html::encode((string)$variables['title']), [
                'style' => 'font:600 20px/1.3 -apple-system,Segoe UI,Helvetica,Arial,sans-serif;margin:0 0 12px;',
            ]),
            Html::tag('div', (string)$variables['body'], [
                'style' => 'font:15px/1.55 -apple-system,Segoe UI,Helvetica,Arial,sans-serif;color:#333;',
            ]),
        ];

        $url = (string)$variables['url'];

        if ($url !== '') {
            $parts[] = Html::tag('p', Html::a(Craft::t('schedulr', 'Read more'), $url, [
                'style' => 'display:inline-block;padding:10px 18px;background:'
                    . Plugin::getInstance()->getSettings()->accentColor
                    . ';color:#fff;border-radius:6px;text-decoration:none;font:600 14px sans-serif;',
            ]), ['style' => 'margin:20px 0 0;']);
        }

        $parts[] = Html::tag('p', Html::a(
            Craft::t('schedulr', 'Unsubscribe from these notifications'),
            (string)$variables['unsubscribeUrl'],
            ['style' => 'color:#888;'],
        ), ['style' => 'margin:28px 0 0;font:12px/1.5 sans-serif;color:#888;']);

        return Html::tag('div', implode('', $parts), [
            'style' => 'max-width:560px;margin:0 auto;padding:24px;',
        ]);
    }
}
