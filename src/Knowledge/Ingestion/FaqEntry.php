<?php

declare(strict_types=1);

namespace WPAiSuite\Knowledge\Ingestion;

/**
 * Ein manueller Wissensbasis-Eintrag (M6, Bauplan Abschnitt 7: "FaqSource/custom_text [manuelle
 * Einträge im Admin]"). Deckt BEIDE Quelltypen ab: bei source_type=faq ist $title die Frage und
 * $content die Antwort, bei source_type=custom_text ist $title die freie Ueberschrift und
 * $content der Freitext — strukturell identisch (Titel+Inhalt), die fachliche Bedeutung der
 * Feldnamen ist nur Sache von DocumentsController beim Parsen der REST-Anfrage
 * (question/answer bzw. title/text im JSON-Body), FaqSource/RawDocument kennen nur title/content.
 *
 * $ref: Abschnitt 4 dokumentiert fuer wpais_documents.source_ref nur "post_id oder Dateipfad" —
 * manuelle Eintraege sind aber keine WP-Posts. Da die Checksum-basierte Re-Ingestion-Erkennung
 * (Abschnitt 7, DocumentIngestionService::ingestOne()) einen stabilen source_type+source_ref-
 * Bezug braucht, um "derselbe Eintrag, geaenderter Inhalt" von "neuer Eintrag" zu unterscheiden,
 * vergibt hier der Aufrufer (Admin, ueber die REST-Anfrage) einen stabilen Schluessel/Slug — z.B.
 * "versandkosten" fuer eine FAQ zu Versandkosten. Wird derselbe ref erneut mit geaendertem
 * $content gesendet, aktualisiert DocumentIngestionService den bestehenden Eintrag (Version+1)
 * statt einen Duplikat-Eintrag anzulegen — keine Aenderung an DocumentIngestionService dafuer
 * noetig, das Verhalten ist bereits seit M4 generisch ueber source_type+source_ref.
 */
final class FaqEntry
{
    public function __construct(
        public readonly string $ref,
        public readonly string $title,
        public readonly string $content,
    ) {
    }
}
