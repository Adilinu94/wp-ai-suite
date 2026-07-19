<?php

declare(strict_types=1);

use WPAiSuite\Core\Plugin;
use WPAiSuite\Security\VaultException;

if (!function_exists('add_action')) {
    /**
     * Stub fuer WordPress' add_action() — im Unit-Test-Context (kein WordPress-
     * Bootstrap) nicht verfuegbar. bootSafely() ruft add_action() im catch-Block
     * auf; dieser Stub verhindert den "Call to undefined function"-Fatal Error.
     */
    function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void
    {
        // Stub: nichts tun, nur existieren.
    }
}

/**
 * Testet den bootSafely()-Helper von Plugin via Reflection isoliert, ohne
 * WordPress-Bootstrap. Stellt sicher, dass VaultException (fehlender
 * WPAIS_ENCRYPTION_KEY) niemals zu einem Fatal Error fuehrt, sondern
 * abgefangen wird.
 *
 * Hinweis: Die WordPress-Hook-Registrierung (add_action/admin_notices) wird
 * NICHT hier getestet — dafuer ist WordPress-Context noetig. Siehe den
 * korrespondierenden Integrationstest.
 */

beforeEach(function (): void {
    $this->plugin = Plugin::instance();

    $ref = new ReflectionMethod(Plugin::class, 'bootSafely');
    $ref->setAccessible(true);

    $this->invokeBootSafely = function (callable $bootFn, string $label) use ($ref): void {
        $ref->invoke($this->plugin, $bootFn, $label);
    };
});

test('bootSafely() ruft die uebergebene Callable erfolgreich auf', function (): void {
    $called = false;

    ($this->invokeBootSafely)(function () use (&$called): void {
        $called = true;
    }, 'Test-Service');

    expect($called)->toBeTrue();
});

test('bootSafely() faengt VaultException und wirft sie nicht weiter', function (): void {
    ($this->invokeBootSafely)(static function (): void {
        throw new VaultException('WPAIS_ENCRYPTION_KEY fehlt.');
    }, 'Test-Service');

    // Kein Throw → Test gilt als bestanden.
    expect(true)->toBeTrue();
});

test('bootSafely() laesst andere Exceptions ungehindert durch', function (): void {
    expect(fn () => ($this->invokeBootSafely)(static function (): void {
        throw new \RuntimeException('Unrelated error');
    }, 'Test-Service'))
        ->toThrow(\RuntimeException::class, 'Unrelated error');
});
