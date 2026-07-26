<?php

declare(strict_types=1);

namespace WPAiSuite\Jobs;

use WPAiSuite\AiCore\Provider\ActiveProviderResolver;
use WPAiSuite\AiCore\Provider\NoActiveProviderException;
use WPAiSuite\Knowledge\Chunking\ChunkerInterface;
use WPAiSuite\Knowledge\DocumentIngestionService;
use WPAiSuite\Knowledge\DocumentRepositoryInterface;
use WPAiSuite\Knowledge\Embedding\EmbeddingProviderResolver;
use WPAiSuite\Knowledge\Embedding\EmbeddingService;
use WPAiSuite\Knowledge\Ingestion\RawDocument;
use WPAiSuite\Knowledge\VectorStore\VectorStoreInterface;

/**
 * Umbauplan Post-MVP Punkt 3: Action-Scheduler-Hook-Callback fuer EIN einzelnes Dokument (siehe
 * ActionSchedulerIngestionDispatcher-Docblock fuer die "ein Job pro Dokument"-Entscheidung).
 *
 * Baut DocumentIngestionService bewusst ERST in handle() (nicht im Konstruktor) — genau das
 * gleiche Muster wie in DocumentsController/KnowledgeBasePage: der Provider wird IMMER frisch
 * zum Zeitpunkt der eigentlichen Arbeit aufgeloest, nicht beim Einplanen des Jobs. WordPress baut
 * den Container ohnehin pro Request/Cron-Tick neu auf, insofern macht das hier keinen praktischen
 * Unterschied zu Konstruktor-Injection — Konsistenz mit den anderen beiden Aufrufstellen ist der
 * eigentliche Grund.
 *
 * serialize()/deserialize() bleiben bewusst statische, WP-freie Methoden (reine
 * Array-Umwandlung) — getrennt von register()/handle(), die WordPress brauchen (add_action). So
 * bleibt wenigstens die Payload-Form selbst ohne WP-Bootstrap ueberpruefbar.
 */
final class IngestionJob
{
    public function __construct(
        private readonly DocumentRepositoryInterface $documents,
        private readonly ChunkerInterface $chunker,
        private readonly VectorStoreInterface $vectorStore,
        private readonly ActiveProviderResolver $providerResolver,
        private readonly EmbeddingProviderResolver $embeddingProviderResolver,
    ) {
    }

    public function register(): void
    {
        add_action(ActionSchedulerIngestionDispatcher::HOOK, [$this, 'handle'], 10, 1);
    }

    /** @param array<string,mixed> $payload */
    public function handle(array $payload): void
    {
        try {
            [$provider, ] = $this->providerResolver->resolve();
        } catch (NoActiveProviderException) {
            // Zwischen Einplanen und Ausfuehren des Jobs wurde der Provider-Key entfernt — kann
            // beim naechsten manuellen "Neu indexieren" erneut versucht werden, es gibt hier
            // keinen sinnvollen Empfaenger fuer eine Fehlermeldung (kein REST-Request/Admin-UI in
            // diesem Kontext, siehe DoD: AS-eigene UI-Sichtbarkeit ist "optional").
            return;
        }

        $embeddingProvider = $this->embeddingProviderResolver->resolve() ?? $provider;
        $ingestionService = new DocumentIngestionService(
            $this->documents,
            $this->chunker,
            $this->vectorStore,
            new EmbeddingService($embeddingProvider),
        );

        try {
            $ingestionService->ingestOne(self::deserialize($payload));
        } catch (\Throwable) {
            // ingestOne() hat den Fehler bereits als markFailed() + error_message auf dem
            // Dokument persistiert (siehe DocumentIngestionService::ingestOne()-Docblock) — hier
            // nur abfangen, damit Action Scheduler den Job nicht endlos als "failed" retryt.
        }
    }

    public static function serialize(RawDocument $rawDocument): array
    {
        return [
            'source_type' => $rawDocument->sourceType,
            'source_ref' => $rawDocument->sourceRef,
            'title' => $rawDocument->title,
            'content' => $rawDocument->content,
            'extraction_error' => $rawDocument->extractionError,
        ];
    }

    /** @param array<string,mixed> $payload */
    public static function deserialize(array $payload): RawDocument
    {
        return new RawDocument(
            sourceType: (string) ($payload['source_type'] ?? ''),
            sourceRef: isset($payload['source_ref']) ? (string) $payload['source_ref'] : null,
            title: (string) ($payload['title'] ?? ''),
            content: (string) ($payload['content'] ?? ''),
            extractionError: isset($payload['extraction_error']) ? (string) $payload['extraction_error'] : null,
        );
    }
}
