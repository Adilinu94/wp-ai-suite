<?php

declare(strict_types=1);

namespace WPAiSuite\Rest\Controllers;

use WPAiSuite\AiCore\Provider\ActiveProviderResolver;
use WPAiSuite\AiCore\Provider\NoActiveProviderException;
use WPAiSuite\Knowledge\Chunking\ChunkerInterface;
use WPAiSuite\Knowledge\DocumentIngestionService;
use WPAiSuite\Knowledge\DocumentRepositoryInterface;
use WPAiSuite\Knowledge\Embedding\EmbeddingProviderResolver;
use WPAiSuite\Knowledge\Embedding\EmbeddingService;
use WPAiSuite\Knowledge\Ingestion\FaqEntry;
use WPAiSuite\Knowledge\Ingestion\FaqSource;
use WPAiSuite\Knowledge\Ingestion\KnowledgeSourceInterface;
use WPAiSuite\Knowledge\Ingestion\PdfFileReference;
use WPAiSuite\Knowledge\Ingestion\PdfSource;
use WPAiSuite\Knowledge\Ingestion\PdfTextExtractorInterface;
use WPAiSuite\Knowledge\Ingestion\WordPressContentSource;
use WPAiSuite\Knowledge\VectorStore\VectorStoreInterface;

/**
 * POST /wpais/v1/documents — Bauplan Abschnitt 12 ("Wissensbasis verwalten", Auth: manage_options).
 * M4-DoD (Abschnitt 15) verlangte nur "Ingestion aus WP-Content funktioniert end-to-end", nicht die
 * volle Wissensbasis-Verwaltung (Liste mit Status, Re-Index-Button pro Dokument) — die ist laut
 * Abschnitt 11/15 M10 ("Admin-Dashboard | Wissensbasis-UI"). Dieser Endpunkt bleibt bewusst nur der
 * Ausloeser: source_type (+ quelltyp-spezifische Parameter) rein, IngestionSummary raus. GET
 * (Liste)/DELETE folgen mit M10.
 *
 * M6 ("PDF/FAQ-Ingestion") erweitert resolveSource() um "pdf" und "faq"/"custom_text" — die
 * eigentliche WP-Kopplung (Anhang-ID -> Dateipfad via get_attached_file(), Titel via
 * get_the_title()) passiert bewusst HIER in der Adapter-Schicht (Abschnitt 1), nicht in PdfSource
 * selbst, damit PdfSource/FaqSource WP-Bootstrap-frei unit-testbar bleiben (siehe deren Docblocks).
 * "Upload" (M6-DoD) bedeutet fuer PDF konkret: die Datei kommt bereits als WP-Mediathek-Anhang rein
 * (Standard-WP-Weg, z.B. POST /wp-json/wp/v2/media oder der klassische Admin-Uploader) — dieser
 * Endpunkt nimmt KEINEN rohen Datei-Upload selbst entgegen (kein multipart-Handling hier), sondern
 * nur die schon vorhandene Anhang-ID. Zwei Gruende: (1) WP bringt einen sicheren, getesteten
 * Upload-Mechanismus (Dateityp-Validierung, Speicherort) schon mit, den man nicht duplizieren muss;
 * (2) eine REST-Route, die einen rohen Dateipfad als Parameter entgegennimmt, waere ein Path-
 * Traversal-Risiko (Abschnitt 9) — Anhang-IDs vermeiden das, weil get_attached_file() nur Pfade
 * liefert, die WP selbst beim Upload angelegt hat.
 *
 * Embedding-Provider = der ganz normale aktive Provider (ActiveProviderResolver, wie in
 * ChatController) — Abschnitt 7 sagt ausdruecklich "über den aktiven Provider", kein separates
 * "Embedding-Provider"-Setting. Ist der aktive Provider Anthropic (kein embed()-Support), schlaegt
 * die Ingestion mit einer klaren Fehlermeldung fehl (UnsupportedCapabilityException, landet als
 * Eintrag in IngestionSummary::$errors) statt stillschweigend falsche Daten zu erzeugen.
 *
 * Laeuft SYNCHRON innerhalb des Requests — Abschnitt 13s wpais_ingest_document/
 * wpais_rescan_documents (Action Scheduler, Composer-Dependency) sind bewusst noch nicht
 * verdrahtet, siehe FORTSETZUNG.md. Fuer eine Handvoll Seiten/Beitraege/PDFs/FAQ-Eintraege
 * unproblematisch, bei grossen Wissensbasen spaeter durch einen Background-Job zu ersetzen, ohne
 * dass sich an DocumentIngestionService selbst etwas aendern muss.
 */
