<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\services;

use craft\base\Component;
use craft\helpers\App;
use craft\helpers\Db;
use DateTime;
use justinholtweb\schedulr\db\Table;
use justinholtweb\schedulr\Plugin;
use justinholtweb\schedulr\push\Encryptor;
use yii\db\Query;

/**
 * The VAPID keypair — where it comes from, and who owns it.
 *
 * Three sources, in a fixed order, and the order is the whole design:
 *
 * 1. **PWA**, when it is installed and Schedulr is set to defer to it. Every subscription a
 *    browser holds is bound to the public key it was created with, so a site running both plugins
 *    must have exactly one keypair or one of the two lists is silently dead.
 * 2. **The environment**, so a site arriving from another push stack keeps its existing
 *    subscriptions working by pasting in the keys it already has.
 * 3. **Generated on first use**, because there is nothing a human can usefully choose here.
 *
 * The pair is never written to project config. A private key does not belong in a file that gets
 * committed, and a rotation arriving through a deployment would orphan a list nobody meant to
 * touch.
 */
class Keys extends Component
{
    /** @var array{publicKey: string, privateKey: string}|null */
    private ?array $keys = null;

    public function getPublicKey(): string
    {
        return $this->getKeys()['publicKey'];
    }

    public function getPrivateKey(): string
    {
        return $this->getKeys()['privateKey'];
    }

    /**
     * @return array{publicKey: string, privateKey: string}
     */
    public function getKeys(): array
    {
        if ($this->keys !== null) {
            return $this->keys;
        }

        $borrowed = Plugin::getInstance()->interop->getPwaKeys();

        if ($borrowed !== null) {
            return $this->keys = $borrowed;
        }

        $envPublic = trim((string)App::env('SCHEDULR_VAPID_PUBLIC_KEY'));
        $envPrivate = trim((string)App::env('SCHEDULR_VAPID_PRIVATE_KEY'));

        if ($envPublic !== '' && $envPrivate !== '') {
            // Other stacks store the private key as a raw base64url scalar; openssl needs PEM,
            // and it will not derive the public point from the scalar, so both halves are
            // required to rebuild one.
            $private = str_contains($envPrivate, 'BEGIN')
                ? $envPrivate
                : Encryptor::importPrivateKey($envPublic, $envPrivate);

            return $this->keys = ['publicKey' => $envPublic, 'privateKey' => $private];
        }

        $row = (new Query())
            ->select(['publicKey', 'privateKey'])
            ->from(Table::KEYS)
            ->orderBy(['id' => SORT_ASC])
            ->one();

        if ($row === false || $row === null) {
            return $this->keys = $this->generate();
        }

        return $this->keys = [
            'publicKey' => (string)$row['publicKey'],
            'privateKey' => (string)$row['privateKey'],
        ];
    }

    /** Where the current pair came from, for the settings screen to say so out loud. */
    public function getSource(): string
    {
        if (Plugin::getInstance()->interop->getPwaKeys() !== null) {
            return 'pwa';
        }

        if (trim((string)App::env('SCHEDULR_VAPID_PUBLIC_KEY')) !== '') {
            return 'env';
        }

        return 'generated';
    }

    /** Whether a pair exists without generating one — the settings screen must not create keys. */
    public function hasKeys(): bool
    {
        if (Plugin::getInstance()->interop->getPwaKeys() !== null) {
            return true;
        }

        if (trim((string)App::env('SCHEDULR_VAPID_PUBLIC_KEY')) !== '') {
            return true;
        }

        return (new Query())->from(Table::KEYS)->exists();
    }

    /**
     * Replaces the keypair, orphaning every existing subscription.
     *
     * There is no recovery from this and the CP says so twice before allowing it. Rotating is
     * refused outright when PWA owns the keys, because the damage would land on PWA's list as
     * well as this one and the button lives in the wrong plugin to be making that call.
     *
     * @return array{publicKey: string, privateKey: string}
     */
    public function rotate(bool $dropSubscribers = true): array
    {
        if (Plugin::getInstance()->interop->getPwaKeys() !== null) {
            throw new \RuntimeException('PWA owns the keys on this site. Rotate them in PWA.');
        }

        $db = \Craft::$app->getDb();
        $db->createCommand()->delete(Table::KEYS)->execute();
        $this->keys = null;

        if ($dropSubscribers) {
            // Not a truncate: the visitor identity survives so the on-site channel and the
            // "already declined" record are not lost along with the endpoints.
            $db->createCommand()->update(Table::SUBSCRIBERS, [
                'endpoint' => null,
                'endpointHash' => null,
                'p256dh' => null,
                'auth' => null,
                'dateSubscribed' => null,
                'failures' => 0,
            ])->execute();
        }

        Plugin::info('VAPID keypair rotated' . ($dropSubscribers ? ' and every push subscription cleared.' : '.'));

        return $this->generate();
    }

    /**
     * @return array{publicKey: string, privateKey: string}
     */
    private function generate(): array
    {
        $keys = Encryptor::generateKeys();
        $now = Db::prepareDateForDb(new DateTime());

        \Craft::$app->getDb()->createCommand()->insert(Table::KEYS, [
            'publicKey' => $keys['publicKey'],
            'privateKey' => $keys['privateKey'],
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => \craft\helpers\StringHelper::UUID(),
        ])->execute();

        Plugin::info('Generated a VAPID keypair.');

        return $this->keys = $keys;
    }
}
