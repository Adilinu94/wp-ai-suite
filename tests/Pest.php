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

/*
| Umbauplan Post-MVP Punkt 8: PdfUploadValidator ist ansonsten WP-frei (siehe dessen Docblock),
| baut seine deutschen Fehlermeldungen aber ueber __() statt hartkodierter Strings (Konsistenz
| mit dem Rest der Codebase, wo jeder nutzersichtbare Text i18n-faehig ist) — derselbe Grund wie
| bei esc_attr() oben, nur fuer Uebersetzung statt Escaping.
*/
if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

/*
| Umbauplan Post-MVP Punkt 3: ActionSchedulerIngestionDispatcher::isAvailable() prueft
| function_exists('as_schedule_single_action') — ohne diesen Stub waere die Funktion in der
| Testumgebung nie vorhanden, isAvailable() also IMMER false, und der eigentliche
| Einplanungs-Pfad (statt des Sync-Rueckfalls) liesse sich nie testen. Zeichnet Aufrufe in
| $GLOBALS['wpais_test_scheduled_actions'] auf, damit Tests darauf assertieren koennen — Reset
| passiert im jeweiligen Test selbst (Konvention: beforeEach setzt das Array leer), nicht hier
| global, damit dieser Stub keine versteckte Test-Reihenfolge-Abhaengigkeit einfuehrt.
*/
if (!function_exists('as_schedule_single_action')) {
    function as_schedule_single_action($timestamp, $hook, $args = [], $group = ''): int
    {
        $GLOBALS['wpais_test_scheduled_actions'][] = [
            'timestamp' => $timestamp,
            'hook' => $hook,
            'args' => $args,
            'group' => $group,
        ];

        return count($GLOBALS['wpais_test_scheduled_actions']);
    }
}
