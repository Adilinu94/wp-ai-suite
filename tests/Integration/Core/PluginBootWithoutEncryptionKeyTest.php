<?php

declare(strict_types=1);

use WPAiSuite\Core\Plugin;

/**
 * Integrationstest: Stellt sicher, dass Plugin::boot() keinen Fatal Error
 * wirft, wenn WPAIS_ENCRYPTION_KEY in wp-config.php nicht definiert ist.
 *
 * Setup-Voraussetzung: WordPress-Test-Suite + Test-Datenbank via
 * bin/install-wp-tests.sh (siehe tests/Integration/README.md).
 * Laueft nur mit --testsuite=Integration.
 */

beforeEach(function (): void {
    // Sicherstellen, dass die Konstante NICHT existiert — genau das
    // Szenario, das den urspruenglichen Fatal Error ausgeloest hat.
    if (defined('WPAIS_ENCRYPTION_KEY')) {
        $this->markTestSkipped(
            'WPAIS_ENCRYPTION_KEY ist in der Testumgebung definiert — ' .
            'dieser Test braucht eine Umgebung OHNE den Schluessel.',
        );
    }
});

afterEach(function (): void {
    // Aufraeumen: bootSafely() registrierte Admin-Notices-Hooks entfernen,
    // damit keine State-Leaks zwischen Tests entstehen.
    remove_all_actions('admin_notices');
});

test('Plugin::boot() wirft keinen Fatal Error wenn WPAIS_ENCRYPTION_KEY fehlt', function (): void {
    // boot() darf keine Exception durchlassen — die bootSafely()-Helper
    // fangen VaultException ab und zeigen stattdessen Admin-Notices.
    Plugin::instance()->boot();

    // Wenn wir hier ankommen, gab es keinen Fatal Error.
    expect(true)->toBeTrue();
});

test('Bei fehlendem WPAIS_ENCRYPTION_KEY wird ein admin_notices-Hook registriert', function (): void {
    Plugin::instance()->boot();

    // Mindestens ein Service (ProviderSettingsPage) sollte via bootSafely()
    // einen Admin-Hinweis registriert haben.
    // has_action() gibt bei vorhandenem Hook die Prioritaet (int) zurueck,
    // sonst false.
    expect(has_action('admin_notices'))->not->toBeFalse();
});
