<?php

declare(strict_types=1);

namespace WPAiSuite\Security;

/**
 * Persistenz-Port fuer wpais_api_keys (Bauplan Abschnitt 4). Wird von ProviderFactory und dem
 * Admin-Settings-Screen aufgerufen (jeweils ausserhalb von src/Security/) — bekommt daher nach
 * Regel 4 ("Wie dieses Dokument zu benutzen ist") einen eigenen Interface-Contract, auch wenn
 * Abschnitt 2 nur ApiKeyVault.php als Datei nennt. ApiKeyVault selbst bleibt reine Kryptografie
 * (siehe dort); diese Schnittstelle kapselt die DB-Zugriffs-Seite.
 */
interface ApiKeyRepositoryInterface
{
    /** Verschluesselt $plaintextApiKey und speichert/aktualisiert die Zeile fuer $provider. */
    public function store(string $provider, string $plaintextApiKey): void;

    /** @return string|null Entschluesselter Key, oder null wenn nicht konfiguriert/deaktiviert. */
    public function retrieve(string $provider): ?string;

    public function isConfigured(string $provider): bool;

    public function setActive(string $provider, bool $isActive): void;

    public function delete(string $provider): void;

    /** @return string[] Provider-Keys mit aktivem, gespeichertem API-Key. */
    public function configuredProviders(): array;
}
