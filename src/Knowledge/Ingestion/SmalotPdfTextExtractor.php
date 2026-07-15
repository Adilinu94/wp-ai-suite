<?php

declare(strict_types=1);

namespace WPAiSuite\Knowledge\Ingestion;

/**
 * Referenzimplementierung von PdfTextExtractorInterface (M6) ueber smalot/pdfparser (Composer-
 * Dependency, siehe composer.json). Einziger Ort im gesamten Plugin, der \Smalot\PdfParser\*
 * referenziert — genau der Punkt von PdfTextExtractorInterface als Port.
 *
 * NICHT unit-getestet (anders als PdfSource selbst): smalot/pdfparser kann in dieser Sandbox nicht
 * per Composer installiert werden (packagist.org nicht im erlaubten Netzwerk, siehe FORTSETZUNG.md
 * "Bekannte Einschraenkungen"), ein Test gegen die echte Klasse wuerde hier mit einem Class-not-
 * found-Fehler scheitern. Nach `composer install` auf solar.local ist die Klasse verfuegbar; ein
 * manueller Smoke-Test mit einer echten PDF-Datei ist der praktikablere Weg, siehe FORTSETZUNG.md
 * "Manuell testen" fuer M6.
 */
final class SmalotPdfTextExtractor implements PdfTextExtractorInterface
{
    public function extract(string $filePath): string
    {
        if ($filePath === '' || !is_readable($filePath)) {
            throw new PdfExtractionException(sprintf(
                /* translators: %s: absolute file path of the unreadable PDF */
                __('PDF-Datei nicht lesbar: %s', 'wp-ai-suite'),
                $filePath === '' ? '(kein Pfad)' : $filePath,
            ));
        }

        try {
            $parser = new \Smalot\PdfParser\Parser();
            $document = $parser->parseFile($filePath);

            return $document->getText();
        } catch (PdfExtractionException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // smalot/pdfparser wirft bei kaputten/verschluesselten PDFs diverse eigene Exception-
            // Typen (z.B. \Exception mit wechselnden Nachrichten je nach Fehlerart) — hier bewusst
            // breit gefangen und einheitlich in PdfExtractionException uebersetzt, damit PdfSource
            // nur EINEN Exception-Typ behandeln muss (siehe PdfExtractionException-Docblock).
            throw new PdfExtractionException(
                sprintf(
                    /* translators: 1: file path, 2: underlying error message from smalot/pdfparser */
                    __('PDF-Textextraktion fehlgeschlagen fuer %1$s: %2$s', 'wp-ai-suite'),
                    $filePath,
                    $e->getMessage(),
                ),
                previous: $e,
            );
        }
    }
}
