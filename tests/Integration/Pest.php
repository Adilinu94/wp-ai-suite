<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Integration Test Case
|--------------------------------------------------------------------------
|
| Bewusst in einer EIGENEN, verzeichnis-gebundenen Pest.php (nicht in
| tests/Pest.php): WP_UnitTestCase existiert nur, wenn die WordPress-Test-
| Suite geladen ist. Wuerde dieses uses() im globalen tests/Pest.php stehen,
| wuerde bereits `vendor/bin/pest --testsuite=Unit` ohne WP-Testumgebung
| fehlschlagen. So bleibt die Unit-Suite unabhaengig lauffaehig.
|
| Setup-Voraussetzung (noch offen, siehe tests/Integration/README.md):
| WP-Test-Suite + Test-Datenbank via bin/install-wp-tests.sh, WP_TESTS_DIR
| Umgebungsvariable, tests/bootstrap-integration.php.
|
*/

uses(WP_UnitTestCase::class)->in(__DIR__);
