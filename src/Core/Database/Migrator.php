<?php

declare(strict_types=1);

namespace WPAiSuite\Core\Database;

/**
 * Legt alle Phase-1-Tabellen an bzw. entfernt sie wieder.
 *
 * Schema-Referenz: BAUPLAN-PHASE1-MVP.md, Abschnitt 4 ("Datenbankdesign").
 * WICHTIG: dbDelta() ist sehr formatsensibel (siehe WP-Codex) - insbesondere
 * "PRIMARY KEY" gefolgt von genau zwei Leerzeichen vor der Klammer.
 * Nicht ohne Grund umformatieren.
 */
final class Migrator
{
    private const TABLE_SUFFIXES = [
        'conversations',
        'messages',
        'documents',
        'chunks',
        'api_keys',
        'usage_logs',
    ];

    public static function createTables(): void
    {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $prefix = $wpdb->prefix . 'wpais_';

        $sql = "CREATE TABLE {$prefix}conversations (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_token VARCHAR(64) NOT NULL,
            wp_user_id BIGINT UNSIGNED NULL,
            channel VARCHAR(20) NOT NULL DEFAULT 'website',
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            meta LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY session_token (session_token),
            KEY wp_user_id (wp_user_id)
        ) {$charset_collate};

        CREATE TABLE {$prefix}messages (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            conversation_id BIGINT UNSIGNED NOT NULL,
            role VARCHAR(20) NOT NULL,
            content LONGTEXT NOT NULL,
            tool_calls LONGTEXT NULL,
            provider VARCHAR(40) NULL,
            model VARCHAR(80) NULL,
            tokens_input INT UNSIGNED NULL,
            tokens_output INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY conversation_id (conversation_id)
        ) {$charset_collate};

        CREATE TABLE {$prefix}documents (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_type VARCHAR(30) NOT NULL,
            source_ref VARCHAR(255) NULL,
            title VARCHAR(255) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            version INT UNSIGNED NOT NULL DEFAULT 1,
            checksum VARCHAR(64) NULL,
            error_message TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY source_type (source_type),
            KEY status (status)
        ) {$charset_collate};

        CREATE TABLE {$prefix}chunks (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            document_id BIGINT UNSIGNED NOT NULL,
            chunk_index INT UNSIGNED NOT NULL,
            content MEDIUMTEXT NOT NULL,
            embedding LONGTEXT NOT NULL,
            token_count INT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY document_id (document_id)
        ) {$charset_collate};

        CREATE TABLE {$prefix}api_keys (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            provider VARCHAR(40) NOT NULL,
            encrypted_key LONGTEXT NOT NULL,
            nonce VARCHAR(64) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY provider (provider)
        ) {$charset_collate};

        CREATE TABLE {$prefix}usage_logs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            conversation_id BIGINT UNSIGNED NULL,
            provider VARCHAR(40) NOT NULL,
            model VARCHAR(80) NOT NULL,
            tokens_input INT UNSIGNED NOT NULL DEFAULT 0,
            tokens_output INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY provider (provider),
            KEY created_at (created_at)
        ) {$charset_collate};";

        dbDelta($sql);

        update_option('wpais_db_version', WPAIS_VERSION);
    }

    public static function dropTables(): void
    {
        global $wpdb;
        $prefix = $wpdb->prefix . 'wpais_';

        foreach (self::TABLE_SUFFIXES as $suffix) {
            $wpdb->query("DROP TABLE IF EXISTS {$prefix}{$suffix}");
        }

        delete_option('wpais_db_version');
    }
}
