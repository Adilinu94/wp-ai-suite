<?php

declare(strict_types=1);

use WPAiSuite\Security\ApiKeyVault;
use WPAiSuite\Security\EncryptedSecret;
use WPAiSuite\Security\VaultException;

test('encrypt/decrypt round-trip returns the original plaintext', function (): void {
    $vault = new ApiKeyVault(ApiKeyVault::generateMasterKey());

    $secret = $vault->encrypt('sk-super-secret-key');

    expect($secret->cipherBase64)->not->toBe('sk-super-secret-key')
        ->and($vault->decrypt($secret))->toBe('sk-super-secret-key');
});

test('encrypting the same plaintext twice yields different ciphertext (random nonce)', function (): void {
    $vault = new ApiKeyVault(ApiKeyVault::generateMasterKey());

    $first = $vault->encrypt('same-plaintext');
    $second = $vault->encrypt('same-plaintext');

    expect($first->cipherBase64)->not->toBe($second->cipherBase64)
        ->and($first->nonceBase64)->not->toBe($second->nonceBase64);
});

test('decrypting with the wrong master key throws', function (): void {
    $vaultA = new ApiKeyVault(ApiKeyVault::generateMasterKey());
    $vaultB = new ApiKeyVault(ApiKeyVault::generateMasterKey());

    $secret = $vaultA->encrypt('sk-secret');

    expect(fn () => $vaultB->decrypt($secret))->toThrow(VaultException::class);
});

test('decrypting tampered ciphertext throws', function (): void {
    $vault = new ApiKeyVault(ApiKeyVault::generateMasterKey());
    $secret = $vault->encrypt('sk-secret');

    $tampered = new EncryptedSecret(
        cipherBase64: base64_encode('x' . base64_decode($secret->cipherBase64, true)),
        nonceBase64: $secret->nonceBase64,
    );

    expect(fn () => $vault->decrypt($tampered))->toThrow(VaultException::class);
});

test('an invalid master key length throws on use', function (): void {
    $vault = new ApiKeyVault(base64_encode('too-short'));

    expect(fn () => $vault->encrypt('sk-secret'))->toThrow(VaultException::class);
});

test('fromWpConfigConstant() throws a clear error when the constant is undefined', function (): void {
    expect(fn () => ApiKeyVault::fromWpConfigConstant())
        ->toThrow(VaultException::class, 'WPAIS_ENCRYPTION_KEY');
});

test('generateMasterKey() produces a key of the correct byte length', function (): void {
    $key = ApiKeyVault::generateMasterKey();
    $raw = base64_decode($key, true);

    expect($raw)->not->toBeFalse()
        ->and(strlen($raw))->toBe(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
});
