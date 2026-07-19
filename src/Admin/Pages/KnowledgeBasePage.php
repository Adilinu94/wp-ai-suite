<?php

declare(strict_types=1);

namespace WPAiSuite\Admin\Pages;

use WPAiSuite\AiCore\Provider\ActiveProviderResolver;
use WPAiSuite\AiCore\Provider\NoActiveProviderException;
use WPAiSuite\Knowledge\Chunking\ChunkerInterface;
use WPAiSuite\Knowledge\DocumentIngestionService;
use WPAiSuite\Knowledge\DocumentRepositoryInterface;
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
 * Bauplan Abschnitt 11 (M10): "Wissensbasis: Liste aller wpais_documents mit Status
 * (pending/processed/failed), Upload für PDF, manuelle FAQ-Einträge, 'Neu indexieren'-Button pro
 * Dokument." M6 hat bewusst nur die REST-Mechanik gebaut (POST /wpais/v1/documents), keine
 * visuelle Verwaltung — genau die liefert diese Seite nach, indem sie dieselben
 * KnowledgeSourceInterface-Implementierungen/DocumentIngestionService direkt aufruft (kein
 * interner HTTP-Rundruf zur eigenen REST-Route noetig).
 *
 * "Neu indexieren" ist bewusst NUR fuer source_type=pdf/wp_content anklickbar, nicht fuer
 * faq/custom_text: wpais_documents speichert nur den Titel, nicht den vollen Inhalt (der liegt
 * gechunkt in wpais_chunks, aus dem er nicht verlustfrei rekonstruierbar ist) — pdf/wp_content
 * haben dagegen eine externe Quelle (Mediathek-Datei bzw. WP-Post), aus der sich der Inhalt
 * jederzeit erneut frisch lesen laesst. Eine FAQ/Custom-Text-Zeile aktualisiert man stattdessen,
 * indem man denselben Ref erneut ueber das Formular unten einreicht (upsert-Verhalten seit M6).
 * "Neu indexieren" bei wp_content synct dabei bewusst ALLE WP-Inhalte neu (WordPressContentSource
 * kennt keinen Einzelpost-Filter) — im UI-Label klar benannt, damit das nicht als "nur diese eine
 * Seite" missverstanden wird.
 */
final class KnowledgeBasePage
{
    private const CAPABILITY = 'manage_options';
    private const NONCE_ACTION = 'wpais_kb_action';
    private const SLUG = 'wpais-knowledge-base';

