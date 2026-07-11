<?php

declare(strict_types=1);

use WPAiSuite\Security\ApiKeyVault;
use WPAiSuite\Security\WpdbApiKeyRepository;

/**
 * Braucht eine echte (Test-)wpdb-Instanz inkl. wpais_api_keys-Tabelle — siehe
 * tests/Integration/README.md fuer den noch offenen Setup-Schritt. In dieser Sandbox nicht
 * ausfuehrbar (keine WordPress-Installation); Logik wurde stattdessen ueber ApiKeyVault
 * (Unit-Tests) sowie eine manuelle SQL-Review gegen Migrator::createTables() abgesichert.
 */
beforeEach(function (): void {
    global $wpdb;

    if (defined('WPAIS_ENCRYPTION_KEY')) {
        $vault = ApiKeyVault::fromWpConfigConstant();
    } else {
        $vault = new ApiKeyVault(ApiKeyVault::generateMasterKey());
    }

    $this->repository = new WpdbApiKeyRepository($wpdb, $vault);
});

test('store() then retrieve() round-trips the plaintext key through the real wpais_api_keys table', function (): void {
    $this->repository->store('openai', 'sk-integration-test');

    expect($this->repository->retrieve('openai'))->toBe('sk-integration-test')
        ->and($this->repository->isConfigured('openai'))->toBeTrue();
});

test('setActive(false) hides the key from retrieve() without deleting the row', function (): void {
    $this->repository->store('anthropic', 'sk-ant-integration');
    $this->repository->setActive('anthropic', false);

    expect($this->repository->retrieve('anthropic'))->toBeNull()
        ->and($this->repository->isConfigured('anthropic'))->toBeFalse();
});

test('delete() removes the row entirely', function (): void {
    $this->repository->store('custom', 'sk-custom-integration');
    $this->repository->delete('custom');

    expect($this->repository->retrieve('custom'))->toBeNull();
});

test('configuredProviders() lists only active providers', function (): void {
    $this->repository->store('openai', 'sk-a');
    $this->repository->store('anthropic', 'sk-b');
    $this->repository->setActive('anthropic', false);

    expect($this->repository->configuredProviders())->toBe(['openai']);
});
