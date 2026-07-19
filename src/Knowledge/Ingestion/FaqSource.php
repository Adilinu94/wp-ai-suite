<?php

declare(strict_types=1);

namespace WPAiSuite\Knowledge\Ingestion;

/**
 * Bauplan Abschnitt 7/15 (M6, "PDF/FAQ-Ingestion"): "FaqSource/custom_text (manuelle Einträge im
 * Admin)". EINE Klasse fuer beide Quelltypen (kein separates CustomTextSource.php — Abschnitt 2s
 * Ordnerstruktur listet ohnehin nur FaqSource.php), $type steuert getType() und wird 1:1 als
 * RawDocument::$sourceType durchgereicht (siehe FaqEntry-Docblock fuer die title/content-Zuordnung).
 *
 * Bewusst WP-frei (strip_tags() statt wp_strip_all_tags()): anders als WordPressContentSource
 * (verarbeitet rohen post_content von potenziell beliebigen Autoren, Abschnitt 9 will dort
 * moeglichst robuste Bereinigung) kommt der Inhalt hier ausschliesslich von einem eingeloggten
 * manage_options-Admin (DocumentsController::register() Capability-Check) direkt als Text im
 * REST-Body — die zusaetzliche WP-Haerte von wp_strip_all_tags() (Script/Style-Inhalte entfernen,
 * Entity-Decoding) ist an dieser vertrauenswuerdigeren Stelle nicht noetig, der Gewinn dafuer:
 * FaqSource ist komplett WP-Bootstrap-frei unit-testbar (siehe
 * tests/Unit/Knowledge/Ingestion/FaqSourceTest.php) statt wie WordPressContentSource nur per
 * (hier ohnehin nicht ausfuehrbarem) Integration-Test.
 */
final class FaqSource implements KnowledgeSourceInterface
{
    /** @param FaqEntry[] $entries */
    public function __construct(
        private readonly string $type,
        private readonly array $entries,
    ) {
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function fetch(): iterable
    {
        foreach ($this->entries as $entry) {
            $content = trim((string) preg_replace('/\s+/', ' ', strip_tags($entry->content)));

            if ($content === '') {
                // Wie WordPressContentSource (M4): ein Eintrag ohne jeden Inhalt landet erst gar
                // nicht als RawDocument im Ingestion-Lauf, statt als "processed" mit 0 Chunks in
                // wpais_documents zu erscheinen — bei einem manuell eingetragenen FAQ/Custom-Text
                // ist ein leerer Inhalt fast immer ein Tippfehler des Admins, keine erwartete
                // Randbedingung wie bei automatisch gescannten WP-Seiten.
                continue;
            }

            yield new RawDocument(
                sourceType: $this->type,
                sourceRef: $entry->ref,
                title: trim($entry->title),
                content: $content,
            );
        }
    }
}
