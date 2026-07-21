<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Unit-Tests (Bauplan Abschnitt 14: "Core, kein WP-Bootstrap") laufen gegen
| die reine PHPUnit\Framework\TestCase — keine WordPress-Funktionen noetig,
| da alle WP-Beruehrungspunkte hinter HttpTransportInterface bzw.
| ApiKeyRepositoryInterface stecken und in Unit-Tests durch Fakes ersetzt
| werden.
|
| Integration-Tests brauchen eine echte WordPress-Testumgebung
| (WP_UnitTestCase) und sind bewusst noch nicht an dieses uses() gebunden —
| siehe tests/Integration/README.md fuer den noch offenen Setup-Schritt.
|
*/

uses(TestCase::class)->in('Unit');

/*
|--------------------------------------------------------------------------
| Minimal WordPress stubs for unit tests that touch thin WP helper calls
|--------------------------------------------------------------------------
|
| ChatWidgetRenderer escapes attributes via esc_attr(). That is intentional
| (XSS-safe output under real WordPress). Unit tests do not bootstrap WP, so
| provide a tiny compatible stub only when the real function is absent.
*/

if (!function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
