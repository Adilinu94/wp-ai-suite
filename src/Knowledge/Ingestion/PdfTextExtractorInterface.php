<?php

declare(strict_types=1);

namespace WPAiSuite\Knowledge\Ingestion;

/**
 * Port fuer PDF-Textextraktion (M6, Bauplan Abschnitt 15: "Upload + Extraktion + Chunking").
 * Kein Bauplan-Codeschnipsel dafuer (wie schon DocumentRepositoryInterface in M4) — analog zu
 * HttpTransportInterface aus M1 ("WP-Bootstrap-freie Tests") hier bewusst als eigener Port
 * eingefuehrt, damit PdfSource selbst keine konkrete PDF-Bibliothek kennt und ohne die echte
 * smalot/pdfparser-Abhaengigkeit unit-testbar bleibt (siehe bekannte Einschraenkungen in
 * FORTSETZUNG.md: packagist.org ist im Sandbox-Netzwerk nicht erreichbar, composer install kann
 * SmalotPdfTextExtractor hier nicht verifizieren — Grund, warum dieser Port ueberhaupt existiert).
 */
interface PdfTextExtractorInterface
{
    /**
     * @throws PdfExtractionException Datei nicht lesbar, kein valides PDF, oder (aktuell von
     *     smalot/pdfparser nicht unterstuetzt) ein verschluesseltes PDF.
     */
    public function extract(string $filePath): string;
}
