<?php

declare(strict_types=1);

namespace WPAiSuite\Frontend\ChatWidget;

/**
 * Registriert das JS-Bundle + Stylesheet frueh (wp_enqueue_scripts-Hook, laeuft auf jeder
 * Anfrage, registriert aber nur die Handles ohne sie zu laden), enqueued sie aber erst, wenn
 * enqueue() tatsaechlich aufgerufen wird — vom Shortcode (M3) bzw. spaeter vom Elementor-Widget
 * (M8). So laedt das Bundle nie sitewide, nur auf Seiten, die den Chat tatsaechlich einbetten.
 *
 * wp-i18n als Script-Dependency + wp_set_script_translations(): das JS-Bundle nutzt
 * wp.i18n.__() fuer Nutzertext, analog zu __() auf der PHP-Seite (Bauplan-Konvention).
 */
final class AssetManager
{
    private const HANDLE = 'wpais-chat';

    private bool $enqueued = false;

    public function __construct(
        private readonly string $pluginUrl,
        private readonly string $version,
    ) {
    }

    public function registerAssets(): void
    {
        add_action('wp_enqueue_scripts', function (): void {
            wp_register_script(
                self::HANDLE,
                $this->pluginUrl . 'assets/js/wpais-chat.js',
                ['wp-i18n'],
                $this->version,
                true,
            );
            wp_set_script_translations(self::HANDLE, 'wp-ai-suite');

            wp_register_style(
                self::HANDLE,
                $this->pluginUrl . 'assets/css/wpais-chat.css',
                [],
                $this->version,
            );
        });
    }

    public function enqueue(): void
    {
        if ($this->enqueued) {
            return;
        }

        $this->enqueued = true;

        wp_enqueue_script(self::HANDLE);
        wp_enqueue_style(self::HANDLE);

        wp_localize_script(self::HANDLE, 'wpaisChatConfig', [
            'chatUrl' => esc_url_raw(rest_url('wpais/v1/chat')),
            'conversationsUrlBase' => esc_url_raw(rest_url('wpais/v1/conversations/')),
            'nonce' => wp_create_nonce('wp_rest'),
        ]);
    }
}
