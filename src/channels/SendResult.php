<?php

declare(strict_types=1);

namespace justinholtweb\schedulr\channels;

use justinholtweb\schedulr\models\Delivery;

/**
 * What one attempt at one recipient came to.
 *
 * A value object rather than a tuple, because the three-way distinction between *failed*, *gone*
 * and *skipped* is the thing every caller has to get right and an array of three positional values
 * is how it gets got wrong.
 */
final class SendResult
{
    private function __construct(
        public readonly string $status,
        public readonly ?int $statusCode = null,
        public readonly ?string $error = null,
    ) {
    }

    public static function delivered(?int $code = null): self
    {
        return new self(Delivery::STATUS_DELIVERED, $code);
    }

    /** Temporarily unreachable. The subscriber stays on the list and the next send tries again. */
    public static function failed(?string $error, ?int $code = null): self
    {
        return new self(Delivery::STATUS_FAILED, $code, $error);
    }

    /** Permanently unreachable. The subscription is retired; retrying is pointless forever. */
    public static function gone(?string $error = null, ?int $code = null): self
    {
        return new self(Delivery::STATUS_GONE, $code, $error);
    }

    /** Reachable, but deliberately not sent. Not a failure and must never be counted as one. */
    public static function skipped(?string $reason = null): self
    {
        return new self(Delivery::STATUS_SKIPPED, null, $reason);
    }

    /** On-site only: accepted, and will be shown the next time the visitor loads a page. */
    public static function queued(): self
    {
        return new self(Delivery::STATUS_QUEUED);
    }

    public function isSuccess(): bool
    {
        return $this->status === Delivery::STATUS_DELIVERED || $this->status === Delivery::STATUS_QUEUED;
    }

    public function isFailure(): bool
    {
        return $this->status === Delivery::STATUS_FAILED || $this->status === Delivery::STATUS_GONE;
    }
}
