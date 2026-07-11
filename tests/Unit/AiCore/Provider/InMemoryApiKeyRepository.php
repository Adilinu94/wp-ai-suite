<?php

declare(strict_types=1);

namespace WPAiSuite\Tests\Unit\AiCore\Provider;

use WPAiSuite\Security\ApiKeyRepositoryInterface;

/**
 * In-Memory-Fake fuer ApiKeyRepositoryInterface — haelt Klartext-Keys direkt im Array (kein
 * wpdb, keine echte Verschluesselung noetig), ausschliesslich fuer Unit-Tests von
 * ProviderFactory/ProviderSettingsPage.
 */
final class InMemoryApiKeyRepository implements ApiKeyRepositoryInterface
{
    /** @var array<string, array{key:string, active:bool}> */
    private array $keys = [];

    public function store(string $provider, string $plaintextApiKey): void
    {
        $this->keys[$provider] = ['key' => $plaintextApiKey, 'active' => true];
    }

    public function retrieve(string $provider): ?string
    {
        $entry = $this->keys[$provider] ?? null;

        return ($entry !== null && $entry['active']) ? $entry['key'] : null;
    }

    public function isConfigured(string $provider): bool
    {
        return ($this->keys[$provider]['active'] ?? false) === true;
    }

    public function setActive(string $provider, bool $isActive): void
    {
        if (isset($this->keys[$provider])) {
            $this->keys[$provider]['active'] = $isActive;
        }
    }

    public function delete(string $provider): void
    {
        unset($this->keys[$provider]);
    }

    public function configuredProviders(): array
    {
        return array_keys(array_filter($this->keys, static fn (array $e): bool => $e['active']));
    }
}
