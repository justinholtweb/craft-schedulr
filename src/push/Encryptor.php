<?php

namespace justinholtweb\schedulr\push;

use RuntimeException;

/**
 * Web push encryption and VAPID signing, in pure PHP against ext-openssl.
 *
 * This is the one part of the plugin with no room for "close enough". A push payload is encrypted
 * end to end (RFC 8291) with a key derived from the browser's own keypair, so the push service
 * relays a message it cannot read — and if any step of the derivation is off by a byte, the push
 * service still accepts the request, still returns 201, and the notification simply never appears.
 * There is no error to read. That is why this class is free of Craft, free of state, and covered
 * by the test vector from RFC 8291 §5: if the output matches those bytes, the chain is right.
 *
 * The other half is VAPID (RFC 8292): a short-lived ES256 JWT that identifies *this server* to the
 * push service. It is what makes a subscription belong to a site rather than to whoever obtained
 * the endpoint.
 *
 * No dependency does this for us, on purpose. The alternatives pull in a JWT library, a crypto
 * library, and their transitive tree, for two hundred lines of arithmetic.
 *
 * **This file is shared verbatim with PWA** (`justinholtweb\pwa\push\Encryptor`), namespace
 * aside. It is the one place where duplicating code beats extracting it: a shared package would
 * bind two independently released plugins to one version of the thing neither of them can ship
 * broken, and the file has no reason ever to change — RFC 8291 and RFC 8292 are done. If it does
 * change, the RFC 8291 §5 vector test in both repos is what proves the change was harmless.
 */
class Encryptor
{
    /** The curve everything here uses. Web push does not permit another. */
    public const CURVE = 'prime256v1';

    /** Ceiling on one push message after encryption. Every push service enforces roughly this. */
    public const MAX_PAYLOAD = 4078;

    private const RECORD_SIZE = 4096;

    // ------------------------------------------------------------------------------ keys

