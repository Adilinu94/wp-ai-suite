<?php

declare(strict_types=1);

namespace WPAiSuite\Admin\Pages;

use WPAiSuite\AiCore\Provider\ProviderFactory;
use WPAiSuite\Security\ApiKeyRepositoryInterface;
use WPAiSuite\Security\RetentionCleanup;

/**
 * Phase-1-Umfang laut M1-DoD (Bauplan Abschnitt 15): Provider-Auswahl + API-Key-Eingabe, schreibt
 * ueber ApiKeyRepositoryInterface (welches wiederum ApiKeyVault fuer die Verschluesselung nutzt).
 *
 * Um M2 (ChatRequest braucht ein konkretes $model) nachgereicht: Standard-Modell pro Provider
 * (Abschnitt 11 nennt das bereits als Teil von "Settings", in M1 aber zunaechst ausgelassen).
 *
 * Der System-Prompt-Editor aus Abschnitt 11 gehoert weiterhin zur Prompt Engine im engeren Sinn
 * (Editor-UI, nicht die Builder-Logik aus M2) und ist bewusst NICHT Teil dieser Seite — siehe
 * Regel 2 ("Architektur nicht eigenmaechtig erweitern"). Kann als eigener Tab ergaenzt werden,
 * sobald dafuer Bedarf besteht; bis dahin nutzt SystemPromptBuilder den Default-Text.
 */
final class ProviderSettingsPage
{
    private const OPTION_ACTIVE_PROVIDER = 'wpais_active_provider';
    private const OPTION_CUSTOM_LABEL = 'wpais_custom_label';
    private const OPTION_CUSTOM_BASE_URL = 'wpais_custom_base_url';
    private const NONCE_ACTION = 'wpais_save_provider_settings';
    private const CAPABILITY = 'manage_options';
    private const MANAGED_KEY_FIELDS = ['openai', 'anthropic', 'custom'];
    private const OPTION_DEFAULT_MODEL_PREFIX = 'wpais_default_model_';

    /**
     * M9: RateLimiter/RetentionCleanup selbst kennen keine WP-Optionen (nehmen fertige Werte
     * ueber ihren Konstruktor entgegen, siehe deren Docblocks) — die Optionsnamen gehoeren
     * deshalb hierher, zur Klasse, die das Einstellungsformular dafuer rendert/speichert, genau
     * wie bei allen anderen Optionen auf dieser Seite. Plugin.php liest sie beim Verdrahten.
     */
    public const OPTION_RATE_LIMIT_MAX = 'wpais_rate_limit_max';
    public const OPTION_RATE_LIMIT_WINDOW = 'wpais_rate_limit_window_seconds';
    public const DEFAULT_RATE_LIMIT_MAX = 20;
    public const DEFAULT_RATE_LIMIT_WINDOW = 600;
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

