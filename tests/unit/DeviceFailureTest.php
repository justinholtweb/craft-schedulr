<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\tests\unit;

use justinholtweb\schedulr\services\Sender;
use PHPUnit\Framework\TestCase;

/**
 * Which push failures count against a device.
 *
 * Getting this wrong is silent and total: if a credential failure counted, five sends with a bad
 * VAPID keypair would retire every subscriber's push subscription, and the visitors would have to
 * opt in again one by one.
 */
final class DeviceFailureTest extends TestCase
{
    public function testSiteSideFailuresDoNotCount(): void
    {
        foreach ([401, 403, 413, 429, 500, 502, 503] as $code) {
            self::assertFalse(Sender::countsAgainstDevice($code), "HTTP $code should not count against the device");
        }
    }

    public function testDeviceSideFailuresCount(): void
    {
        self::assertTrue(Sender::countsAgainstDevice(null), 'an unreachable endpoint counts');
        self::assertTrue(Sender::countsAgainstDevice(400));
    }
}
