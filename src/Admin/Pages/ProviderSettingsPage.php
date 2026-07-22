<?php

declare(strict_types=1);

namespace WPAiSuite\Admin\Pages;

use WPAiSuite\AiCore\Provider\ProviderFactory;
use WPAiSuite\Knowledge\Embedding\EmbeddingProviderResolver;
use WPAiSuite\Security\ApiKeyRepositoryInterface;
use WPAiSuite\Security\RetentionCleanup;

/**
 * Phase-1-Umfang laut M1-DoD (Bauplan Abschnitt 15): Provider-Auswahl + API-Key-Eingabe, schreibt
 * ueber ApiKeyRepositoryInterface (welches wiederum ApiKeyVault fuer die Verschluesselung nutzt).
 *
 * Um M2 (ChatRequest braucht ein konkretes $model) nachgereicht: Standard-Modell pro Provider
 * (Abschnitt 11 nennt das bereits als Teil von "Settings", in M1 aber zunaechst ausgelassen).
 *
 * Der System-Prompt-Editor aus Abschnitt 11 (M10) ist seitdem Teil dieser Seite (eigener
 * Abschnitt, `renderSystemPromptField()`/`wpais_system_prompt`) — bis M9 bewusst
 * zurueckgestellt (Regel 2, "Architektur nicht eigenmaechtig erweitern"), bis dahin nutzte
 * `SystemPromptBuilder` nur den Default-Text.
 *
 * Umbauplan Post-MVP Punkt 1 ergaenzt einen eigenen "Embeddings"-Abschnitt
 * (`renderEmbeddingProviderFields()`) fuer einen vom Chat-Provider unabhaengigen
 * Embedding-Provider — die Optionsnamen dafuer gehoeren EmbeddingProviderResolver (analog dazu,
 * wie OPTION_RATE_LIMIT_* oben RateLimiter gehoeren, nicht dieser Klasse).
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

        // Umbauplan Post-MVP Punkt 1, Risiko-Hinweis: ein Embedding-Provider-Wechsel macht
        // bestehende Chunks nicht automatisch inkompatibel (Cosine-Similarity funktioniert
        // weiter), liefert mit dem neuen Provider aber erst nach Neu-Indexierung wieder
        // konsistent gute Treffer — deshalb nur ein Hinweis, kein Blocker.
        if (isset($_GET['wpais_embedding_changed'])) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('Embedding-Provider geaendert — bestehende Wissensbasis-Eintraege wurden mit dem vorherigen Provider erstellt. Fuer beste Ergebnisse: Dokumente mit externer Quelle (PDF, WordPress-Inhalte) auf der Wissensbasis-Seite neu indexieren; FAQ/Custom-Text-Eintraege erneut einreichen.', 'wp-ai-suite') . '</p></div>';
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
        echo '<h2>' . esc_html__('Embeddings', 'wp-ai-suite') . '</h2>';
        echo '<table class="form-table"><tbody>';
        $this->renderEmbeddingProviderFields();
        echo '</tbody></table>';
        echo '<h2>' . esc_html__('System-Prompt', 'wp-ai-suite') . '</h2>';
        echo '<table class="form-table"><tbody>';
        $this->renderSystemPromptField();
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

    /**
     * Umbauplan Post-MVP Punkt 1: optionaler, vom Chat-Provider unabhaengiger Embedding-Provider.
     * "OpenAI"/"Anthropic" hier nutzen bewusst denselben gespeicherten Key wie oben (kein
     * zweites Key-Feld noetig — derselbe Account, andere Verwendung). Nur der eigenstaendige
     * OpenAI-kompatible Fall (z.B. ein separates lokales Ollama nur fuer Embeddings) braucht
     * eigene Key-/Basis-URL-/Modell-Felder, deshalb der eigene Provider-Key "custom_embed"
     * statt den bestehenden "custom"-Slot der Chat-Seite mitzubenutzen (siehe
     * EmbeddingProviderResolver::CUSTOM_KEY-Docblock).
     */
    private function renderEmbeddingProviderFields(): void
    {
        $current = (string) get_option(EmbeddingProviderResolver::OPTION_PROVIDER, '');
        $options = [
            '' => __('Wie Chat-Provider (Standard)', 'wp-ai-suite'),
            'openai' => __('OpenAI (nutzt den OpenAI-Key oben)', 'wp-ai-suite'),
            'anthropic' => __('Anthropic (nutzt den Anthropic-Key oben)', 'wp-ai-suite'),
            EmbeddingProviderResolver::CUSTOM_KEY => __('OpenAI-kompatibel, separat (z.B. lokales Ollama)', 'wp-ai-suite'),
        ];

        echo '<tr><th scope="row"><label for="wpais_embedding_provider">' . esc_html__('Embedding-Provider', 'wp-ai-suite') . '</label></th><td>';
        echo '<select name="wpais_embedding_provider" id="wpais_embedding_provider">';
        foreach ($options as $key => $label) {
            printf(
                '<option value="%s"%s>%s</option>',
                esc_attr($key),
                selected($current, $key, false),
                esc_html($label),
            );
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('Nur noetig, wenn der aktive Chat-Provider keine brauchbare Embeddings-API hat (z.B. DeepSeek) — ohne Auswahl faellt die Wissensbasis automatisch auf einen einfachen lokalen Vergleich zurueck (funktioniert, aber schwaecher als ein echtes Embedding-Modell).', 'wp-ai-suite') . '</p>';
        echo '</td></tr>';

        $this->renderKeyField(EmbeddingProviderResolver::CUSTOM_KEY, __('Separat — API-Key', 'wp-ai-suite'));

        echo '<tr><th scope="row"><label for="wpais_embedding_label">' . esc_html__('Separat — Name', 'wp-ai-suite') . '</label></th><td>';
        printf(
            '<input type="text" class="regular-text" name="wpais_embedding_label" id="wpais_embedding_label" value="%s" placeholder="z.B. Lokales Ollama" />',
            esc_attr((string) get_option(EmbeddingProviderResolver::OPTION_LABEL, '')),
        );
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="wpais_embedding_base_url">' . esc_html__('Separat — Basis-URL', 'wp-ai-suite') . '</label></th><td>';
        printf(
            '<input type="url" class="regular-text" name="wpais_embedding_base_url" id="wpais_embedding_base_url" value="%s" placeholder="http://localhost:11434/v1" />',
            esc_attr((string) get_option(EmbeddingProviderResolver::OPTION_BASE_URL, '')),
        );
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="wpais_embedding_model">' . esc_html__('Separat — Embedding-Modell', 'wp-ai-suite') . '</label></th><td>';
        printf(
            '<input type="text" class="regular-text" name="wpais_embedding_model" id="wpais_embedding_model" value="%s" placeholder="z.B. nomic-embed-text" />',
            esc_attr((string) get_option(EmbeddingProviderResolver::OPTION_MODEL, '')),
        );
        echo '<p class="description">' . esc_html__('Nur fuer "OpenAI-kompatibel, separat" relevant. Leer = text-embedding-3-small (OpenAI-Modellname — passt nicht zu jedem Anbieter, bei lokalen Runtimes den dortigen Modellnamen eintragen).', 'wp-ai-suite') . '</p>';
        echo '</td></tr>';
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

    /** M10: der in M1 bewusst zurueckgestellte System-Prompt-Editor (siehe Klassen-Docblock). */
    private function renderSystemPromptField(): void
    {
        $value = (string) get_option('wpais_system_prompt', '');

        echo '<tr><th scope="row"><label for="wpais_system_prompt">' . esc_html__('System-Prompt', 'wp-ai-suite') . '</label></th><td>';
        echo '<textarea class="large-text code" rows="6" name="wpais_system_prompt" id="wpais_system_prompt">' . esc_textarea($value) . '</textarea>';
        echo '<p class="description">' . esc_html__('Leer lassen fuer den eingebauten Standard-System-Prompt.', 'wp-ai-suite') . '</p>';
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

        // Umbauplan Post-MVP Punkt 1: eigener Embedding-Provider, siehe
        // renderEmbeddingProviderFields()-Docblock. $embeddingProviderChanged wird unten fuer
        // den Redirect gebraucht (Re-Index-Hinweis), deshalb der Vorher-Wert VOR dem Speichern.
        $previousEmbeddingProvider = (string) get_option(EmbeddingProviderResolver::OPTION_PROVIDER, '');

        $embeddingProvider = isset($_POST['wpais_embedding_provider'])
            ? sanitize_key(wp_unslash($_POST['wpais_embedding_provider']))
            : '';
        update_option(EmbeddingProviderResolver::OPTION_PROVIDER, $embeddingProvider);

        $embeddingKeyField = 'wpais_key_' . EmbeddingProviderResolver::CUSTOM_KEY;
        if (!empty($_POST[$embeddingKeyField])) {
            $this->apiKeys->store(
                EmbeddingProviderResolver::CUSTOM_KEY,
                sanitize_text_field(wp_unslash($_POST[$embeddingKeyField])),
            );
        }

        if (isset($_POST['wpais_embedding_label'])) {
            update_option(EmbeddingProviderResolver::OPTION_LABEL, sanitize_text_field(wp_unslash($_POST['wpais_embedding_label'])));
        }

        if (isset($_POST['wpais_embedding_base_url'])) {
            update_option(EmbeddingProviderResolver::OPTION_BASE_URL, esc_url_raw(wp_unslash($_POST['wpais_embedding_base_url'])));
        }

        if (isset($_POST['wpais_embedding_model'])) {
            update_option(EmbeddingProviderResolver::OPTION_MODEL, sanitize_text_field(wp_unslash($_POST['wpais_embedding_model'])));
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

        if (isset($_POST['wpais_system_prompt'])) {
            // sanitize_textarea_field statt sanitize_text_field: Zeilenumbrueche im
            // System-Prompt sind gewollt (Absaetze, Listen), sollen nicht auf eine Zeile
            // zusammenfallen.
            update_option('wpais_system_prompt', sanitize_textarea_field(wp_unslash($_POST['wpais_system_prompt'])));
        }

        $redirectTarget = wp_get_referer();
        if ($redirectTarget === false) {
            $redirectTarget = admin_url('admin.php?page=wpais-settings');
        }

        $redirectArgs = ['wpais_saved' => '1'];
        if ($embeddingProvider !== $previousEmbeddingProvider) {
            $redirectArgs['wpais_embedding_changed'] = '1';
        }

        wp_safe_redirect(add_query_arg($redirectArgs, $redirectTarget));
        exit;
    }
}
