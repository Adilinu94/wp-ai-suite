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

// M9: Cron-Event wird eigentlich schon bei Deaktivierung entfernt (wpais_deactivated-Hook,
// siehe Plugin.php), hier zusaetzlich defensiv (falls Deinstallation je ohne vorherige
// Deaktivierung erreichbar sein sollte).
wp_clear_scheduled_hook('wpais_retention_cleanup');

// Alle Plugin-Options entfernen. Liste erweitert sich mit jedem Meilenstein,
// der neue Options einfuehrt (M1: Provider-Settings, M9: Retention-/Rate-Limit-Settings).
$wpais_options = [
    'wpais_db_version',
    'wpais_active_provider',
    'wpais_system_prompt',
    'wpais_custom_label',
    'wpais_custom_base_url',
    'wpais_default_model_openai',
    'wpais_default_model_anthropic',
    'wpais_default_model_custom',
    // M9: Retention-/Rate-Limit-Settings.
    'wpais_retention_days',
    'wpais_rate_limit_max',
    'wpais_rate_limit_window_seconds',
    // Umbauplan Post-MVP Punkt 1: separater Embedding-Provider.
    'wpais_embedding_provider',
    'wpais_embedding_label',
    'wpais_embedding_base_url',
    'wpais_embedding_model',
    // Umbauplan Post-MVP Punkt 7: Proxy-Trust fuer die Rate-Limit-IP-Ermittlung.
    'wpais_trust_proxy',
    'wpais_trusted_proxies',
];

foreach ($wpais_options as $wpais_option) {
    delete_option($wpais_option);
}
