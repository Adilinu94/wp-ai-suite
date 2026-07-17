<?php

declare(strict_types=1);

namespace WPAiSuite\Tests\Unit\Security;

use WPAiSuite\Security\TransientStoreInterface;

final class FakeTransientStore implements TransientStoreInterface
{
    /** @var array<string, int> */
    private array $values = [];

    /** @var array<string, bool> Nur fuer Tests: simuliert ein abgelaufenes Fenster. */
    private array $expired = [];

    public function get(string $key): ?int
    {
        if (($this->expired[$key] ?? false)) {
            return null;
        }

        return $this->values[$key] ?? null;
    }

    public function set(string $key, int $value, int $ttlSeconds): void
    {
        $this->values[$key] = $value;
        $this->expired[$key] = false;
    }

    /** Nur fuer Tests: simuliert den Ablauf des Zeitfensters fuer $key. */
    public function expire(string $key): void
    {
        $this->expired[$key] = true;
    }
}
