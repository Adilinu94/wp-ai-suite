<?php

declare(strict_types=1);

namespace WPAiSuite\Admin;

use WPAiSuite\Security\ApiKeyVault;

/**
 * Zeigt auf ALLEN wp-admin-Seiten eine unuebersehbare Admin-Notice, wenn der
 * Verschluesselungs-Schluessel WPAIS_ENCRYPTION_KEY nicht in wp-config.php
 * definiert ist. Enthaelt eine Schritt-fuer-Schritt-Anleitung zur Erzeugung
 * und zum Eintrag des Schluessels.
 *
 * Die Notice ist NICHT dismissible — sie verschwindet erst, wenn der
 * Schluessel tatsaechlich in wp-config.php gesetzt ist und die Seite neu
 * geladen wird.
 *
 * Nur fuer Benutzer mit 'manage_options'-Capability sichtbar.
 */
final class EncryptionKeyNotice
{
    /**
     * @param string|null $encryptionKeyValue Wenn null (Default), wird der Wert aus der
     *        WPAIS_ENCRYPTION_KEY-Konstante gelesen. Ein expliziter String (z.B. '' oder ein
     *        Base64-Key) ueberschreibt das — wird nur in Tests genutzt, nicht in Produktion.
     */
    public function __construct(
        private readonly ?string $encryptionKeyValue = null,
    ) {
    }

    public function register(): void
    {
        add_action('admin_notices', [$this, 'render']);
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Schluessel ist gesetzt und nicht leer → kein Hinweis noetig.
        $value = $this->resolveEncryptionKeyValue();
        if ($value !== null && $value !== '') {
            return;
        }

        $key = ApiKeyVault::generateMasterKey();
        $constant = ApiKeyVault::WP_CONFIG_CONSTANT;

        ?>
        <div class="notice notice-error" style="padding: 12px 16px;">
            <p>
                <strong><?php esc_html_e('WP AI Suite — Einrichtung erforderlich', 'wp-ai-suite'); ?></strong>
            </p>
            <p>
                <?php esc_html_e('Der Verschluesselungs-Schluessel fehlt. Ohne ihn kann das Plugin keine API-Keys speichern und keine KI-Funktionen nutzen.', 'wp-ai-suite'); ?>
            </p>
            <ol style="margin-left: 20px; list-style: decimal;">
                <li>
                    <?php esc_html_e('Oeffne die Datei', 'wp-ai-suite'); ?>
                    <code>wp-config.php</code>
                    <?php esc_html_e('im Wurzelverzeichnis deiner WordPress-Installation.', 'wp-ai-suite'); ?>
                </li>
                <li>
                    <?php esc_html_e('Fuege folgende Zeile VOR dem Kommentar', 'wp-ai-suite'); ?>
                    <code>/* That's all, stop editing! Happy publishing. */</code>
                    <?php esc_html_e('ein:', 'wp-ai-suite'); ?>
                    <br>
                    <textarea
                        readonly
                        rows="2"
                        style="width: 100%; max-width: 700px; font-family: monospace; font-size: 12px; margin-top: 4px; background: #f0f0f1; border: 1px solid #c3c4c7; padding: 6px 8px; resize: none; cursor: pointer;"
                        onclick="this.select(); navigator.clipboard && navigator.clipboard.writeText(this.value) || document.execCommand('copy');"
                        title="<?php esc_attr_e('Klicken zum Kopieren', 'wp-ai-suite'); ?>"
                    >define('<?php echo esc_js($constant); ?>', '<?php echo esc_js($key); ?>');</textarea>
                    <br>
                    <small style="color: #646970;">
                        <?php esc_html_e('Klicke auf das Textfeld, um die Zeile in die Zwischenablage zu kopieren.', 'wp-ai-suite'); ?>
                    </small>
                </li>
                <li>
                    <?php esc_html_e('Speichere die Datei und lade diese Seite neu.', 'wp-ai-suite'); ?>
                </li>
            </ol>
            <p>
                <strong><?php esc_html_e('Hinweis:', 'wp-ai-suite'); ?></strong>
                <?php esc_html_e('Bei jedem Seitenaufruf wird ein neuer Schluessel generiert — kopiere ihn daher JETZT, bevor du die Seite neu laedst.', 'wp-ai-suite'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Liefert den Wert der WPAIS_ENCRYPTION_KEY-Konstante. Wird im Konstruktor
     * ein expliziter Wert gesetzt (nur fuer Tests), hat dieser Vorrang.
     *
     * Rueckgabe:
     *  - null: Konstante nicht definiert
     *  - '':   Konstante definiert aber leer
     *  - non-empty-string: gueltiger Schluessel
     */
    private function resolveEncryptionKeyValue(): ?string
    {
        if ($this->encryptionKeyValue !== null) {
            return $this->encryptionKeyValue;
        }

        if (!defined(ApiKeyVault::WP_CONFIG_CONSTANT)) {
            return null;
        }

        $value = constant(ApiKeyVault::WP_CONFIG_CONSTANT);

        return is_string($value) ? $value : null;
    }
}