final class DocumentsController
{
    public function __construct(
        private readonly DocumentRepositoryInterface $documents,
        private readonly ChunkerInterface $chunker,
        private readonly VectorStoreInterface $vectorStore,
        private readonly ActiveProviderResolver $providerResolver,
        private readonly PdfTextExtractorInterface $pdfExtractor,
        private readonly EmbeddingProviderResolver $embeddingProviderResolver,
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
                    // M6: nur fuer source_type=pdf ausgewertet (WP-Mediathek-Anhang-IDs).
                    'attachment_ids' => ['required' => false, 'type' => 'array'],
                    // M6: nur fuer source_type=faq|custom_text ausgewertet, siehe resolveFaqSource().
                    'entries' => ['required' => false, 'type' => 'array'],
                ],
            ]);
        });
    }

    public function ingest(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $sourceType = (string) $request->get_param('source_type');
        $source = $this->resolveSource($request, $sourceType);

        if ($source instanceof \WP_Error) {
            return $source;
        }

        if ($source === null) {
            return new \WP_Error(
                'wpais_unsupported_source',
                sprintf(
                    /* translators: %s: requested source_type, e.g. "invalid" */
                    __('Quelltyp "%s" wird nicht unterstuetzt (aktuell: wp_content, pdf, faq, custom_text).', 'wp-ai-suite'),
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

        // Umbauplan Post-MVP Punkt 1: siehe EmbeddingProviderResolver-Docblock.
        $embeddingProvider = $this->embeddingProviderResolver->resolve() ?? $provider;

        $ingestionService = new DocumentIngestionService(
            $this->documents,
            $this->chunker,
            $this->vectorStore,
            new EmbeddingService($embeddingProvider),
        );

        $summary = $ingestionService->ingest($source);

        return new \WP_REST_Response([
            'processed' => $summary->processed,
            'skipped_unchanged' => $summary->skippedUnchanged,
            'failed' => $summary->failed,
            'errors' => $summary->errors,
        ]);
    }

    /**
     * null = source_type unbekannt. WP_Error = source_type bekannt, aber Pflichtparameter fehlen/
     * sind ungueltig (z.B. leeres attachment_ids-Array). Sonst eine einsatzbereite Quelle.
     */
    private function resolveSource(\WP_REST_Request $request, string $sourceType): KnowledgeSourceInterface|\WP_Error|null
    {
        return match ($sourceType) {
            'wp_content' => new WordPressContentSource(),
            'pdf' => $this->resolvePdfSource($request),
            'faq', 'custom_text' => $this->resolveFaqSource($request, $sourceType),
            default => null,
        };
    }

    private function resolvePdfSource(\WP_REST_Request $request): PdfSource|\WP_Error
    {
        $attachmentIds = $request->get_param('attachment_ids');

        if (!is_array($attachmentIds) || $attachmentIds === []) {
            return new \WP_Error(
                'wpais_missing_attachment_ids',
                __('source_type=pdf braucht ein nicht-leeres Array "attachment_ids" (WP-Mediathek-Anhang-IDs).', 'wp-ai-suite'),
                ['status' => 400],
            );
        }

        $files = [];

        foreach ($attachmentIds as $attachmentId) {
            $attachmentId = (int) $attachmentId;
            $filePath = get_attached_file($attachmentId);
            // Kein Sonderfall fuer "Anhang nicht gefunden": ein leerer Pfad laeuft unveraendert in
            // PdfSource/SmalotPdfTextExtractor, die is_readable('') sowieso ablehnen und daraus
            // ganz normal ein RawDocument mit $extractionError bauen — ein einheitlicher
            // Fehlerpfad statt zwei getrennten (siehe SmalotPdfTextExtractor).
            $filePath = $filePath !== false ? $filePath : '';

            $title = get_the_title($attachmentId);
            $title = $title !== '' ? $title : sprintf('PDF #%d', $attachmentId);

            $files[] = new PdfFileReference((string) $attachmentId, $title, $filePath);
        }

        return new PdfSource($files, $this->pdfExtractor);
    }

    private function resolveFaqSource(\WP_REST_Request $request, string $sourceType): FaqSource|\WP_Error
    {
        $rawEntries = $request->get_param('entries');

        if (!is_array($rawEntries) || $rawEntries === []) {
            return new \WP_Error(
                'wpais_missing_entries',
                sprintf(
                    /* translators: %s: source_type, "faq" or "custom_text" */
                    __('source_type=%s braucht ein nicht-leeres Array "entries".', 'wp-ai-suite'),
                    $sourceType,
                ),
                ['status' => 400],
            );
        }

        $entries = [];

        foreach ($rawEntries as $index => $rawEntry) {
            if (!is_array($rawEntry) || !isset($rawEntry['ref']) || (string) $rawEntry['ref'] === '') {
                return new \WP_Error(
                    'wpais_invalid_entry',
                    sprintf(
                        /* translators: %d: zero-based index of the malformed entry in the request */
                        __('entries[%d] fehlt das Pflichtfeld "ref".', 'wp-ai-suite'),
                        $index,
                    ),
                    ['status' => 400],
                );
            }

            // faq: {ref, question, answer} — custom_text: {ref, title, text}. Beide auf dieselben
            // FaqEntry-Felder (title/content) gemappt, siehe FaqEntry-Docblock.
            [$title, $content] = $sourceType === 'faq'
                ? [(string) ($rawEntry['question'] ?? ''), (string) ($rawEntry['answer'] ?? '')]
                : [(string) ($rawEntry['title'] ?? ''), (string) ($rawEntry['text'] ?? '')];

            $entries[] = new FaqEntry((string) $rawEntry['ref'], $title, $content);
        }

        return new FaqSource($sourceType, $entries);
    }
}
