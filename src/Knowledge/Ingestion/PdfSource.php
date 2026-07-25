<?php

declare(strict_types=1);

namespace WPAiSuite\Knowledge\Ingestion;

/**
 * Bauplan Abschnitt 7/15 (M6, "PDF/FAQ-Ingestion"): "PdfSource (Text-Extraktion)". Anders als
 * WordPressContentSource (M4, direkt an WP_Query gekoppelt und deshalb bewusst nur per
 * Integration-Test abgedeckt) bekommt PdfSource bereits fertig aufgeloeste Dateipfade+Titel
 * (PdfFileReference, von DocumentsController aus WP-Anhang-IDs gebaut) und die eigentliche
 * Extraktion ueber den PdfTextExtractorInterface-Port — dadurch komplett WP-Bootstrap-frei und
 * unit-testbar (siehe tests/Unit/Knowledge/Ingestion/PdfSourceTest.php), obwohl die Quelle
 * inhaltlich klar WP-Mediathek-Anhaenge meint.
 *
 * Fehlerisolation: schlaegt die Extraktion einer einzelnen Datei fehl, wird das NICHT geworfen
 * (wuerde die foreach-Schleife in DocumentIngestionService::ingest() fuer alle folgenden Dateien
 * mit abbrechen), sondern als RawDocument mit gesetztem $extractionError weitergereicht — siehe
 * RawDocument-Docblock. Ein kaputtes PDF blockiert damit die anderen hochgeladenen PDFs nicht,
 * konsistent mit dem in M4 etablierten Prinzip.
 *
 * Umbauplan Post-MVP Punkt 8: ein PDF OHNE extrahierbaren Text (z.B. ein reiner Bild-Scan ohne
 * Texterkennung/OCR) wird seitdem GENAUSO behandelt wie ein Extraktionsfehler — vorher (M6) lief
 * das als "processed" mit 0 Chunks durch, was fuer Adi im Wissensbasis-Dashboard unauffaellig
 * blieb (kein Fehler sichtbar), obwohl das Dokument fuer RAG faktisch nutzlos war. `failed` +
 * klare Fehlermeldung ist ehrlicher und nutzt den seit M8 bestehenden Fehler-Hinweis in der
 * Dokumentliste (KnowledgeBasePage), ohne dass dort etwas Neues gebaut werden musste.
 */
final class PdfSource implements KnowledgeSourceInterface
{
    /** @param PdfFileReference[] $files */
    public function __construct(
        private readonly array $files,
        private readonly PdfTextExtractorInterface $extractor,
    ) {
    }

    public function getType(): string
    {
        return 'pdf';
    }

    public function fetch(): iterable
    {
        foreach ($this->files as $file) {
            try {
                $text = $this->extractor->extract($file->filePath);
            } catch (PdfExtractionException $e) {
                yield new RawDocument(
                    sourceType: $this->getType(),
                    sourceRef: $file->ref,
                    title: $file->title,
                    content: '',
                    extractionError: $e->getMessage(),
                );

                continue;
            }

            // Gleiche Normalisierung wie WordPressContentSource (M4): Chunker/Embedding wollen
            // Lesetext ohne mehrfache Leerzeichen/Zeilenumbrueche, keine PDF-Layout-Artefakte.
            $normalized = trim((string) preg_replace('/\s+/', ' ', $text));

            if ($normalized === '') {
                // Umbauplan Post-MVP Punkt 8: kein "continue"/kein leises RawDocument mehr (siehe
                // Klassen-Docblock) — ein leerer Text ist ab jetzt ein Fehlerfall, kein valides
                // Nullergebnis, damit er in DocumentIngestionService::ingestOne() denselben Pfad
                // wie ein echter Extraktionsfehler nimmt (markFailed + sichtbare error_message).
                yield new RawDocument(
                    sourceType: $this->getType(),
                    sourceRef: $file->ref,
                    title: $file->title,
                    content: '',
                    extractionError: __('PDF enthaelt keinen extrahierbaren Text (vermutlich ein Bild-Scan ohne Texterkennung/OCR).', 'wp-ai-suite'),
                );

                continue;
            }

            yield new RawDocument(
                sourceType: $this->getType(),
                sourceRef: $file->ref,
                title: $file->title,
                content: $normalized,
            );
        }
    }
}
