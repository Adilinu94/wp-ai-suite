<?php

declare(strict_types=1);

namespace WPAiSuite\Security;

/**
 * wpdb-Adapter fuer die wpais_api_keys-Tabelle (Bauplan Abschnitt 4). Kennt nur encrypted_key
 * und nonce als Rohdaten, Ver-/Entschluesselung uebernimmt vollstaendig ApiKeyVault.
 *
 * Integration-Test-Territorium (Bauplan Abschnitt 14: WP_UnitTestCase, echte wpdb) — im
 * Gegensatz zu ApiKeyVault, das ohne WP-Bootstrap unit-testbar ist.
 */
final class WpdbApiKeyRepository implements ApiKeyRepositoryInterface
{
    public function __construct(
        private readonly \wpdb $wpdb,
        private readonly ApiKeyVault $vault,
    ) {
    }

    private function table(): string
    {
        return $this->wpdb->prefix . 'wpais_api_keys';
    }

    public function store(string $provider, string $plaintextApiKey): void
    {
        $secret = $this->vault->encrypt($plaintextApiKey);
        $table = $this->table();

        $this->wpdb->query(
            $this->wpdb->prepare(
                "INSERT INTO {$table} (provider, encrypted_key, nonce, is_active, created_at)
                 VALUES (%s, %s, %s, 1, %s)
                 ON DUPLICATE KEY UPDATE encrypted_key = VALUES(encrypted_key), nonce = VALUES(nonce), is_active = 1",
                $provider,
                $secret->cipherBase64,
                $secret->nonceBase64,
                current_time('mysql', true),
            ),
        );
    }

    public function retrieve(string $provider): ?string
    {
        $table = $this->table();

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT encrypted_key, nonce FROM {$table} WHERE provider = %s AND is_active = 1",
                $provider,
            ),
            ARRAY_A,
        );

        if (!is_array($row)) {
            return null;
        }

        return $this->vault->decrypt(new EncryptedSecret($row['encrypted_key'], $row['nonce']));
    }

    public function isConfigured(string $provider): bool
    {
        $table = $this->table();

        $count = (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE provider = %s AND is_active = 1",
                $provider,
            ),
        );

        return $count > 0;
    }

    public function setActive(string $provider, bool $isActive): void
    {
        $this->wpdb->update(
            $this->table(),
            ['is_active' => $isActive ? 1 : 0],
            ['provider' => $provider],
            ['%d'],
            ['%s'],
        );
    }

    public function delete(string $provider): void
    {
        $this->wpdb->delete($this->table(), ['provider' => $provider], ['%s']);
    }

    public function configuredProviders(): array
    {
        $table = $this->table();
        $rows = $this->wpdb->get_col("SELECT provider FROM {$table} WHERE is_active = 1");

        return array_map('strval', $rows ?: []);
    }
}
