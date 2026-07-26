<?php

declare(strict_types=1);

namespace WPAiSuite\Jobs;

use WPAiSuite\Knowledge\Ingestion\KnowledgeSourceInterface;

/**
 * Umbauplan Post-MVP Punkt 3: Port zwischen den Aufrufstellen (DocumentsController,
 * KnowledgeBasePage) und der Frage "synchron im Request verarbeiten oder als Hintergrund-Job
 * einplanen" — DocumentIngestionService selbst bleibt unveraendert WP-frei und weiss nichts von
 * dieser Entscheidung (Bauplan-Prinzip: Core kennt kein WordPress, nur Ports).
 */
interface IngestionDispatcherInterface
{
    public function dispatch(KnowledgeSourceInterface $source): IngestionDispatchResult;
}
