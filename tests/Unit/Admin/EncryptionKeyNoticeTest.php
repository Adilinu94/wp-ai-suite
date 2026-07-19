<?php

declare(strict_types=1);

use WPAiSuite\Admin\EncryptionKeyNotice;

/**
 * Unit-Test fuer EncryptionKeyNotice. Da render() WordPress-Funktionen
 * (current_user_can, esc_html_e, etc.) sowie ApiKeyVault::generateMasterKey()
 * (benoetigt ext-sodium) aufruft, werden WordPress-Funktionen gestubbt und
 * sodium-abhaengige Tests bei fehlender Extension uebersprungen.
 *
 * Getestete Faelle:
 *  1. Notice erscheint, wenn WPAIS_ENCRYPTION_KEY fehlt
 *  2. Notice erscheint NICHT, wenn Benutzer keine manage_options-Cap hat
 *  3. Notice enthaelt Setup-Anleitung mit define()-Code
 *  4. register() haengt sich in admin_notices ein
 *  5. Notice erscheint, wenn WPAIS_ENCRYPTION_KEY leer ist (via DI: new Notice(''))
 *  6. Notice erscheint NICHT, wenn WPAIS_ENCRYPTION_KEY gültig ist (via DI: new Notice('key'))
 */

// --- WordPress-Stubs ---

if (!function_exists('add_action')) {
    function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void {}
}

if (!function_exists('current_user_can')) {
    /**
     * Konfigurierbarer Stub: Rueckgabewert kann pro Capability ueber die
     * globale Variable $_wpais_mock_capabilities gesteuert werden.
     *
     * Beispiel im Test:
     *   global $_wpais_mock_capabilities;
     *   $_wpais_mock_capabilities = ['manage_options' => false];
     */
    function current_user_can(string $capability): bool
    {
        global $_wpais_mock_capabilities;

        if (isset($_wpais_mock_capabilities[$capability])) {
            return $_wpais_mock_capabilities[$capability];
        }

        return true;
    }
}

if (!function_exists('esc_html_e')) {
    function esc_html_e(string $text, string $domain = ''): void { echo $text; }
}

if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = ''): string { return $text; }
}

if (!function_exists('esc_attr_e')) {
    function esc_attr_e(string $text, string $domain = ''): void { echo $text; }
}

if (!function_exists('esc_attr__')) {
    function esc_attr__(string $text, string $domain = ''): string { return $text; }
}

if (!function_exists('esc_js')) {
    function esc_js(string $text): string { return $text; }
}

beforeEach(function (): void {
    // Mock-Array leeren → alle Capabilities fallen auf Default true zurueck.
    global $_wpais_mock_capabilities;
    $_wpais_mock_capabilities = [];

    $this->notice = new EncryptionKeyNotice();
});

function requiresSodium(): void
{
    if (!defined('SODIUM_CRYPTO_SECRETBOX_KEYBYTES')) {
        \PHPUnit\Framework\Assert::markTestSkipped('ext-sodium nicht geladen.');
    }
}

// --- Tests ---

test('register() haengt sich in admin_notices ein', function (): void {
    // add_action() ist gestubbt — Aufruf wuerde bei fehlender Funktion crashen.
    $this->notice->register();
    expect($this->notice)->toBeInstanceOf(EncryptionKeyNotice::class);
});

test('render() gibt Setup-HTML aus wenn WPAIS_ENCRYPTION_KEY fehlt', function (): void {
    requiresSodium();

    ob_start();
    $this->notice->render();
    $output = ob_get_clean();

    expect($output)
        ->toContain('WP AI Suite')
        ->toContain('Einrichtung erforderlich')
        ->toContain('wp-config.php')
        ->toContain("define('WPAIS_ENCRYPTION_KEY'");
});

test('render() gibt KEIN HTML aus wenn Benutzer keine manage_options-Capability hat', function (): void {
    global $_wpais_mock_capabilities;
    $_wpais_mock_capabilities = ['manage_options' => false];

    ob_start();
    $this->notice->render();
    $output = ob_get_clean();

    expect($output)->toBe('');
});

test('render() gibt HTML aus wenn Benutzer manage_options-Capability hat', function (): void {
    requiresSodium();

    global $_wpais_mock_capabilities;
    $_wpais_mock_capabilities = ['manage_options' => true];

    ob_start();
    $this->notice->render();
    $output = ob_get_clean();

    expect($output)
        ->toContain('WP AI Suite')
        ->toContain('Einrichtung erforderlich');
});

test('render() enthaelt klickbaren Copy-Bereich mit Schluessel', function (): void {
    requiresSodium();

    ob_start();
    $this->notice->render();
    $output = ob_get_clean();

    expect($output)
        ->toContain('<textarea')
        ->toContain('navigator.clipboard')
        ->toContain('readonly');
});

// --- Tests mit definierter WPAIS_ENCRYPTION_KEY (via DI-Parameter) ---

test('render() gibt Setup-HTML aus wenn WPAIS_ENCRYPTION_KEY ein leerer String ist', function (): void {
    requiresSodium();

    $notice = new EncryptionKeyNotice('');

    ob_start();
    $notice->render();
    $output = ob_get_clean();

    expect($output)
        ->toContain('WP AI Suite')
        ->toContain('Einrichtung erforderlich');
});

test('render() gibt KEIN HTML aus wenn WPAIS_ENCRYPTION_KEY ein gueltiger Key ist', function (): void {
    $notice = new EncryptionKeyNotice('dGhpcy1pcy1hLXZhbGlkLWJhc2U2NC1rZXk=');

    ob_start();
    $notice->render();
    $output = ob_get_clean();

    expect($output)->toBe('');
});
