# Integration-Tests — Setup (offener Punkt)

Diese Tests laufen gegen `WP_UnitTestCase` (echte `wpdb`, echte WordPress-Funktionen) und
brauchen die WordPress-PHPUnit-Testumgebung. Das ist **nicht** dasselbe wie `composer install` —
zusaetzlich noetig:

1. Ein WordPress-Core-Checkout + Test-Bibliothek (klassisch via
   [`bin/install-wp-tests.sh`](https://make.wordpress.org/cli/handbook/misc/plugin-unit-tests/)
   oder das `wp-phpunit/wp-phpunit`-Composer-Paket).
2. Eine (leere, ausschliesslich fuer Tests genutzte) MySQL-Test-Datenbank.
3. Die Umgebungsvariable `WP_TESTS_DIR`, die auf die installierte Test-Bibliothek zeigt.
4. Ein `tests/bootstrap-integration.php`, das `WP_TESTS_DIR . '/includes/bootstrap.php'` laedt
   und danach das Plugin selbst (`wp-ai-suite.php`) aktiviert, damit `Migrator::createTables()`
   einmalig laeuft.

Da dieses Repo bisher nur lokal (Local by Flywheel, `solar.local`) und nicht in einer
Sandbox-/CI-Umgebung mit MySQL lief, ist dieser Schritt bewusst **noch offen** — analog zum
bereits in `FORTSETZUNG.md` dokumentierten PHP/Composer-Gap fuer M0. Die Test-**Faelle** in
`tests/Integration/Security/WpdbApiKeyRepositoryTest.php` (M1) und
`tests/Integration/AiCore/Conversation/WpdbConversationRepositoryTest.php` (M2) sind fertig
geschrieben und laufen, sobald obiges Setup steht; ihre fachliche Korrektheit wurde stattdessen
indirekt ueber Unit-Tests (`ApiKeyVault`, `ConversationService` mit `FakeConversationRepository`)
sowie eine manuelle Pruefung gegen `Migrator::createTables()`s tatsaechliches Schema
sichergestellt.

## Lauf, sobald eingerichtet

```bash
WP_TESTS_DIR=/pfad/zur/wp-tests-lib vendor/bin/pest --testsuite=Integration
```
