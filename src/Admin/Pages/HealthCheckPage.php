<?php

declare(strict_types=1);

namespace WPAiSuite\Admin\Pages;

use WPAiSuite\AiCore\Provider\ActiveProviderResolver;
use WPAiSuite\AiCore\Provider\NoActiveProviderException;
use WPAiSuite\Jobs\ActionSchedulerIngestionDispatcher;
use WPAiSuite\Knowledge\Embedding\EmbeddingProviderResolver;
use WPAiSuite\Security\ApiKeyVault;

/**
 * Verbesserung Punkt 7: Sammelstelle fuer "ist alles technisch in Ordnung", ohne dafuer erst
 * Einstellungen/Wissensbasis/Nutzungsprotokoll einzeln durchklicken zu muessen. Bewusst KEIN
 * echter (kostenpflichtiger) Provider-Live-Aufruf hier — das macht bereits der
 * Verbindungstest-Button auf der Einstellungsseite (ConnectionTestController, Umbauplan Punkt 4);
 * diese Seite prueft nur, ob ueberhaupt etwas konfiguriert ist, nicht ob der Key tatsaechlich
 * funktioniert, damit ein einfaches Seitenladen keine API-Kosten verursacht.
 */
final class HealthCheckPage
{
    private const CAPABILITY = 'manage_options';
    private const SLUG = 'wpais-health-check';