    public function __construct(
        private readonly DocumentRepositoryInterface $documents,
        private readonly ChunkerInterface $chunker,
        private readonly VectorStoreInterface $vectorStore,
        private readonly ActiveProviderResolver $providerResolver,
        private readonly PdfTextExtractorInterface $pdfExtractor,
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', function (): void {
            add_submenu_page(
                'wpais-settings',
                __('Wissensbasis', 'wp-ai-suite'),
                __('Wissensbasis', 'wp-ai-suite'),
                self::CAPABILITY,
                self::SLUG,
                [$this, 'renderPage'],
            );
        });

        add_action('admin_post_wpais_kb_upload_pdf', [$this, 'handleUploadPdf']);
        add_action('admin_post_wpais_kb_add_entry', [$this, 'handleAddEntry']);
        add_action('admin_post_wpais_kb_reindex', [$this, 'handleReindex']);
    }

    public function renderPage(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Keine Berechtigung.', 'wp-ai-suite'));
        }

        echo '<div class="wrap"><h1>' . esc_html__('Wissensbasis', 'wp-ai-suite') . '</h1>';
        $this->renderNotice();
        $this->renderDocumentsTable();
        $this->renderUploadPdfForm();
        $this->renderAddEntryForm();
        echo '</div>';
    }

    private function renderNotice(): void
    {
        $notice = isset($_GET['wpais_notice']) ? sanitize_text_field(wp_unslash((string) $_GET['wpais_notice'])) : '';

        if ($notice === '') {
            return;
        }

        $isError = isset($_GET['wpais_error']);
        printf(
            '<div class="notice %s is-dismissible"><p>%s</p></div>',
            $isError ? 'notice-error' : 'notice-success',
            esc_html($notice),
        );
    }

    private function renderDocumentsTable(): void
    {
        $documents = $this->documents->listAll();

        echo '<h2>' . esc_html__('Dokumente', 'wp-ai-suite') . '</h2>';

        if ($documents === []) {
            echo '<p>' . esc_html__('Noch keine Dokumente in der Wissensbasis.', 'wp-ai-suite') . '</p>';

            return;
        }

        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
        foreach ([
            __('Titel', 'wp-ai-suite'),
            __('Typ', 'wp-ai-suite'),
            __('Status', 'wp-ai-suite'),
            __('Zuletzt aktualisiert', 'wp-ai-suite'),
            __('Aktion', 'wp-ai-suite'),
        ] as $column) {
            echo '<th>' . esc_html($column) . '</th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($documents as $document) {
            echo '<tr>';
            echo '<td>' . esc_html($document->title) . '</td>';
            echo '<td>' . esc_html($document->sourceType) . '</td>';
            echo '<td>' . $this->renderStatusBadge($document->status);
            if ($document->status === 'failed' && $document->errorMessage !== null) {
                echo '<br><span class="description">' . esc_html($document->errorMessage) . '</span>';
            }
            echo '</td>';
            echo '<td>' . esc_html($document->updatedAt?->format('Y-m-d H:i') ?? '—') . '</td>';
            echo '<td>' . $this->renderReindexAction($document->id, $document->sourceType) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private function renderStatusBadge(string $status): string
    {
        $colors = ['pending' => '#996800', 'processing' => '#2271b1', 'processed' => '#008a20', 'failed' => '#d63638'];
        $color = $colors[$status] ?? '#555';

        return sprintf('<span style="color:%s;font-weight:600;">%s</span>', esc_attr($color), esc_html($status));
    }

    private function renderReindexAction(int $documentId, string $sourceType): string
    {
        if (!in_array($sourceType, ['wp_content', 'pdf'], true)) {
            return '—';
        }

        $url = wp_nonce_url(
            admin_url('admin-post.php?action=wpais_kb_reindex&document_id=' . $documentId),
            self::NONCE_ACTION,
        );
        $label = $sourceType === 'wp_content'
            ? __('Neu indexieren (alle WP-Inhalte)', 'wp-ai-suite')
            : __('Neu indexieren', 'wp-ai-suite');

        return sprintf('<a href="%s" class="button button-small">%s</a>', esc_url($url), esc_html($label));
    }

    private function renderUploadPdfForm(): void
    {
        echo '<h2>' . esc_html__('PDF hochladen', 'wp-ai-suite') . '</h2>';
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<input type="hidden" name="action" value="wpais_kb_upload_pdf" />';
        echo '<input type="file" name="pdf_file" accept="application/pdf" required /> ';
        submit_button(__('Hochladen und einlesen', 'wp-ai-suite'), 'secondary', 'submit', false);
        echo '</form>';
    }

    private function renderAddEntryForm(): void
    {
        echo '<h2>' . esc_html__('FAQ / Freitext hinzufügen', 'wp-ai-suite') . '</h2>';
        echo '<p class="description">' . esc_html__('Ein bereits verwendeter Schlüssel aktualisiert den bestehenden Eintrag, statt einen neuen anzulegen.', 'wp-ai-suite') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<input type="hidden" name="action" value="wpais_kb_add_entry" />';
        echo '<table class="form-table"><tbody>';

        echo '<tr><th scope="row"><label for="wpais_entry_type">' . esc_html__('Typ', 'wp-ai-suite') . '</label></th><td>';
        echo '<select name="entry_type" id="wpais_entry_type">';
        echo '<option value="faq">' . esc_html__('FAQ (Frage/Antwort)', 'wp-ai-suite') . '</option>';
        echo '<option value="custom_text">' . esc_html__('Freitext (Titel/Text)', 'wp-ai-suite') . '</option>';
        echo '</select></td></tr>';

        echo '<tr><th scope="row"><label for="wpais_entry_ref">' . esc_html__('Schlüssel (Ref)', 'wp-ai-suite') . '</label></th><td>';
        echo '<input type="text" class="regular-text" name="ref" id="wpais_entry_ref" required placeholder="z.B. versandkosten" /></td></tr>';

        echo '<tr><th scope="row"><label for="wpais_entry_title">' . esc_html__('Frage / Titel', 'wp-ai-suite') . '</label></th><td>';
        echo '<input type="text" class="regular-text" name="entry_title" id="wpais_entry_title" required /></td></tr>';

        echo '<tr><th scope="row"><label for="wpais_entry_content">' . esc_html__('Antwort / Text', 'wp-ai-suite') . '</label></th><td>';
        echo '<textarea class="large-text" rows="5" name="entry_content" id="wpais_entry_content" required></textarea></td></tr>';

        echo '</tbody></table>';
        submit_button(__('Speichern und einlesen', 'wp-ai-suite'));
        echo '</form>';
    }

    public function handleUploadPdf(): void
    {
        $this->assertRequestAllowed();

        if (!isset($_FILES['pdf_file']) || !is_array($_FILES['pdf_file']) || (int) $_FILES['pdf_file']['error'] !== UPLOAD_ERR_OK) {
            $this->redirectWithNotice(__('PDF-Upload fehlgeschlagen (keine Datei erhalten).', 'wp-ai-suite'), true);
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $attachmentId = media_handle_upload('pdf_file', 0);

        if (is_wp_error($attachmentId)) {
            $this->redirectWithNotice($attachmentId->get_error_message(), true);
        }

        $filePath = get_attached_file($attachmentId);
        $title = get_the_title($attachmentId) ?: sprintf('PDF #%d', $attachmentId);
        $source = new PdfSource(
            [new PdfFileReference((string) $attachmentId, $title, $filePath !== false ? $filePath : '')],
            $this->pdfExtractor,
        );

        $this->ingestAndRedirect($source, sprintf(__('PDF "%s" eingelesen.', 'wp-ai-suite'), $title));
    }

    public function handleAddEntry(): void
    {
        $this->assertRequestAllowed();

        $entryType = ($_POST['entry_type'] ?? '') === 'custom_text' ? 'custom_text' : 'faq';
        $ref = sanitize_text_field(wp_unslash((string) ($_POST['ref'] ?? '')));
        $title = sanitize_text_field(wp_unslash((string) ($_POST['entry_title'] ?? '')));
        $content = sanitize_textarea_field(wp_unslash((string) ($_POST['entry_content'] ?? '')));

        if ($ref === '' || $title === '' || $content === '') {
            $this->redirectWithNotice(__('Bitte Schlüssel, Frage/Titel und Antwort/Text ausfüllen.', 'wp-ai-suite'), true);
        }

        $source = new FaqSource($entryType, [new FaqEntry($ref, $title, $content)]);

        $this->ingestAndRedirect($source, sprintf(__('Eintrag "%s" gespeichert.', 'wp-ai-suite'), $title));
    }

    public function handleReindex(): void
    {
        $this->assertRequestAllowed();

        $documentId = (int) ($_GET['document_id'] ?? 0);
        $document = $this->documents->findById($documentId);

        if ($document === null) {
            $this->redirectWithNotice(__('Dokument nicht gefunden.', 'wp-ai-suite'), true);

            return;
        }

        $source = match ($document->sourceType) {
            'wp_content' => new WordPressContentSource(),
            'pdf' => new PdfSource(
                [new PdfFileReference(
                    (string) $document->sourceRef,
                    $document->title,
                    ($path = get_attached_file((int) $document->sourceRef)) !== false ? $path : '',
                )],
                $this->pdfExtractor,
            ),
            default => null,
        };

        if ($source === null) {
            $this->redirectWithNotice(__('Dieser Dokumenttyp kann hier nicht neu indexiert werden.', 'wp-ai-suite'), true);

            return;
        }

        $this->ingestAndRedirect($source, sprintf(__('"%s" neu indexiert.', 'wp-ai-suite'), $document->title));
    }

    private function ingestAndRedirect(KnowledgeSourceInterface $source, string $successMessage): void
    {
        try {
            [$provider, ] = $this->providerResolver->resolve();
        } catch (NoActiveProviderException $e) {
            $this->redirectWithNotice($e->getMessage(), true);

            return;
        }

        $service = new DocumentIngestionService(
            $this->documents,
            $this->chunker,
            $this->vectorStore,
            new EmbeddingService($provider),
        );

        $summary = $service->ingest($source);

        if ($summary->failed > 0) {
            $this->redirectWithNotice(implode(' ', $summary->errors), true);

            return;
        }

        $this->redirectWithNotice($successMessage, false);
    }

    private function assertRequestAllowed(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Keine Berechtigung.', 'wp-ai-suite'));
        }

        check_admin_referer(self::NONCE_ACTION);
    }

    private function redirectWithNotice(string $message, bool $isError): void
    {
        $url = add_query_arg(
            array_filter(['page' => self::SLUG, 'wpais_notice' => rawurlencode($message), 'wpais_error' => $isError ? '1' : null]),
            admin_url('admin.php'),
        );

        wp_safe_redirect($url);
        exit;
    }
}
