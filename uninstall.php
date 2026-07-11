<?php

declare(strict_types=1);

// WordPress ruft diese Datei nur bei echter Deinstallation auf (nicht bei Deaktivierung).
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$wpais_autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($wpais_autoload)) {
    require_once $wpais_autoload;
}
unset($wpais_autoload);

// DSGVO: vollstaendiger Datenabbau. Tabellen (inkl. Konversationen, Chunks,
// Embeddings, verschluesselte Keys) werden entfernt.
if (class_exists(\WPAiSuite\Core\Database\Migrator::class)) {
    \WPAiSuite\Core\Database\Migrator::dropTables();
}

// Alle Plugin-Options entfernen. Liste erweitert sich mit jedem Meilenstein,
// der neue Options einfuehrt (M1: Provider-Settings, M9: Retention-Settings, ...).
$wpais_options = [
    'wpais_db_version',
    'wpais_active_provider',
    'wpais_system_prompt',
    'wpais_custom_label',
    'wpais_custom_base_url',
];

foreach ($wpais_options as $wpais_option) {
    delete_option($wpais_option);
}
