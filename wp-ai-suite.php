<?php
/**
 * Plugin Name:       WP AI Suite
 * Plugin URI:        https://github.com/Adilinu94/wp-ai-suite
 * Description:       Enterprise-KI-Plattform fuer WordPress. Phase-1-MVP gemaess BAUPLAN-PHASE1-MVP.md.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Adi
 * Text Domain:       wp-ai-suite
 * Domain Path:       /languages
 *
 * Platzhalter-Produktname. "WPAiSuite" (Namespace), "wpais_" (DB-Prefix) und
 * "wp-ai-suite" (Text-Domain/Slug) sind konsistent im gesamten Projekt und
 * koennen bei Bedarf gemeinsam per Suchen&Ersetzen umbenannt werden.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('WPAIS_VERSION', '0.1.0');
define('WPAIS_PLUGIN_FILE', __FILE__);
define('WPAIS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPAIS_PLUGIN_URL', plugin_dir_url(__FILE__));

$wpais_autoload = WPAIS_PLUGIN_DIR . 'vendor/autoload.php';
if (file_exists($wpais_autoload)) {
    require_once $wpais_autoload;
}
unset($wpais_autoload);

/**
 * Strauss rewrites smalot/pdfparser namespaces (PSR-0) into WPAiSuite\Vendor\... but leaves
 * the physical layout under vendor-scoped/smalot/pdfparser/src/Smalot/.... The generated PSR-0
 * map then looks for src/WPAiSuite/Vendor/Smalot/... which does not exist. Bridge that gap.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'WPAiSuite\\Vendor\\Smalot\\PdfParser\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = 'Smalot/PdfParser/' . str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = WPAIS_PLUGIN_DIR . 'vendor-scoped/smalot/pdfparser/src/' . $relative . '.php';
    if (is_readable($file)) {
        require_once $file;
    }
}, true, true);

/**
 * Aktivierung: legt alle Plugin-Tabellen an (siehe Migrator, DB-Design in
 * BAUPLAN-PHASE1-MVP.md Abschnitt 4). Muss idempotent sein (dbDelta).
 */
register_activation_hook(__FILE__, static function (): void {
    if (class_exists(\WPAiSuite\Core\Database\Migrator::class)) {
        \WPAiSuite\Core\Database\Migrator::createTables();
    }
});

/**
 * Deaktivierung loescht bewusst KEINE Daten (WordPress-Konvention).
 * Vollstaendiger Datenabbau (DSGVO) passiert ausschliesslich in uninstall.php.
 */
register_deactivation_hook(__FILE__, static function (): void {
    do_action('wpais_deactivated');
});

add_action('plugins_loaded', static function (): void {
    if (class_exists(\WPAiSuite\Core\Plugin::class)) {
        \WPAiSuite\Core\Plugin::instance()->boot();
    }
});