    /** Wird auch von Plugin.php beim Verdrahten von ConversationService genutzt. */
    public static function defaultModelOptionName(string $providerKey): string
    {
        return self::OPTION_DEFAULT_MODEL_PREFIX . $providerKey;
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
        $this->renderModelField('openai', __('OpenAI Standard-Modell', 'wp-ai-suite'), 'z.B. gpt-5.2-mini');
        $this->renderKeyField('anthropic', __('Anthropic API-Key', 'wp-ai-suite'));
        $this->renderModelField('anthropic', __('Anthropic Standard-Modell', 'wp-ai-suite'), 'z.B. claude-sonnet-5');
        $this->renderCustomProviderFields();

        echo '</tbody></table>';
        echo '<h2>' . esc_html__('Sicherheit', 'wp-ai-suite') . '</h2>';
        echo '<table class="form-table"><tbody>';
        $this->renderSecurityFields();
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

    private function renderModelField(string $providerKey, string $label, string $placeholder): void
    {
        $optionName = self::defaultModelOptionName($providerKey);

        echo '<tr><th scope="row"><label for="wpais_default_model_' . esc_attr($providerKey) . '">' . esc_html($label) . '</label></th><td>';
        printf(
            '<input type="text" class="regular-text" name="wpais_default_model_%1$s" id="wpais_default_model_%1$s" value="%2$s" placeholder="%3$s" />',
            esc_attr($providerKey),
            esc_attr((string) get_option($optionName, '')),
            esc_attr($placeholder),
        );
        echo '<p class="description">' . esc_html__('Exakte Modell-ID des Providers. Verfuegbare IDs kann der jeweilige Adapter live per listModels() abfragen — bislang nur programmatisch, keine UI-Dropdown-Anbindung in M2.', 'wp-ai-suite') . '</p>';
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
        $this->renderModelField('custom', __('OpenAI-kompatibel — Standard-Modell', 'wp-ai-suite'), 'z.B. deepseek-chat');
    }

    /** M9: Rate-Limiting + Aufbewahrungsfrist (Bauplan Abschnitt 9). */
    private function renderSecurityFields(): void
    {
        echo '<tr><th scope="row"><label for="wpais_rate_limit_max">' . esc_html__('Rate-Limit: max. Nachrichten', 'wp-ai-suite') . '</label></th><td>';
        printf(
            '<input type="number" min="1" step="1" class="small-text" name="wpais_rate_limit_max" id="wpais_rate_limit_max" value="%s" /> ',
            esc_attr((string) get_option(self::OPTION_RATE_LIMIT_MAX, self::DEFAULT_RATE_LIMIT_MAX)),
        );
        esc_html_e('Nachrichten pro', 'wp-ai-suite');
        printf(
            ' <input type="number" min="1" step="1" class="small-text" name="wpais_rate_limit_window_seconds" id="wpais_rate_limit_window_seconds" value="%s" /> ',
            esc_attr((string) get_option(self::OPTION_RATE_LIMIT_WINDOW, self::DEFAULT_RATE_LIMIT_WINDOW)),
        );
        esc_html_e('Sekunden, pro Konversation bzw. IP-Adresse.', 'wp-ai-suite');
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="wpais_retention_days">' . esc_html__('Aufbewahrungsfrist (Tage)', 'wp-ai-suite') . '</label></th><td>';
        printf(
            '<input type="number" min="0" step="1" class="small-text" name="wpais_retention_days" id="wpais_retention_days" value="%s" />',
            esc_attr((string) get_option(RetentionCleanup::OPTION_RETENTION_DAYS, RetentionCleanup::DEFAULT_RETENTION_DAYS)),
        );
        echo '<p class="description">' . esc_html__('Konversationen ohne neue Nachricht seit dieser Anzahl Tage werden taeglich per Cron geloescht (inkl. aller Nachrichten). 0 = Aufbewahrungsfrist deaktiviert (nichts wird automatisch geloescht).', 'wp-ai-suite') . '</p>';
        echo '</td></tr>';
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

            $modelFieldName = 'wpais_default_model_' . $providerKey;
            if (isset($_POST[$modelFieldName])) {
                update_option(self::defaultModelOptionName($providerKey), sanitize_text_field(wp_unslash($_POST[$modelFieldName])));
            }
        }

        if (isset($_POST['wpais_custom_base_url'])) {
            update_option(self::OPTION_CUSTOM_BASE_URL, esc_url_raw(wp_unslash($_POST['wpais_custom_base_url'])));
        }

        if (isset($_POST['wpais_custom_label'])) {
            update_option(self::OPTION_CUSTOM_LABEL, sanitize_text_field(wp_unslash($_POST['wpais_custom_label'])));
        }

        if (isset($_POST['wpais_rate_limit_max'])) {
            update_option(self::OPTION_RATE_LIMIT_MAX, max(1, (int) $_POST['wpais_rate_limit_max']));
        }

        if (isset($_POST['wpais_rate_limit_window_seconds'])) {
            update_option(self::OPTION_RATE_LIMIT_WINDOW, max(1, (int) $_POST['wpais_rate_limit_window_seconds']));
        }

        if (isset($_POST['wpais_retention_days'])) {
            // 0 ist gueltig (= Retention deaktiviert, siehe RetentionCleanup::run()), negative
            // Werte ergeben fachlich keinen Sinn.
            update_option(RetentionCleanup::OPTION_RETENTION_DAYS, max(0, (int) $_POST['wpais_retention_days']));
        }

        $redirectTarget = wp_get_referer();
        if ($redirectTarget === false) {
            $redirectTarget = admin_url('admin.php?page=wpais-settings');
        }

        wp_safe_redirect(add_query_arg('wpais_saved', '1', $redirectTarget));
        exit;
    }
}
