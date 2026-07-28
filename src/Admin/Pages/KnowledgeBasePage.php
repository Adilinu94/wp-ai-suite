<?php

declare(strict_types=1);

namespace WPAiSuite\Admin\Pages;

use WPAiSuite\AiCore\Provider\ActiveProviderResolver;
use WPAiSuite\AiCore\Provider\NoActiveProviderException;
use WPAiSuite\Knowledge\Chunking\ChunkerInterface;
use WPAiSuite\Jobs\ActionSchedulerIngestionDispatcher;
use WPAiSuite\Jobs\IngestionDispatchResult;
use WPAiSuite\Knowledge\DocumentIngestionService;
use WPAiSuite\Knowledge\DocumentListCriteria;
use WPAiSuite\Knowledge\DocumentListPage;
use WPAiSuite\Knowledge\DocumentRepositoryInterface;
use WPAiSuite\Knowledge\Embedding\EmbeddingProviderResolver;
use WPAiSuite\Knowledge\Embedding\EmbeddingService;
use WPAiSuite\Knowledge\Ingestion\AutoRefResolver;
use WPAiSuite\Knowledge\Ingestion\ChunkContentReconstructor;
use WPAiSuite\Knowledge\Ingestion\FaqEntry;
use WPAiSuite\Knowledge\Ingestion\FaqSource;
use WPAiSuite\Knowledge\Ingestion\KnowledgeSourceInterface;
use WPAiSuite\Knowledge\Ingestion\PdfFileReference;
use WPAiSuite\Knowledge\Ingestion\PdfSource;
use WPAiSuite\Knowledge\Ingestion\PdfTextExtractorInterface;
use WPAiSuite\Knowledge\Ingestion\PdfUploadValidator;
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
        private readonly EmbeddingProviderResolver $embeddingProviderResolver,
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
        add_action('admin_post_wpais_kb_delete', [$this, 'handleDelete']);
        add_action('admin_post_wpais_kb_bulk_action', [$this, 'handleBulkAction']);
        add_action('admin_post_wpais_kb_export_csv', [$this, 'handleExportCsv']);
        add_action('admin_post_wpais_kb_import_csv', [$this, 'handleImportCsv']);
    }

    public function renderPage(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Keine Berechtigung.', 'wp-ai-suite'));
        }

        echo '<div class="wrap"><h1>' . esc_html__('Wissensbasis', 'wp-ai-suite') . '</h1>';
        $this->renderNotice();
        $criteria = $this->currentCriteria();
        $this->renderDocumentsTable($this->documents->list($criteria), $criteria);
        $this->renderUploadPdfForm();
        $this->renderAddEntryForm();
        $this->renderExportImportSection();
        echo '</div>';
    }

    /**
     * Umbauplan Post-MVP Punkt 9: liest Filter/Seite aus der GET-Anfrage der Filterleiste
     * (renderFilterBar()) bzw. den Pager-Links (pagerUrl()). "wpais_paged" statt "page", weil
     * "page" bereits der WP-Admin-Query-Var fuer die Menue-Seite selbst ist (self::SLUG).
     */
    private function currentCriteria(): DocumentListCriteria
    {
        $status = isset($_GET['wpais_status']) ? sanitize_key(wp_unslash((string) $_GET['wpais_status'])) : '';
        $sourceType = isset($_GET['wpais_type']) ? sanitize_key(wp_unslash((string) $_GET['wpais_type'])) : '';
        $search = isset($_GET['wpais_q']) ? sanitize_text_field(wp_unslash((string) $_GET['wpais_q'])) : '';
        $page = isset($_GET['wpais_paged']) ? max(1, (int) $_GET['wpais_paged']) : 1;

        return new DocumentListCriteria(
            page: $page,
            perPage: 20,
            status: in_array($status, ['pending', 'processed', 'failed'], true) ? $status : null,
            sourceType: $sourceType !== '' ? $sourceType : null,
            titleSearch: $search !== '' ? $search : null,
        );
    }

    private function hasActiveFilter(DocumentListCriteria $criteria): bool
    {
        return $criteria->status !== null || $criteria->sourceType !== null || $criteria->titleSearch !== null;
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

    private function renderDocumentsTable(DocumentListPage $page, DocumentListCriteria $criteria): void
    {
        echo '<h2>' . esc_html__('Dokumente', 'wp-ai-suite') . '</h2>';
        $this->renderFilterBar($criteria);

        if ($page->items === []) {
            $message = $this->hasActiveFilter($criteria)
                ? __('Keine Dokumente entsprechen diesem Filter.', 'wp-ai-suite')
                : __('Noch keine Dokumente in der Wissensbasis.', 'wp-ai-suite');
            echo '<p>' . esc_html($message) . '</p>';

            return;
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<input type="hidden" name="action" value="wpais_kb_bulk_action" />';

        echo '<div class="tablenav top"><div class="alignleft actions">';
        echo '<select name="bulk_action">';
        echo '<option value="">' . esc_html__('Mit Auswahl…', 'wp-ai-suite') . '</option>';
        echo '<option value="reindex">' . esc_html__('Neu indexieren (nur WordPress-Inhalte/PDF)', 'wp-ai-suite') . '</option>';
        echo '<option value="delete">' . esc_html__('Löschen', 'wp-ai-suite') . '</option>';
        echo '</select> ';
        submit_button(__('Anwenden', 'wp-ai-suite'), 'secondary', '', false, [
            'onclick' => "return confirm('" . esc_js(__('Ausgewaehlte Aktion wirklich auf alle markierten Dokumente anwenden?', 'wp-ai-suite')) . "');",
        ]);
        echo '</div></div>';

        echo '<table class="wp-list-table widefat fixed striped"><thead><tr>';
        echo '<td class="manage-column column-cb check-column"><input type="checkbox" class="wpais-kb-select-all" onclick="var f=this.form,cbs=f.querySelectorAll(&quot;input[name=\'document_ids[]\']&quot;);for(var i=0;i<cbs.length;i++){cbs[i].checked=this.checked;}" /></td>';
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

        foreach ($page->items as $document) {
            echo '<tr>';
            echo '<th scope="row" class="check-column"><input type="checkbox" name="document_ids[]" value="' . esc_attr((string) $document->id) . '" /></th>';
            echo '<td>' . esc_html($document->title) . '</td>';
            echo '<td>' . esc_html($document->sourceType) . '</td>';
            echo '<td>' . $this->renderStatusBadge($document->status);
            if ($document->status === 'failed' && $document->errorMessage !== null) {
                echo '<br><span class="description">' . esc_html($document->errorMessage) . '</span>';
            }
            echo '</td>';
            echo '<td>' . esc_html($document->updatedAt?->format('Y-m-d H:i') ?? '—') . '</td>';
            echo '<td>' . $this->renderReindexAction($document->id, $document->sourceType) . ' ' . $this->renderDeleteAction($document->id) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        $this->renderPager($page, $criteria);
        echo '</form>';
    }

    /**
     * Umbauplan Post-MVP Punkt 9, Ziel 2+4: Status/Typ als Dropdown, Titel als Volltextsuche,
     * plus "Nur Fehler"-Schnellfilter als direkter Link (ohne erst das Dropdown umstellen und
     * absenden zu muessen).
     */
    private function renderFilterBar(DocumentListCriteria $criteria): void
    {
        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" style="margin:1em 0;">';
        echo '<input type="hidden" name="page" value="' . esc_attr(self::SLUG) . '" />';

        echo '<select name="wpais_status">';
        echo '<option value="">' . esc_html__('Alle Status', 'wp-ai-suite') . '</option>';
        foreach ([
            'pending' => __('Ausstehend', 'wp-ai-suite'),
            'processed' => __('Verarbeitet', 'wp-ai-suite'),
            'failed' => __('Fehlgeschlagen', 'wp-ai-suite'),
        ] as $value => $label) {
            printf('<option value="%s"%s>%s</option>', esc_attr($value), selected($criteria->status, $value, false), esc_html($label));
        }
        echo '</select> ';

        echo '<select name="wpais_type">';
        echo '<option value="">' . esc_html__('Alle Typen', 'wp-ai-suite') . '</option>';
        foreach (['wp_content' => 'WordPress', 'pdf' => 'PDF', 'faq' => 'FAQ', 'custom_text' => __('Freitext', 'wp-ai-suite')] as $value => $label) {
            printf('<option value="%s"%s>%s</option>', esc_attr($value), selected($criteria->sourceType, $value, false), esc_html($label));
        }
        echo '</select> ';

        echo '<input type="search" name="wpais_q" value="' . esc_attr((string) $criteria->titleSearch) . '" placeholder="' . esc_attr__('Titel durchsuchen…', 'wp-ai-suite') . '" /> ';

        submit_button(__('Filtern', 'wp-ai-suite'), 'secondary', '', false);

        if ($criteria->status !== 'failed') {
            $failedUrl = add_query_arg(['page' => self::SLUG, 'wpais_status' => 'failed'], admin_url('admin.php'));
            echo ' <a href="' . esc_url($failedUrl) . '" class="button">' . esc_html__('Nur Fehler', 'wp-ai-suite') . '</a>';
        }

        if ($this->hasActiveFilter($criteria)) {
            echo ' <a href="' . esc_url(admin_url('admin.php?page=' . self::SLUG)) . '">' . esc_html__('Filter zurücksetzen', 'wp-ai-suite') . '</a>';
        }

        echo '</form>';
    }

    private function renderPager(DocumentListPage $page, DocumentListCriteria $criteria): void
    {
        $totalPages = $page->totalPages();

        if ($totalPages <= 1) {
            return;
        }

        echo '<p class="tablenav-pages">';
        printf(
            esc_html__('Seite %1$d von %2$d (%3$d Dokumente gesamt)', 'wp-ai-suite'),
            $page->page,
            $totalPages,
            $page->total,
        );

        if ($page->page > 1) {
            echo ' <a class="button" href="' . esc_url($this->pagerUrl($criteria, $page->page - 1)) . '">' . esc_html__('« Zurück', 'wp-ai-suite') . '</a>';
        }
        if ($page->page < $totalPages) {
            echo ' <a class="button" href="' . esc_url($this->pagerUrl($criteria, $page->page + 1)) . '">' . esc_html__('Weiter »', 'wp-ai-suite') . '</a>';
        }

        echo '</p>';
    }

    private function pagerUrl(DocumentListCriteria $criteria, int $targetPage): string
    {
        return add_query_arg(array_filter([
            'page' => self::SLUG,
            'wpais_status' => $criteria->status,
            'wpais_type' => $criteria->sourceType,
            'wpais_q' => $criteria->titleSearch,
            'wpais_paged' => $targetPage > 1 ? $targetPage : null,
        ]), admin_url('admin.php'));
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

    private function renderDeleteAction(int $documentId): string
    {
        $url = wp_nonce_url(
            admin_url('admin-post.php?action=wpais_kb_delete&document_id=' . $documentId),
            self::NONCE_ACTION,
        );

        // Bewusst KEINE Interpolation von $title in den onclick-JS-String: ein Dokumenttitel
        // kann Anführungszeichen enthalten (z.B. ein WP-Seitentitel), die sich mit esc_attr()
        // allein nicht sicher in einen verschachtelten JS-String-Literal einbetten liessen, ohne
        // das umschliessende onclick-Attribut zu zerbrechen. Eine generische Bestaetigung ist
        // dafuer die einfachere, robuste Loesung (title steht ohnehin schon in derselben
        // Tabellenzeile sichtbar).
        return sprintf(
            '<a href="%s" class="button button-small button-link-delete" onclick="return confirm(\'%s\');">%s</a>',
            esc_url($url),
            esc_js(__('Dieses Dokument wirklich unwiderruflich loeschen?', 'wp-ai-suite')),
            esc_html__('Löschen', 'wp-ai-suite'),
        );
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
        echo '<input type="text" class="regular-text" name="ref" id="wpais_entry_ref" placeholder="' . esc_attr__('leer lassen für automatischen Schlüssel aus dem Titel', 'wp-ai-suite') . '" /></td></tr>';

        echo '<tr><th scope="row"><label for="wpais_entry_title">' . esc_html__('Frage / Titel', 'wp-ai-suite') . '</label></th><td>';
        echo '<input type="text" class="regular-text" name="entry_title" id="wpais_entry_title" required /></td></tr>';

        echo '<tr><th scope="row"><label for="wpais_entry_content">' . esc_html__('Antwort / Text', 'wp-ai-suite') . '</label></th><td>';
        echo '<textarea class="large-text" rows="5" name="entry_content" id="wpais_entry_content" required></textarea></td></tr>';

        echo '</tbody></table>';
        submit_button(__('Speichern und einlesen', 'wp-ai-suite'));
        echo '</form>';
    }

    /**
     * Verbesserung Punkt 10: Export als einfacher Nonce-Link (GET, loest direkt den
     * Datei-Download aus — kein Formular noetig fuer eine reine Leseoperation). Import als
     * eigenes Formular mit Datei-Upload, analog zu renderUploadPdfForm().
     */
    private function renderExportImportSection(): void
    {
        echo '<h2>' . esc_html__('FAQ / Freitext: CSV-Import/-Export', 'wp-ai-suite') . '</h2>';
        echo '<p class="description">' . esc_html__('Export enthaelt nur FAQ- und Freitext-Eintraege (kein WordPress-Inhalt/PDF, die haben ja bereits eine externe Quelle). Die Spalte "vollstaendig" zeigt an, ob der Inhalt exakt oder nur bestmoeglich rekonstruiert ist.', 'wp-ai-suite') . '</p>';

        $exportUrl = wp_nonce_url(
            admin_url('admin-post.php?action=wpais_kb_export_csv'),
            self::NONCE_ACTION,
        );
        echo '<p><a href="' . esc_url($exportUrl) . '" class="button">' . esc_html__('Als CSV exportieren', 'wp-ai-suite') . '</a></p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data">';
        wp_nonce_field(self::NONCE_ACTION);
        echo '<input type="hidden" name="action" value="wpais_kb_import_csv" />';
        echo '<p><input type="file" name="csv_file" accept=".csv,text/csv" required /> ';
        submit_button(__('CSV importieren', 'wp-ai-suite'), 'secondary', '', false);
        echo '</p></form>';
    }

    public function handleUploadPdf(): void
    {
        $this->assertRequestAllowed();

        if (!isset($_FILES['pdf_file']) || !is_array($_FILES['pdf_file']) || (int) $_FILES['pdf_file']['error'] !== UPLOAD_ERR_OK) {
            $this->redirectWithNotice(__('PDF-Upload fehlgeschlagen (keine Datei erhalten).', 'wp-ai-suite'), true);
        }

        // Umbauplan Post-MVP Punkt 8: Endung/MIME/Groesse VOR media_handle_upload pruefen — die
        // bisherige clientseitige accept="application/pdf" im Formular ist nur ein Hinweis fuer
        // den Dateidialog, keine echte Absicherung (siehe PdfUploadValidator-Docblock).
        $validationError = (new PdfUploadValidator())->validate($_FILES['pdf_file']);

        if ($validationError !== null) {
            $this->redirectWithNotice($validationError, true);
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

        if ($title === '' || $content === '') {
            $this->redirectWithNotice(__('Bitte Frage/Titel und Antwort/Text ausfüllen.', 'wp-ai-suite'), true);
        }

        if ($ref === '') {
            // Umbauplan Post-MVP Punkt 9: ref optional — Server vergibt einen stabilen Slug aus
            // dem Titel (siehe AutoRefResolver-Docblock fuer die Kollisions-/Re-Update-Regel).
            $ref = (new AutoRefResolver($this->documents))->resolve($entryType, sanitize_title($title), $title);
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

    /** Verbesserung Punkt 1: Dokument-Zeile + Chunks + Vector-Store-Eintraege loeschen. */
    public function handleDelete(): void
    {
        $this->assertRequestAllowed();

        $documentId = (int) ($_GET['document_id'] ?? 0);
        $document = $this->documents->findById($documentId);

        if ($document === null) {
            $this->redirectWithNotice(__('Dokument nicht gefunden.', 'wp-ai-suite'), true);

            return;
        }

        $this->deleteDocument($documentId);
        $this->redirectWithNotice(sprintf(__('"%s" geloescht.', 'wp-ai-suite'), $document->title), false);
    }

    /**
     * Verbesserung Punkt 3: Mehrfachauswahl aus der Dokumentliste — "loeschen" laeuft direkt
     * pro ID, "neu indexieren" gruppiert die ausgewaehlten wp_content/pdf-Dokumente in JEWEILS
     * EINE Quelle (ueber den in Verbesserung Punkt 2 ergaenzten $postIds-Filter von
     * WordPressContentSource) statt pro Zeile einen eigenen (bei wp_content: kompletten!)
     * Re-Sync auszuloesen.
     */
    public function handleBulkAction(): void
    {
        $this->assertRequestAllowed();

        $action = sanitize_key(wp_unslash((string) ($_POST['bulk_action'] ?? '')));
        $documentIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['document_ids'] ?? [])))));

        if ($documentIds === []) {
            $this->redirectWithNotice(__('Keine Dokumente ausgewaehlt.', 'wp-ai-suite'), true);

            return;
        }

        if ($action === 'delete') {
            foreach ($documentIds as $documentId) {
                $this->deleteDocument($documentId);
            }
            $this->redirectWithNotice(sprintf(__('%d Dokument(e) geloescht.', 'wp-ai-suite'), count($documentIds)), false);

            return;
        }

        if ($action === 'reindex') {
            $this->bulkReindex($documentIds);

            return;
        }

        $this->redirectWithNotice(__('Unbekannte Aktion.', 'wp-ai-suite'), true);
    }

    /** @param int[] $documentIds */
    private function bulkReindex(array $documentIds): void
    {
        $postIds = [];
        $pdfRefs = [];

        foreach ($documentIds as $documentId) {
            $document = $this->documents->findById($documentId);

            if ($document === null) {
                continue;
            }

            if ($document->sourceType === 'wp_content') {
                $postIds[] = (int) $document->sourceRef;
            } elseif ($document->sourceType === 'pdf') {
                $path = get_attached_file((int) $document->sourceRef);
                $pdfRefs[] = new PdfFileReference((string) $document->sourceRef, $document->title, $path !== false ? $path : '');
            }
            // faq/custom_text: bewusst uebersprungen, siehe Klassen-Docblock ("Neu indexieren"
            // ist nur fuer wp_content/pdf sinnvoll).
        }

        if ($postIds === [] && $pdfRefs === []) {
            $this->redirectWithNotice(__('Keines der ausgewaehlten Dokumente kann neu indexiert werden (nur WordPress-Inhalte/PDFs).', 'wp-ai-suite'), true);

            return;
        }

        $results = [];
        if ($postIds !== []) {
            $results[] = $this->dispatchSource(new WordPressContentSource(postIds: $postIds));
        }
        if ($pdfRefs !== []) {
            $results[] = $this->dispatchSource(new PdfSource($pdfRefs, $this->pdfExtractor));
        }

        $queued = array_sum(array_map(static fn (IngestionDispatchResult $r): int => $r->queued, $results));
        $failed = array_sum(array_map(static fn (IngestionDispatchResult $r): int => $r->summary->failed, $results));
        $processed = array_sum(array_map(static fn (IngestionDispatchResult $r): int => $r->summary->processed, $results));
        $errors = array_merge(...array_map(static fn (IngestionDispatchResult $r): array => $r->summary->errors, $results));

        if ($failed > 0) {
            $this->redirectWithNotice(implode(' ', $errors), true);

            return;
        }

        if ($queued > 0) {
            $this->redirectWithNotice(
                sprintf(__('%d Dokument(e) werden im Hintergrund verarbeitet und erscheinen in Kuerze in der Liste.', 'wp-ai-suite'), $queued),
                false,
            );

            return;
        }

        $this->redirectWithNotice(sprintf(__('%d Dokument(e) neu indexiert.', 'wp-ai-suite'), $processed), false);
    }

    /** Orchestriert die vollstaendige Loeschung ueber alle drei betroffenen Ports (siehe DocumentRepositoryInterface::delete()-Docblock). */
    private function deleteDocument(int $documentId): void
    {
        $this->vectorStore->deleteByDocument($documentId);
        $this->documents->deleteChunks($documentId);
        $this->documents->delete($documentId);
    }

    /**
     * Verbesserung Punkt 10: Export nur fuer faq/custom_text — wp_content/pdf haben eine
     * externe Quelle (WP-Post bzw. Media-Anhang), die kein CSV-Roundtrip braucht. Die Spalte
     * "vollstaendig" (ja/nein) zeigt an, ob die Rekonstruktion aus den Chunks exakt ist (ein
     * einzelner Chunk) oder Best-Effort (mehrere Chunks) — siehe ChunkContentReconstructor-
     * Docblock.
     */
    public function handleExportCsv(): void
    {
        $this->assertRequestAllowed();

        $reconstructor = new ChunkContentReconstructor();
        $documents = array_merge(
            $this->documents->list(new DocumentListCriteria(sourceType: 'faq', perPage: 100000))->items,
            $this->documents->list(new DocumentListCriteria(sourceType: 'custom_text', perPage: 100000))->items,
        );

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="wissensbasis-export-' . gmdate('Y-m-d') . '.csv"');

        $out = fopen('php://output', 'w');
        fputcsv($out, ['ref', 'entry_type', 'title', 'content', 'vollstaendig']);

        foreach ($documents as $document) {
            $chunkContents = $this->documents->findChunkContents($document->id);
            fputcsv($out, [
                (string) $document->sourceRef,
                $document->sourceType,
                $document->title,
                $reconstructor->reconstruct($chunkContents),
                $reconstructor->isExact($chunkContents) ? 'ja' : 'nein',
            ]);
        }

        fclose($out);
        exit;
    }

    /**
     * Verbesserung Punkt 10: erwartet dieselben Spalten wie handleExportCsv() ausgibt (ref,
     * entry_type, title, content — "vollstaendig" wird beim Import ignoriert, falls vorhanden).
     * ref darf leer sein — dann greift wie im Einzelformular AutoRefResolver. Nutzt fgetcsv()
     * (nicht zeilenweises Parsen), damit mehrzeiliger Inhalt in Anfuehrungszeichen korrekt
     * gelesen wird. Gruppiert nach entry_type und schickt jede Gruppe als EINE FaqSource durch
     * dispatchSource() — dieselbe Sync/Async-Schwelle wie bei Bulk-Reindex greift damit auch hier.
     */
    public function handleImportCsv(): void
    {
        $this->assertRequestAllowed();

        if (!isset($_FILES['csv_file']) || !is_array($_FILES['csv_file']) || (int) $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            $this->redirectWithNotice(__('CSV-Import fehlgeschlagen (keine Datei erhalten).', 'wp-ai-suite'), true);

            return;
        }

        $handle = fopen((string) $_FILES['csv_file']['tmp_name'], 'rb');

        if ($handle === false) {
            $this->redirectWithNotice(__('CSV-Datei konnte nicht gelesen werden.', 'wp-ai-suite'), true);

            return;
        }

        fgetcsv($handle); // Kopfzeile ueberspringen.

        $entriesByType = ['faq' => [], 'custom_text' => []];
        $skipped = 0;

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 4) {
                $skipped++;

                continue;
            }

            [$ref, $entryType, $title, $content] = $row;
            $entryType = $entryType === 'custom_text' ? 'custom_text' : 'faq';
            $title = trim((string) $title);
            $content = trim((string) $content);

            if ($title === '' || $content === '') {
                $skipped++;

                continue;
            }

            $ref = trim((string) $ref);
            if ($ref === '') {
                $ref = (new AutoRefResolver($this->documents))->resolve($entryType, sanitize_title($title), $title);
            }

            $entriesByType[$entryType][] = new FaqEntry($ref, $title, $content);
        }

        fclose($handle);

        if ($entriesByType['faq'] === [] && $entriesByType['custom_text'] === []) {
            $this->redirectWithNotice(__('Keine gueltigen Zeilen in der CSV-Datei gefunden.', 'wp-ai-suite'), true);

            return;
        }

        $results = [];
        foreach ($entriesByType as $entryType => $entries) {
            if ($entries !== []) {
                $results[] = $this->dispatchSource(new FaqSource($entryType, $entries));
            }
        }

        $failed = array_sum(array_map(static fn (IngestionDispatchResult $r): int => $r->summary->failed, $results));

        if ($failed > 0) {
            $errors = array_merge(...array_map(static fn (IngestionDispatchResult $r): array => $r->summary->errors, $results));
            $this->redirectWithNotice(implode(' ', $errors), true);

            return;
        }

        $imported = array_sum(array_map(static fn (IngestionDispatchResult $r): int => $r->summary->processed + $r->queued, $results));
        $message = sprintf(__('%d Eintrag/Einträge importiert.', 'wp-ai-suite'), $imported);
        if ($skipped > 0) {
            $message .= ' ' . sprintf(__('%d Zeile(n) uebersprungen (unvollstaendig).', 'wp-ai-suite'), $skipped);
        }

        $this->redirectWithNotice($message, false);
    }

    private function ingestAndRedirect(KnowledgeSourceInterface $source, string $successMessage): void
    {
        $result = $this->dispatchSource($source);

        if ($result->summary->failed > 0) {
            $this->redirectWithNotice(implode(' ', $result->summary->errors), true);

            return;
        }

        if ($result->queued > 0) {
            $this->redirectWithNotice(
                sprintf(
                    /* translators: %d: Anzahl der im Hintergrund eingeplanten Dokumente */
                    __('%d Dokument(e) werden im Hintergrund verarbeitet und erscheinen in Kuerze in der Liste.', 'wp-ai-suite'),
                    $result->queued,
                ),
                false,
            );

            return;
        }

        $this->redirectWithNotice($successMessage, false);
    }

    /**
     * Aus ingestAndRedirect() extrahiert (Verbesserung Punkt 3), damit bulkReindex() dieselbe
     * Provider-Aufloesung + Sync/Async-Schwellwert-Logik fuer MEHRERE Quellen (wp_content-Gruppe
     * + pdf-Gruppe) wiederverwenden kann, statt sie zu duplizieren. Redirectet selbst bei
     * fehlendem aktivem Provider (per redirectWithNotice()+exit) — der Rueckgabewert ist dann
     * zwar laut Signatur non-null, wird aber wegen exit() nie mehr gelesen.
     */
    private function dispatchSource(KnowledgeSourceInterface $source): IngestionDispatchResult
    {
        try {
            [$provider, ] = $this->providerResolver->resolve();
        } catch (NoActiveProviderException $e) {
            $this->redirectWithNotice($e->getMessage(), true);

            return new IngestionDispatchResult(queued: 0, summary: new \WPAiSuite\Knowledge\IngestionSummary(0, 0, 0, []));
        }

        // Umbauplan Post-MVP Punkt 1: siehe EmbeddingProviderResolver-Docblock.
        $embeddingProvider = $this->embeddingProviderResolver->resolve() ?? $provider;

        $service = new DocumentIngestionService(
            $this->documents,
            $this->chunker,
            $this->vectorStore,
            new EmbeddingService($embeddingProvider),
        );

        // Umbauplan Post-MVP Punkt 3: syncMaxDocs per Filter statt neuer Admin-Option — Adi kann
        // ihn bei Bedarf in einem mu-plugin/functions.php ueberschreiben, ohne dass dafuer ein
        // eigenes UI-Feld noetig war (siehe ActionSchedulerIngestionDispatcher-Docblock).
        $dispatcher = new ActionSchedulerIngestionDispatcher(
            $service,
            (int) apply_filters('wpais_ingest_sync_max_docs', 20),
        );

        return $dispatcher->dispatch($source);
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
