<?php

declare(strict_types=1);

namespace WPAiSuite\Admin;

/**
 * Bauplan Abschnitt 9 (M9, DSGVO): "Datenschutz-Hinweistext-Baustein als Admin-Notice". Kein
 * automatischer Eintrag in die Datenschutzerklaerung der Website (WP hat dafuer keinen
 * zuverlaessigen, plugin-uebergreifenden Mechanismus) — stattdessen ein fertig formulierter
 * Textbaustein zum Copy-Paste in die eigene Datenschutzerklaerung, als dismissible Notice NUR auf
 * der eigenen Plugin-Seite gezeigt (kein Zuspammen anderer wp-admin-Seiten).
 *
 * Bewusst kein neuer DB-Eintrag fuer "wurde bereits kopiert/gesehen" — WordPress' eingebauter
 * `is-dismissible`-Mechanismus blendet die Notice per JS/User-Meta client-seitig aus, das reicht
 * hier, der Text ist jederzeit ueber Neuladen der Seite wieder abrufbar.
 */
final class PrivacyNoticeAdminNotice
{
    public function register(): void
    {
        add_action('admin_notices', [$this, 'render']);
    }

    public function render(): void
    {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;

        if ($screen === null || $screen->id !== 'toplevel_page_wpais-settings') {
            return;
        }

        $text = __(
            'Diese Website nutzt WP AI Suite fuer einen KI-gestuetzten Chat-Assistenten. ' .
            'Nachrichteninhalte werden zur Beantwortung an den konfigurierten KI-Anbieter ' .
            '(OpenAI, Anthropic oder einen kompatiblen Dienst) uebertragen und in der ' .
            'WordPress-Datenbank gespeichert, bis die konfigurierte Aufbewahrungsfrist ' .
            'abgelaufen ist oder die Konversation manuell geloescht wird. Es werden keine ' .
            'IP-Adressen gespeichert.',
            'wp-ai-suite',
        );

        echo '<div class="notice notice-info is-dismissible"><p><strong>' .
            esc_html__('WP AI Suite — Textbaustein fuer die Datenschutzerklaerung', 'wp-ai-suite') .
            '</strong></p><p>' . esc_html($text) . '</p><p><em>' .
            esc_html__('Bitte pruefen und an die eigene Datenschutzerklaerung anpassen — dieser Text ersetzt keine Rechtsberatung.', 'wp-ai-suite') .
            '</em></p></div>';
    }
}
