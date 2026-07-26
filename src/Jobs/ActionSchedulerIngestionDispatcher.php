<?php

declare(strict_types=1);

namespace WPAiSuite\Jobs;

use WPAiSuite\Knowledge\DocumentIngestionService;
use WPAiSuite\Knowledge\Ingestion\KnowledgeSourceInterface;

/**
 * Umbauplan Post-MVP Punkt 3: verarbeitet eine Quelle synchron, wenn sie klein genug ist ODER
 * Action Scheduler nicht verfuegbar ist (DoD: "ohne AS: degradieren auf Sync") — sonst wird jedes
 * einzelne Dokument als eigener Hintergrund-Job eingeplant statt alle in einem einzigen,
 * potenziell zeitaufwaendigen Job zu buendeln (siehe Risiko-Abschnitt: "ggf. pro Dokument ein
 * Job").
 *
 * Bewusste Vereinfachung, transparent dokumentiert statt stillschweigend: $source->fetch() wird
 * IMMER synchron in diesem Request aufgerufen, auch wenn asynchron eingeplant wird — verzoegert
 * wird nur die Chunking/Embedding-Stufe (ingestOne(), der eigentliche Netzwerk-Flaschenhals),
 * nicht das Einlesen der Quelle selbst. Fuer WordPressContentSource/FaqSource ist das Einlesen
 * ohnehin schnell (WP_Query/reiner Text); bei PdfSource wuerde ein echtes Deferring der
 * PDF-Extraktion selbst bedeuten, gar keine RawDocuments mehr vor der Einplanung zu erzeugen,
 * sondern nur Attachment-IDs — das explizit getestete DoD-Szenario ("≥50 WP-Seiten") betrifft
 * WordPressContentSource, nicht PDF, daher hier bewusst nicht mitgebaut (PDF-Batches sind in der
 * Praxis deutlich kleiner als 50 Dateien pro Upload).
 *
 * Kein woocommerce/action-scheduler als eigene composer.json-Abhaengigkeit hinzugefuegt: prueft
 * stattdessen nur, ob die (globalen) Action-Scheduler-Funktionen zur Laufzeit existieren — deckt
 * sowohl eine separate AS-Installation als auch die von WooCommerce mitgelieferte Kopie ab, ohne
 * eine Doppel-Dependency zu riskieren (Risiko-Abschnitt: "Namespace/Strauss beachten").
 */
final class ActionSchedulerIngestionDispatcher implements IngestionDispatcherInterface
{
    public const HOOK = 'wpais_ingest_source';
    public const GROUP = 'wpais';

    public function __construct(
        private readonly DocumentIngestionService $ingestionService,
        private readonly int $syncMaxDocs,
    ) {
    }

    public static function isAvailable(): bool
    {
        return function_exists('as_schedule_single_action');
    }

    public function dispatch(KnowledgeSourceInterface $source): IngestionDispatchResult
    {
        $rawDocuments = iterator_to_array($source->fetch(), false);

        if (count($rawDocuments) <= $this->syncMaxDocs || !self::isAvailable()) {
            return new IngestionDispatchResult(queued: 0, summary: $this->ingestionService->ingestMany($rawDocuments));
        }

        $queued = 0;

        foreach ($rawDocuments as $rawDocument) {
            as_schedule_single_action(
                time(),
                self::HOOK,
                ['payload' => IngestionJob::serialize($rawDocument)],
                self::GROUP,
            );
            $queued++;
        }

        return new IngestionDispatchResult(queued: $queued, summary: new \WPAiSuite\Knowledge\IngestionSummary(0, 0, 0, []));
    }
}
