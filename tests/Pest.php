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
