<?php

declare(strict_types=1);

namespace WPAiSuite\Security;

/**
 * Cipher und Nonce getrennt, Base64-kodiert — entspricht 1:1 den Spalten encrypted_key/nonce
 * der wpais_api_keys-Tabelle (Bauplan Abschnitt 4).
 */
final class EncryptedSecret
{
    public function __construct(
        public readonly string $cipherBase64,
        public readonly string $nonceBase64,
    ) {
    }
}
