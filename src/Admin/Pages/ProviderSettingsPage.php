<?php

declare(strict_types=1);

namespace WPAiSuite\Admin\Pages;

use WPAiSuite\AiCore\Provider\ProviderFactory;
use WPAiSuite\Security\ApiKeyRepositoryInterface;

/**
 * Phase-1-Umfang laut M1-DoD (Bauplan Abschnitt 15): Provider-Auswahl + API-Key-Eingabe, schreibt
 * ueber ApiKeyRepositoryInterface (welches wiederum ApiKeyVault fuer die Verschluesselung nutzt).
 *
 * Der System-Prompt-Editor aus Abschnitt 11 gehoert zur Prompt Engine und damit zu einem
 * spaeteren Meilenstein (Prompt/SystemPromptBuilder existiert noch nicht) — bewusst NICHT Teil
 * dieser Seite, siehe Regel 2 ("Architektur nicht eigenmaechtig erweitern"). Kann als eigener
 * Tab/eigene Seite ergaenzt werden, sobald die Prompt Engine ansteht.
 */
final class ProviderSettingsPage
{
    private const OPTION_ACTIVE_PROVIDER = 'wpais_active_provider';
    private const OPTION_CUSTOM_LABEL = 'wpais_custom_label';
    private const OPTION_CUSTOM_BASE_URL = 'wpais_custom_base_url';
    private const NONCE_ACTION = 'wpais_save_provider_settings';
    private const CAPABILITY = 'manage_options';
    private const MANAGED_KEY_FIELDS = ['openai', 'anthropic', 'custom'];

    public function __construct(
        private readonly ApiKeyRepositoryInterface $apiKeys,
        private readonly ProviderFactory $providerFactory,
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_post_' . self::NONCE_ACTION, [$this, 'handleSave']);
    }

    public function addMenuPage(): void
    {
        add_menu_page(
            __('WP AI Suite', 'wp-ai-suite'),
            __('WP AI Suite', 'wp-ai-suite'),
            self::CAPABILITY,
            'wpais-settings',
            [$this, 'renderPage'],
            'dashicons-format-chat',
        );
    }

    public function renderPage(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Keine Berechtigung.', 'wp-ai-suite'));
        }

        $activeProvider = (string) get_option(self::OPTION_ACTIVE_PROVIDER, '');

        echo '<div class="wrap"><h1>' . esc_html__('WP AI Suite — Provider', 'wp-ai-suite') . '</h1>';

        if (isset($_GET['wpais_saved'])) {
            echo '<div class="notice notice-success"><p>' . esc_html__('Einstellungen gespeichert.', 'wp-ai-suite') . '</p></div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::NONCE_ACTION, '_wpais_nonce');
        echo '<input type="hidden" name="action" value="' . esc_attr(self::NONCE_ACTION) . '" />';
        echo '<table class="form-table"><tbody>';

        $this->renderActiveProviderSelect($activeProvider);
        $this->renderKeyField('openai', __('OpenAI API-Key', 'wp-ai-suite'));
        $this->renderKeyField('anthropic', __('Anthropic API-Key', 'wp-ai-suite'));
        $this->renderCustomProviderFields();

        echo '</tbody></table>';
        submit_button(__('Speichern', 'wp-ai-suite'));
        echo '</form></div>';
    }

    private function renderActiveProviderSelect(string $activeProvider): void
    {
        $knownProviders = [
            'openai' => __('OpenAI', 'wp-ai-suite'),
            'anthropic' => __('Anthropic', 'wp-ai-suite'),
            'custom' => __('OpenAI-kompatibel (DeepSeek, Mistral, Ollama, ...)', 'wp-ai-suite'),
        ];

        echo '<tr><th scope="row"><label for="wpais_active_provider">' . esc_html__('Aktiver Provider', 'wp-ai-suite') . '</label></th><td>';
        echo '<select name="wpais_active_provider" id="wpais_active_provider">';
        foreach ($knownProviders as $key => $label) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($key),
                selected($activeProvider, $key, false),
                esc_html($label),
            );
        }
        echo '</select></td></tr>';
    }

    private function renderKeyField(string $providerKey, string $label): void
    {
        $isConfigured = $this->apiKeys->isConfigured($providerKey);
        $placeholder = $isConfigured
            ? __('•••••••• (gespeichert — zum Aendern ueberschreiben)', 'wp-ai-suite')
            : '';

        echo '<tr><th scope="row"><label for="wpais_key_' . esc_attr($providerKey) . '">' . esc_html($label) . '</label></th><td>';
        printf(
            '<input type="password" class="regular-text" name="wpais_key_%1$s" id="wpais_key_%1$s" placeholder="%2$s" autocomplete="off" />',
            esc_attr($providerKey),
            esc_attr($placeholder),
        );
        echo '</td></tr>';
    }

    private function renderCustomProviderFields(): void
    {
        echo '<tr><th scope="row"><label for="wpais_custom_label">' . esc_html__('OpenAI-kompatibel — Name', 'wp-ai-suite') . '</label></th><td>';
        printf(
            '<input type="text" class="regular-text" name="wpais_custom_label" id="wpais_custom_label" value="%s" placeholder="z.B. DeepSeek" />',
            esc_attr((string) get_option(self::OPTION_CUSTOM_LABEL, '')),
        );
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="wpais_custom_base_url">' . esc_html__('Basis-URL', 'wp-ai-suite') . '</label></th><td>';
        printf(
            '<input type="url" class="regular-text" name="wpais_custom_base_url" id="wpais_custom_base_url" value="%s" placeholder="https://api.deepseek.com/v1" />',
            esc_attr((string) get_option(self::OPTION_CUSTOM_BASE_URL, '')),
        );
        echo '<p class="description">' . esc_html__('Bekannte Presets (DeepSeek, Mistral) werden automatisch erkannt, wenn hier leer gelassen und "custom" als aktiver Provider gewaehlt wird — sonst manuell eintragen (z.B. lokales Ollama).', 'wp-ai-suite') . '</p>';
        echo '</td></tr>';

        $this->renderKeyField('custom', __('OpenAI-kompatibel — API-Key', 'wp-ai-suite'));
    }

    public function handleSave(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Keine Berechtigung.', 'wp-ai-suite'));
        }

        check_admin_referer(self::NONCE_ACTION, '_wpais_nonce');

        $activeProvider = isset($_POST['wpais_active_provider'])
            ? sanitize_key(wp_unslash($_POST['wpais_active_provider']))
            : '';
        update_option(self::OPTION_ACTIVE_PROVIDER, $activeProvider);

        foreach (self::MANAGED_KEY_FIELDS as $providerKey) {
            $fieldName = 'wpais_key_' . $providerKey;
            if (!empty($_POST[$fieldName])) {
                $plainKey = sanitize_text_field(wp_unslash($_POST[$fieldName]));
                $this->apiKeys->store($providerKey, $plainKey);
            }
        }

        if (isset($_POST['wpais_custom_base_url'])) {
            update_option(self::OPTION_CUSTOM_BASE_URL, esc_url_raw(wp_unslash($_POST['wpais_custom_base_url'])));
        }

        if (isset($_POST['wpais_custom_label'])) {
            update_option(self::OPTION_CUSTOM_LABEL, sanitize_text_field(wp_unslash($_POST['wpais_custom_label'])));
        }

        $redirectTarget = wp_get_referer();
        if ($redirectTarget === false) {
            $redirectTarget = admin_url('admin.php?page=wpais-settings');
        }

        wp_safe_redirect(add_query_arg('wpais_saved', '1', $redirectTarget));
        exit;
    }
}
