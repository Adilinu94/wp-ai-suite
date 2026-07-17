<?php

declare(strict_types=1);

namespace WPAiSuite\Security;

/**
 * Referenzimplementierung von TransientStoreInterface ueber WordPress' eigene Transient-API
 * (get_transient()/set_transient()) — bei einer Standard-Installation faktisch ein
 * wp_options-Eintrag mit Ablaufzeit, bei aktivem Object-Cache-Plugin automatisch echtes
 * Caching (Redis/Memcached) ohne Aenderung an dieser Klasse.
 */
final class WpTransientStore implements TransientStoreInterface
{
    public function get(string $key): ?int
    {
        $value = get_transient($key);

        return $value === false ? null : (int) $value;
    }

    public function set(string $key, int $value, int $ttlSeconds): void
    {
        set_transient($key, $value, $ttlSeconds);
    }
}
