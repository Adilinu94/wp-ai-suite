<?php

declare(strict_types=1);

namespace WPAiSuite\Security;

/**
 * Reine Ver-/Entschluesselung (sodium_crypto_secretbox) fuer API-Keys — kennt weder wpdb noch
 * WordPress-Hooks und ist dadurch vollstaendig unit-testbar ohne WP-Bootstrap (Bauplan
 * Abschnitt 14). Persistenz uebernimmt WpdbApiKeyRepository.
 *
 * Der Master-Schluessel liegt NICHT in der Datenbank, sondern als Konstante in wp-config.php
 * (Bauplan Abschnitt 9): define('WPAIS_ENCRYPTION_KEY', '...'); — analog zu WordPress' eigenen
 * AUTH_KEY-Salts. DB speichert ausschliesslich encrypted_key + nonce (wpais_api_keys, Abschnitt 4).
 */
final class ApiKeyVault
{
    public const WP_CONFIG_CONSTANT = 'WPAIS_ENCRYPTION_KEY';

    public function __construct(
        private readonly string $masterKeyBase64,
    ) {
    }

    /**
     * @throws VaultException Wenn die Konstante fehlt oder kein gueltiger Base64-Schluessel ist.
     */
    public static function fromWpConfigConstant(): self
    {
        if (!defined(self::WP_CONFIG_CONSTANT)) {
            throw new VaultException(sprintf(
                "Konstante %s ist nicht definiert. In wp-config.php ergaenzen: define('%s', '<Base64-Schluessel>'); " .
                'Neuen Schluessel erzeugen mit ApiKeyVault::generateMasterKey().',
                self::WP_CONFIG_CONSTANT,
                self::WP_CONFIG_CONSTANT,
            ));
        }

        /** @var mixed $value */
        $value = constant(self::WP_CONFIG_CONSTANT);

        if (!is_string($value) || $value === '') {
            throw new VaultException(sprintf('%s muss ein nicht-leerer String sein.', self::WP_CONFIG_CONSTANT));
        }

        return new self($value);
    }

    /** Erzeugt einen neuen, zufaelligen Master-Schluessel (Base64) fuer wp-config.php. */
    public static function generateMasterKey(): string
    {
        return base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    public function encrypt(string $plaintext): EncryptedSecret
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $this->rawKey());

        return new EncryptedSecret(
            cipherBase64: base64_encode($cipher),
            nonceBase64: base64_encode($nonce),
        );
    }

    /** @throws VaultException Bei ungueltigem Chiffrat oder falschem Schluessel. */
    public function decrypt(EncryptedSecret $secret): string
    {
        $cipher = base64_decode($secret->cipherBase64, true);
        $nonce = base64_decode($secret->nonceBase64, true);

        if ($cipher === false || $nonce === false || strlen($nonce) !== SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new VaultException('Gespeicherter Wert ist kein gueltiges Chiffrat.');
        }

        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->rawKey());

        if ($plain === false) {
            throw new VaultException('Entschluesselung fehlgeschlagen — falscher Schluessel oder manipulierte Daten.');
        }

        return $plain;
    }

    private function rawKey(): string
    {
        $raw = base64_decode($this->masterKeyBase64, true);

        if ($raw === false || strlen($raw) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new VaultException(sprintf(
                '%s muss zu %d Bytes dekodieren (Base64-Ausgabe von ApiKeyVault::generateMasterKey()).',
                self::WP_CONFIG_CONSTANT,
                SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            ));
        }

        return $raw;
    }
}
