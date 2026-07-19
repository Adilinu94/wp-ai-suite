<?php

declare(strict_types=1);

namespace WPAiSuite\Knowledge\Ingestion;

/**
 * Rueckgabetyp von KnowledgeSourceInterface::fetch() (im Bauplan-Codeschnipsel referenziert,
 * Felder hier abgeleitet aus dem wpais_documents-Schema, Abschnitt 4: source_type, source_ref,
 * title decken sich 1:1 mit den gleichnamigen Spalten; content ist der bereits extrahierte
 * Klartext, den DocumentIngestionService chunked).
 *
 * $extractionError (M6): WordPressContentSource (M4) kann beim Aufbau eines RawDocument praktisch
 * nicht scheitern (Post-Inhalt ist immer lesbar). PdfSource dagegen KANN scheitern (korruptes PDF,
 * nicht lesbare Datei) — mitten in fetch()'s Generator eine Exception zu werfen wuerde die
 * komplette foreach-Schleife in DocumentIngestionService::ingest() abbrechen und damit ALLE noch
 * nicht verarbeiteten Dokumente des Laufs mitreissen, nicht nur das fehlerhafte (Verstoss gegen
 * das M4-Prinzip "ein fehlerhaftes Dokument darf die anderen nicht blockieren", siehe dortiger
 * Docblock). Ein Extraktionsfehler wird deshalb NICHT geworfen, sondern als ganz normales
 * RawDocument mit gesetztem $extractionError weitergereicht — DocumentIngestionService::ingestOne()
 * behandelt das dann als regulaeren Fehlschlag desselben einen Dokuments (markFailed), der Rest
 * des Batches laeuft unbeeinflusst weiter. null = kein Fehler (Normalfall, alle M4-Aufrufer).
 */
final class RawDocument
{
    public function __construct(
        public readonly string $sourceType,
        public readonly ?string $sourceRef,
        public readonly string $title,
        public readonly string $content,
        public readonly ?string $extractionError = null,
    ) {
    }
}