    public function __construct(
        private readonly ActiveProviderResolver $providerResolver,
        private readonly EmbeddingProviderResolver $embeddingProviderResolver,
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', function (): void {
            add_submenu_page(
                'wpais-settings',
                __('Diagnose', 'wp-ai-suite'),
                __('Diagnose', 'wp-ai-suite'),
                self::CAPABILITY,
                self::SLUG,
                [$this, 'renderPage'],
            );
        });
    }

    public function renderPage(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Keine Berechtigung.', 'wp-ai-suite'));
        }

        echo '<div class="wrap"><h1>' . esc_html__('Diagnose', 'wp-ai-suite') . '</h1>';
        echo '<p>' . esc_html__('Technischer Zustand auf einen Blick. Ruft keinen KI-Provider live auf (verursacht keine Kosten) — fuer einen echten Verbindungstest siehe Einstellungen.', 'wp-ai-suite') . '</p>';

        echo '<table class="widefat striped" style="max-width:800px;"><tbody>';
        $this->renderRow(__('Chat-Provider', 'wp-ai-suite'), $this->checkChatProvider());
        $this->renderRow(__('Embedding-Provider', 'wp-ai-suite'), $this->checkEmbeddingProvider());
        $this->renderRow(__('Verschlüsselungs-Schlüssel', 'wp-ai-suite'), $this->checkEncryptionKey());
        $this->renderRow(__('PHP-Erweiterungen', 'wp-ai-suite'), $this->checkExtensions());
        $this->renderRow(__('Datenbank-Tabellen', 'wp-ai-suite'), $this->checkTables());
        $this->renderRow(__('Action Scheduler', 'wp-ai-suite'), $this->checkActionScheduler());
        $this->renderRow(__('Wissensbasis-Größe', 'wp-ai-suite'), $this->checkChunkVolume());
        echo '</tbody></table></div>';
    }

    /** @return array{ok: bool, message: string} */
    private function checkChatProvider(): array
    {
        try {
            [$provider, $model] = $this->providerResolver->resolve();

            return ['ok' => true, 'message' => sprintf(
                /* translators: 1: Provider-Label, 2: Modellname */
                __('Konfiguriert: %1$s (%2$s)', 'wp-ai-suite'),
                $provider->getLabel(),
                $model,
            )];
        } catch (NoActiveProviderException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** @return array{ok: bool, message: string} */
    private function checkEmbeddingProvider(): array
    {
        $provider = $this->embeddingProviderResolver->resolve();

        if ($provider === null) {
            return ['ok' => true, 'message' => __('Kein eigener Embedding-Provider konfiguriert — nutzt den Chat-Provider als Fallback.', 'wp-ai-suite')];
        }

        return ['ok' => true, 'message' => sprintf(__('Eigener Provider konfiguriert: %s', 'wp-ai-suite'), $provider->getLabel())];
    }

    /** @return array{ok: bool, message: string} */
    private function checkEncryptionKey(): array
    {
        return defined(ApiKeyVault::WP_CONFIG_CONSTANT)
            ? ['ok' => true, 'message' => sprintf(__('%s ist in wp-config.php gesetzt.', 'wp-ai-suite'), ApiKeyVault::WP_CONFIG_CONSTANT)]
            : ['ok' => false, 'message' => sprintf(__('%s fehlt in wp-config.php — API-Keys koennen nicht gespeichert werden.', 'wp-ai-suite'), ApiKeyVault::WP_CONFIG_CONSTANT)];
    }

    /** @return array{ok: bool, message: string} */
    private function checkExtensions(): array
    {
        $missing = array_values(array_filter(
            ['sodium', 'curl', 'json'],
            static fn (string $ext): bool => !extension_loaded($ext),
        ));

        return $missing === []
            ? ['ok' => true, 'message' => __('sodium, curl, json sind geladen.', 'wp-ai-suite')]
            : ['ok' => false, 'message' => sprintf(__('Fehlende PHP-Erweiterung(en): %s', 'wp-ai-suite'), implode(', ', $missing))];
    }

    /** @return array{ok: bool, message: string} */
    private function checkTables(): array
    {
        global $wpdb;

        $prefix = $wpdb->prefix . 'wpais_';
        $expected = ['conversations', 'messages', 'documents', 'chunks', 'api_keys', 'usage_logs'];
        $missing = [];

        foreach ($expected as $table) {
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $prefix . $table));
            if ($exists === null) {
                $missing[] = $prefix . $table;
            }
        }

        return $missing === []
            ? ['ok' => true, 'message' => sprintf(__('Alle %d Tabellen vorhanden.', 'wp-ai-suite'), count($expected))]
            : ['ok' => false, 'message' => sprintf(__('Fehlende Tabelle(n): %s', 'wp-ai-suite'), implode(', ', $missing))];
    }

    /** @return array{ok: bool, message: string} */
    private function checkActionScheduler(): array
    {
        if (!ActionSchedulerIngestionDispatcher::isAvailable()) {
            return ['ok' => true, 'message' => __('Nicht installiert — grosse Ingestion-Batches laufen dann synchron (siehe Umbauplan Post-MVP Punkt 3).', 'wp-ai-suite')];
        }

        if (!function_exists('as_get_scheduled_actions')) {
            return ['ok' => true, 'message' => __('Verfuegbar.', 'wp-ai-suite')];
        }

        $pending = count(as_get_scheduled_actions([
            'hook' => ActionSchedulerIngestionDispatcher::HOOK,
            'group' => ActionSchedulerIngestionDispatcher::GROUP,
            'status' => 'pending',
            'per_page' => -1,
        ], 'ids'));

        return ['ok' => true, 'message' => sprintf(
            /* translators: %d: Anzahl wartender Ingestion-Jobs */
            __('Verfügbar, %d Dokument(e) aktuell in der Warteschlange.', 'wp-ai-suite'),
            $pending,
        )];
    }

    /**
     * Verbesserung Punkt 8: WpdbJsonVectorStore scannt bei JEDER RAG-Anfrage ALLE verarbeiteten
     * Chunks (siehe dessen Docblock, kein echter ANN-Index) — dieser Wert ist das
     * Fruehwarnsignal dafuer, ohne live einen Provider aufzurufen. Schwellwert 2000 ist eine
     * grobe Faustregel (spuerbare Verlangsamung haengt auch von der Server-Hardware ab), keine
     * gemessene harte Grenze.
     *
     * @return array{ok: bool, message: string}
     */
    private function checkChunkVolume(): array
    {
        global $wpdb;

        $chunksTable = $wpdb->prefix . 'wpais_chunks';
        $documentsTable = $wpdb->prefix . 'wpais_documents';
        $count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$chunksTable} c INNER JOIN {$documentsTable} d ON d.id = c.document_id WHERE d.status = 'processed'",
        );

        $threshold = 2000;

        if ($count <= $threshold) {
            return ['ok' => true, 'message' => sprintf(__('%d verarbeitete Chunks.', 'wp-ai-suite'), $count)];
        }

        return ['ok' => false, 'message' => sprintf(
            /* translators: %d: Anzahl verarbeiteter Chunks */
            __('%d verarbeitete Chunks — RAG-Anfragen koennen spuerbar langsamer werden (siehe WpdbJsonVectorStore-Docblock zur Skalierungsgrenze).', 'wp-ai-suite'),
            $count,
        )];
    }

    /** @param array{ok: bool, message: string} $result */
    private function renderRow(string $label, array $result): void
    {
        $icon = $result['ok'] ? '✅' : '⚠️';
        echo '<tr><th scope="row" style="width:220px;">' . esc_html($label) . '</th><td>' . $icon . ' ' . esc_html($result['message']) . '</td></tr>';
    }
}
