<?php

declare(strict_types=1);

namespace WPAiSuite\Rest\Controllers;

use WPAiSuite\AiCore\Provider\ActiveProviderResolver;
use WPAiSuite\AiCore\Provider\NoActiveProviderException;
use WPAiSuite\Knowledge\Chunking\ChunkerInterface;
use WPAiSuite\Knowledge\DocumentIngestionService;
use WPAiSuite\Knowledge\DocumentRepositoryInterface;
use WPAiSuite\Knowledge\Embedding\EmbeddingService;
use WPAiSuite\Knowledge\Ingestion\WordPressContentSource;
use WPAiSuite\Knowledge\VectorStore\VectorStoreInterface;

/**
 * POST /wpais/v1/documents — Bauplan Abschnitt 12 ("Wissensbasis verwalten", Auth: manage_options).
 * M4-DoD (Abschnitt 15) verlangt nur "Ingestion aus WP-Content funktioniert end-to-end", nicht die
 * volle Wissensbasis-Verwaltung (Liste mit Status, Re-Index-Button pro Dokument) — die ist laut
 * Abschnitt 11/15 M10 ("Admin-Dashboard | Wissensbasis-UI"). Dieser Endpunkt ist bewusst nur der
 * Ausloeser: source_type=wp_content rein, IngestionSummary raus. GET (Liste)/DELETE folgen mit M10.
 *
 * Embedding-Provider = der ganz normale aktive Provider (ActiveProviderResolver, wie in
 * ChatController) — Abschnitt 7 sagt ausdruecklich "über den aktiven Provider", kein separates
 * "Embedding-Provider"-Setting. Ist der aktive Provider Anthropic (kein embed()-Support), schlaegt
 * die Ingestion mit einer klaren Fehlermeldung fehl (UnsupportedCapabilityException, landet als
 * Eintrag in IngestionSummary::$errors) statt stillschweigend falsche Daten zu erzeugen.
 *
 * Laeuft SYNCHRON innerhalb des Requests — Abschnitt 13s wpais_ingest_document/
 * wpais_rescan_documents (Action Scheduler, Composer-Dependency) sind bewusst noch nicht
 * verdrahtet, siehe FORTSETZUNG.md. Fuer eine Handvoll Seiten/Beitraege unproblematisch, bei
 * grossen Wissensbasen spaeter durch einen Background-Job zu ersetzen, ohne dass sich an
 * DocumentIngestionService selbst etwas aendern muss.
 */
final class DocumentsController
{
    public function __construct(
        private readonly DocumentRepositoryInterface $documents,
        private readonly ChunkerInterface $chunker,
        private readonly VectorStoreInterface $vectorStore,
        private readonly ActiveProviderResolver $providerResolver,
    ) {
    }

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            register_rest_route('wpais/v1', '/documents', [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'ingest'],
                'permission_callback' => static fn (): bool => current_user_can('manage_options'),
                'args' => [
                    'source_type' => ['required' => true, 'type' => 'string'],
                ],
            ]);
        });
    }

    public function ingest(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $sourceType = (string) $request->get_param('source_type');
        $source = $this->resolveSource($sourceType);

        if ($source === null) {
            return new \WP_Error(
                'wpais_unsupported_source',
                sprintf(
                    /* translators: %s: requested source_type, e.g. "pdf" */
                    __('Quelltyp "%s" wird noch nicht unterstuetzt (aktuell nur "wp_content").', 'wp-ai-suite'),
                    $sourceType,
                ),
                ['status' => 400],
            );
        }

        try {
            [$provider, ] = $this->providerResolver->resolve();
        } catch (NoActiveProviderException $e) {
            return new \WP_Error('wpais_no_active_provider', $e->getMessage(), ['status' => 503]);
        }

        $ingestionService = new DocumentIngestionService(
            $this->documents,
            $this->chunker,
            $this->vectorStore,
            new EmbeddingService($provider),
        );

        $summary = $ingestionService->ingest($source);

        return new \WP_REST_Response([
            'processed' => $summary->processed,
            'skipped_unchanged' => $summary->skippedUnchanged,
            'failed' => $summary->failed,
            'errors' => $summary->errors,
        ]);
    }

    private function resolveSource(string $sourceType): ?WordPressContentSource
    {
        return $sourceType === 'wp_content' ? new WordPressContentSource() : null;
    }
}
