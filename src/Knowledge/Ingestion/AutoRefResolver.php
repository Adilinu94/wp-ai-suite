<?php

declare(strict_types=1);

namespace WPAiSuite\Knowledge\Ingestion;

use WPAiSuite\Knowledge\DocumentRepositoryInterface;

/**
 * Umbauplan Post-MVP Punkt 9: loest einen stabilen source_ref auf, wenn der Admin im
 * FAQ/Freitext-Formular keinen manuellen Schluessel angibt (vorher Pflichtfeld, siehe
 * FaqEntry-Docblock). $baseSlug kommt bereits fertig slugifiziert vom Aufrufer — sanitize_title()
 * ist eine WP-Funktion und wird deshalb bewusst NICHT hier aufgerufen, damit diese Klasse WP-frei
 * und per Pest-Unit testbar bleibt (gleiches Muster wie ClientIpResolver/RagQueryBuilder in
 * frueheren Umbauplan-Punkten).
 *
 * Kollisionsregel: existiert der Slug bereits UNTER DEMSELBEN TITEL, ist das kein echter
 * Konflikt, sondern derselbe Eintrag, der erneut gespeichert wird (das Formular hat kein
 * Dokument-ID-Feld) — DocumentIngestionService aktualisiert diesen dann ueber denselben
 * source_ref (DoD "re-updatebar mit gleichem Auto-Slug"). Nur wenn derselbe Slug zu einem
 * ANDEREN Titel gehoert, ist es eine echte Kollision und bekommt einen Suffix "-2", "-3", ...
 */
final class AutoRefResolver
{
    public function __construct(
        private readonly DocumentRepositoryInterface $documents,
    ) {
    }

    public function resolve(string $entryType, string $baseSlug, string $title): string
    {
        $root = $baseSlug !== '' ? $baseSlug : 'eintrag';
        $slug = $root;
        $suffix = 1;

        while (true) {
            $existing = $this->documents->findBySourceTypeAndRef($entryType, $slug);

            if ($existing === null || $existing->title === $title) {
                return $slug;
            }

            $suffix++;
            $slug = $root . '-' . $suffix;
        }
    }
}