    /**
     * A fresh VAPID keypair.
     *
     * The public key is returned in the form a browser wants — base64url of the 65-byte
     * uncompressed point — and the private key as PEM, which is the form openssl will hand back
     * for signing.
     *
     * @return array{publicKey: string, privateKey: string}
     */
    public static function generateKeys(): array
    {
        $key = openssl_pkey_new([
            'curve_name' => self::CURVE,
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);

        if ($key === false) {
            throw new RuntimeException('Could not generate an EC keypair: ' . self::opensslErrors());
        }

        openssl_pkey_export($key, $pem);
        $details = openssl_pkey_get_details($key);

        if (!is_array($details) || !isset($details['ec']['x'], $details['ec']['y'])) {
            throw new RuntimeException('The generated key has no EC details.');
        }

        return [
            'publicKey' => self::encode(self::point($details['ec']['x'], $details['ec']['y'])),
            'privateKey' => trim((string)$pem),
        ];
    }

    /**
     * Rebuilds a PEM private key from a raw base64url keypair.
     *
     * Every other web push implementation stores VAPID keys as two base64url strings, so anybody
     * arriving from one has exactly that and no PEM. Both halves are needed: openssl will not
     * derive the public point from the scalar, so a keypair is imported as a pair or not at all.
     */
    public static function importPrivateKey(string $publicKey, string $privateKey): string
    {
        $point = self::decode($publicKey);
        $scalar = self::decode($privateKey);

        if (strlen($point) !== 65 || $point[0] !== "\x04") {
            throw new RuntimeException('The public key is not a 65-byte uncompressed P-256 point.');
        }

        if (strlen($scalar) !== 32) {
            throw new RuntimeException('The private key is not a 32-byte P-256 scalar.');
        }

        // SEC 1 ECPrivateKey, which is what a PEM "EC PRIVATE KEY" block contains.
        $der = self::sequence(
            self::integer("\x01")
            . self::octetString($scalar)
            . self::tagged(0, self::oid("\x2a\x86\x48\xce\x3d\x03\x01\x07")) // prime256v1
            . self::tagged(1, self::bitString($point)),
        );

        return "-----BEGIN EC PRIVATE KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END EC PRIVATE KEY-----\n";
    }

    /** Whether a PEM string is a usable P-256 private key. */
    public static function isUsableKey(string $pem): bool
    {
        $key = @openssl_pkey_get_private($pem);

        if ($key === false) {
            return false;
        }

        $details = openssl_pkey_get_details($key);

        return is_array($details) && ($details['type'] ?? null) === OPENSSL_KEYTYPE_EC;
    }

    // ----------------------------------------------------------------------------- VAPID

    /**
     * The `Authorization` header value for one endpoint.
     *
     * The audience is the *origin* of the push endpoint and nothing more — including the path
     * makes the push service reject the token, and the error it returns says only "invalid JWT".
     *
     * Expiry is capped at 24 hours by the spec; twelve is used here so that a queued job which
     * sits for a few hours still sends with a token that is valid when it finally runs.
     */
    public static function vapidHeader(
        string $endpoint,
        string $subject,
        string $publicKey,
        string $privateKeyPem,
        ?int $now = null,
    ): string {
        $parts = parse_url($endpoint);

        if (!isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException('The push endpoint is not a URL.');
        }

        $audience = $parts['scheme'] . '://' . $parts['host'];
        $now ??= time();

        $header = self::encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256'], JSON_THROW_ON_ERROR));
        $claims = self::encode(json_encode([
            'aud' => $audience,
            'exp' => $now + 43200,
            'sub' => $subject,
        ], JSON_THROW_ON_ERROR));

        $signature = self::sign($header . '.' . $claims, $privateKeyPem);

        return 'vapid t=' . $header . '.' . $claims . '.' . self::encode($signature) . ', k=' . $publicKey;
    }

    /**
     * ES256 over the JWT signing input.
     *
     * openssl produces a DER-encoded (r, s) pair; JWS wants the two integers concatenated as raw
     * 32-byte values. Skipping that conversion produces a signature that is structurally valid and
     * universally rejected.
     */
    private static function sign(string $input, string $privateKeyPem): string
    {
        $key = openssl_pkey_get_private($privateKeyPem);

        if ($key === false) {
            throw new RuntimeException('The VAPID private key could not be read: ' . self::opensslErrors());
        }

        if (!openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Signing failed: ' . self::opensslErrors());
        }

        return self::derToJose($der);
    }

    private static function derToJose(string $der): string
    {
        $offset = 2;

        // A DER SEQUENCE whose contents run past 127 bytes carries a long-form length; an ES256
        // signature never does, but reading the byte rather than assuming it costs nothing.
        if ((ord($der[1]) & 0x80) !== 0) {
            $offset += ord($der[1]) & 0x7f;
        }

        $parts = [];

        for ($i = 0; $i < 2; $i++) {
            if ($der[$offset] !== "\x02") {
                throw new RuntimeException('Malformed DER signature.');
            }

            $length = ord($der[$offset + 1]);
            $value = substr($der, $offset + 2, $length);
            $offset += 2 + $length;

            // Integers are signed in DER, so a value whose top bit is set is prefixed with 0x00.
            $value = ltrim($value, "\x00");
            $parts[] = str_pad($value, 32, "\x00", STR_PAD_LEFT);
        }

        return $parts[0] . $parts[1];
    }

    // ------------------------------------------------------------------------ encryption

    /**
     * Encrypts one payload for one subscription, producing an `aes128gcm` body.
     *
     * `$ephemeralPem` and `$salt` exist only so the RFC's test vector can be reproduced. In
     * production both are generated here, and both must be: reusing an ephemeral keypair across
     * messages defeats the forward secrecy that is the reason it exists.
     *
     * @param string $p256dh The subscription's public key, base64url.
     * @param string $auth The subscription's auth secret, base64url.
     */
    public static function encrypt(
        string $payload,
        string $p256dh,
        string $auth,
        ?string $ephemeralPem = null,
        ?string $salt = null,
    ): string {
        $uaPublic = self::decode($p256dh);
        $authSecret = self::decode($auth);

        if (strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04") {
            throw new RuntimeException('The subscription key is not a 65-byte uncompressed P-256 point.');
        }

        if (strlen($authSecret) !== 16) {
            throw new RuntimeException('The subscription auth secret must be 16 bytes.');
        }

        // The application server's throwaway keypair for this one message.
        if ($ephemeralPem !== null) {
            $ephemeral = openssl_pkey_get_private($ephemeralPem);

            if ($ephemeral === false) {
                throw new RuntimeException('The supplied ephemeral key could not be read.');
            }
        } else {
            $ephemeral = openssl_pkey_new([
                'curve_name' => self::CURVE,
                'private_key_type' => OPENSSL_KEYTYPE_EC,
            ]);

            if ($ephemeral === false) {
                throw new RuntimeException('Could not generate an ephemeral keypair: ' . self::opensslErrors());
            }
        }

        $details = openssl_pkey_get_details($ephemeral);

        if (!is_array($details) || !isset($details['ec']['x'], $details['ec']['y'])) {
            throw new RuntimeException('The ephemeral key has no EC details.');
        }

        $asPublic = self::point($details['ec']['x'], $details['ec']['y']);

        $shared = openssl_pkey_derive(self::publicKeyPem($uaPublic), $ephemeral, 32);

        if ($shared === false) {
            throw new RuntimeException('ECDH agreement failed: ' . self::opensslErrors());
        }

        // RFC 8291 §3.4. The key info binds the derived secret to *both* public keys, which is
        // what stops a relayed message being decryptable by anybody but this subscription.
        $keyInfo = 'WebPush: info' . "\x00" . $uaPublic . $asPublic;
        $ikm = hash_hkdf('sha256', $shared, 32, $keyInfo, $authSecret);

        $salt ??= random_bytes(16);

        if (strlen($salt) !== 16) {
            throw new RuntimeException('The salt must be 16 bytes.');
        }

        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

        // A single record, so the delimiter is 0x02 ("last record") rather than 0x01.
        $plaintext = $payload . "\x02";

        $cipher = openssl_encrypt($plaintext, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);

        if ($cipher === false) {
            throw new RuntimeException('Payload encryption failed: ' . self::opensslErrors());
        }

        // RFC 8188 header: salt, record size, key id length, key id (the ephemeral public key).
        return $salt
            . pack('N', self::RECORD_SIZE)
            . chr(strlen($asPublic))
            . $asPublic
            . $cipher
            . $tag;
    }

    /** Wraps a raw 65-byte point as a PEM public key, which is the only form openssl accepts. */
    private static function publicKeyPem(string $point): string
    {
        // SubjectPublicKeyInfo for id-ecPublicKey over prime256v1, followed by the point.
        $der = self::sequence(
            self::sequence(
                self::oid("\x2a\x86\x48\xce\x3d\x02\x01")       // id-ecPublicKey
                . self::oid("\x2a\x86\x48\xce\x3d\x03\x01\x07"), // prime256v1
            )
            . self::bitString($point),
        );

        return "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($der), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
    }

    // --------------------------------------------------------------------------- encoding

    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function decode(string $value): string
    {
        $value = strtr(trim($value), '-_', '+/');
        $decoded = base64_decode($value . str_repeat('=', (4 - strlen($value) % 4) % 4), true);

        if ($decoded === false) {
            throw new RuntimeException('Value is not valid base64url.');
        }

        return $decoded;
    }

    /** Uncompressed point from the coordinates openssl reports, each padded to the curve size. */
    private static function point(string $x, string $y): string
    {
        return "\x04" . str_pad($x, 32, "\x00", STR_PAD_LEFT) . str_pad($y, 32, "\x00", STR_PAD_LEFT);
    }

    // A minimal DER writer. Only the shapes above are needed, and a general one would be more
    // code to review for no more capability.

    private static function length(string $contents): string
    {
        $length = strlen($contents);

        if ($length < 128) {
            return chr($length);
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function sequence(string $contents): string
    {
        return "\x30" . self::length($contents) . $contents;
    }

    private static function integer(string $contents): string
    {
        return "\x02" . self::length($contents) . $contents;
    }

    private static function octetString(string $contents): string
    {
        return "\x04" . self::length($contents) . $contents;
    }

    private static function oid(string $contents): string
    {
        return "\x06" . self::length($contents) . $contents;
    }

    private static function bitString(string $contents): string
    {
        // The leading zero is the count of unused bits in the final byte, which for a key is none.
        return "\x03" . self::length("\x00" . $contents) . "\x00" . $contents;
    }

    private static function tagged(int $tag, string $contents): string
    {
        return chr(0xa0 | $tag) . self::length($contents) . $contents;
    }

    private static function opensslErrors(): string
    {
        $messages = [];

        while (($error = openssl_error_string()) !== false) {
            $messages[] = $error;
        }

        return implode('; ', $messages) ?: 'no detail from openssl';
    }
}
