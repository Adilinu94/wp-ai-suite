<?php

declare(strict_types=1);

namespace WPAiSuite\Knowledge\Ingestion;

/**
 * Wird von PdfTextExtractorInterface::extract() geworfen: Datei nicht lesbar, kein valides PDF,
 * oder ein verschluesseltes/passwortgeschuetztes PDF (smalot/pdfparser unterstuetzt laut eigener
 * Doku aktuell keine gesicherten Dokumente). PdfSource faengt diese Exception pro Datei ab und
 * wandelt sie in ein RawDocument mit gesetztem $extractionError um (siehe dortiger Docblock) —
 * bewusst eine eigene Klasse statt eines generischen RuntimeException-Catch-Alls, damit PdfSource
 * gezielt NUR Extraktionsfehler abfaengt und alles andere (Programmierfehler etc.) durchreicht,
 * analog zur Begruendung bei UnsupportedCapabilityException (M1).
 */
final class PdfExtractionException extends \RuntimeException
{
}
