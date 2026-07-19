<?php

declare(strict_types=1);

namespace WPAiSuite\Knowledge\Ingestion;

/**
 * Ein bereits aufgeloester PDF-Dateiverweis. DocumentsController (WP-Adapter-Schicht, Abschnitt 1)
 * loest eine WP-Mediathek-Anhang-ID via get_attached_file()/get_the_title() zu Dateipfad+Titel
 * auf, BEVOR PdfSource konstruiert wird — dadurch braucht PdfSource selbst keine WordPress-
 * Funktionen, bleibt WP-Bootstrap-frei unit-testbar (Bauplan Abschnitt 14) und ist nicht daran
 * gebunden, dass eine PDF-Datei zwingend aus der WP-Mediathek stammt.
 *
 * $ref wird 1:1 als RawDocument::$sourceRef durchgereicht (wpais_documents.source_ref, Abschnitt
 * 4) — bei den heutigen WP-Mediathek-Anhaengen die Attachment-Post-ID als String.
 */
final class PdfFileReference
{
    public function __construct(
        public readonly string $ref,
        public readonly string $title,
        public readonly string $filePath,
    ) {
    }
}
