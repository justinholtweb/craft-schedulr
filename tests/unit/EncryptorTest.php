<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\tests\unit;

use justinholtweb\schedulr\push\Encryptor;
use PHPUnit\Framework\TestCase;

/**
 * The one test in this plugin that cannot be replaced by inspection.
 *
 * Web push encryption has no useful failure signal: if any step of the RFC 8291 derivation is off by a
 * byte, the push service still accepts the request, still returns 201, and the notification simply never
 * appears. There is nothing to read in a log.
 *
 * So the encryptor is pinned to the test vector in **RFC 8291 §5**. If the output matches those bytes,
 * every link in the chain — ECDH, the HKDF key info, the CEK, the nonce, the record header — is right.
 * If it does not, nothing else in this file matters.
 */
final class EncryptorTest extends TestCase
{
    // RFC 8291 §5. The plaintext is "When I grow up, I want to be a watermelon".
    private const RFC_PLAINTEXT = 'When I grow up, I want to be a watermelon';
    private const RFC_UA_PUBLIC = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';
    private const RFC_AUTH = 'BTBZMqHH6r4Tts7J_aSIgg';
    private const RFC_AS_PRIVATE_D = 'yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw';
    private const RFC_AS_PUBLIC = 'BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8';
    private const RFC_SALT = 'DGv6ra1nlYgDCS1FRnbzlw';

    private const RFC_CIPHERTEXT = 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN';

    public function testMatchesTheRfc8291Vector(): void
    {
        $body = Encryptor::encrypt(
            self::RFC_PLAINTEXT,
            self::RFC_UA_PUBLIC,
            self::RFC_AUTH,
            Encryptor::importPrivateKey(self::RFC_AS_PUBLIC, self::RFC_AS_PRIVATE_D),
            Encryptor::decode(self::RFC_SALT),
        );

        self::assertSame(self::RFC_CIPHERTEXT, Encryptor::encode($body));
    }

    public function testGeneratesAUsableKeypair(): void
    {
        $keys = Encryptor::generateKeys();

        // 65 bytes: the 0x04 uncompressed-point marker plus two 32-byte coordinates. A browser rejects
        // an applicationServerKey of any other length, with a message that names no cause.
        self::assertSame(65, strlen(Encryptor::decode($keys['publicKey'])));
        self::assertSame("\x04", Encryptor::decode($keys['publicKey'])[0]);
        self::assertTrue(Encryptor::isUsableKey($keys['privateKey']));
    }

    public function testImportedKeysRoundTrip(): void
    {
        // The path a site takes when arriving from another push stack, which stores the pair as two
        // base64url strings and has no PEM.
        $pem = Encryptor::importPrivateKey(self::RFC_AS_PUBLIC, self::RFC_AS_PRIVATE_D);

        self::assertTrue(Encryptor::isUsableKey($pem));
        self::assertStringContainsString('BEGIN EC PRIVATE KEY', $pem);
    }

    public function testVapidHeaderAudienceIsTheOriginOnly(): void
    {
        $keys = Encryptor::generateKeys();

        $header = Encryptor::vapidHeader(
            'https://fcm.googleapis.com/fcm/send/abc123?x=1',
            'mailto:test@example.com',
            $keys['publicKey'],
            $keys['privateKey'],
            1_700_000_000,
        );

        self::assertStringStartsWith('vapid t=', $header);
        self::assertStringContainsString(', k=' . $keys['publicKey'], $header);

        [$encodedHeader, $encodedClaims] = explode('.', substr($header, 8, (int)strpos($header, ',') - 8));

        self::assertSame(['typ' => 'JWT', 'alg' => 'ES256'], json_decode(Encryptor::decode($encodedHeader), true));

        $claims = json_decode(Encryptor::decode($encodedClaims), true);

        // The audience is the origin and nothing more. Including the path makes the push service reject
        // the token with an error that says only "invalid JWT".
        self::assertSame('https://fcm.googleapis.com', $claims['aud']);
        self::assertSame('mailto:test@example.com', $claims['sub']);
        self::assertSame(1_700_000_000 + 43200, $claims['exp']);
    }

    public function testSignatureIsRawRS(): void
    {
        $keys = Encryptor::generateKeys();
        $header = Encryptor::vapidHeader('https://example.com/p', 'mailto:a@b.c', $keys['publicKey'], $keys['privateKey']);

        $token = substr($header, 8, (int)strpos($header, ',') - 8);
        $signature = Encryptor::decode(explode('.', $token)[2]);

        // 64 bytes, not a DER SEQUENCE. openssl produces DER; JWS wants r and s concatenated as raw
        // 32-byte values, and skipping that conversion yields a signature that is structurally valid
        // and universally rejected.
        self::assertSame(64, strlen($signature));
    }

    public function testRefusesAMalformedSubscription(): void
    {
        $this->expectException(\RuntimeException::class);

        Encryptor::encrypt('hello', Encryptor::encode('too short'), self::RFC_AUTH);
    }

    public function testRefusesAShortAuthSecret(): void
    {
        $this->expectException(\RuntimeException::class);

        Encryptor::encrypt('hello', self::RFC_UA_PUBLIC, Encryptor::encode('nope'));
    }

    public function testBase64UrlRoundTrips(): void
    {
        foreach (['', 'a', 'ab', 'abc', 'abcd', random_bytes(65)] as $value) {
            self::assertSame($value, Encryptor::decode(Encryptor::encode($value)));
        }

        // No padding, and the URL-safe alphabet: a `+` or `/` in an applicationServerKey is rejected by
        // the browser before any request is made.
        self::assertStringNotContainsString('=', Encryptor::encode(random_bytes(10)));
        self::assertStringNotContainsString('+', Encryptor::encode("\xfb\xff"));
        self::assertStringNotContainsString('/', Encryptor::encode("\xfb\xff"));
    }
}
