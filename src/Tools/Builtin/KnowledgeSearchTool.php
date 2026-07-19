<?php

declare(strict_types=1);

namespace WPAiSuite\Tools\Builtin;

use WPAiSuite\Knowledge\RagServiceInterface;
use WPAiSuite\Tools\Contract\ToolExecutionContext;
use WPAiSuite\Tools\Contract\ToolInterface;
use WPAiSuite\Tools\Contract\ToolResult;

/**
 * Bauplan Abschnitt 8: "KnowledgeSearchTool { ruft RagService::retrieve() }". Ergaenzt das
 * automatische Retrieval aus M5 (das IMMER vor dem ersten Provider-Aufruf laeuft, siehe
 * ConversationService::handleUserMessage()) um einen Weg, den das Modell selbst GEZIELT waehrend
 * eines laufenden Tool-Loops erneut aufrufen kann — z.B. bei einer praezisierten Rueckfrage
 * mitten in der Konversation, fuer die die urspruengliche automatische Retrieval-Query (die rohe
 * erste User-Nachricht, siehe dortiger Docblock/offene Punkte) nicht mehr gut passt.
 *
 * WP-Bootstrap-frei: haengt nur von RagServiceInterface ab (seit M5 selbst schon WP-frei), dadurch
 * unit-testbar mit einem Fake statt einer echten Wissensbasis (anders als
 * WooCommerceProductSearchTool).
 */
final class KnowledgeSearchTool implements ToolInterface
{
    public function __construct(
        private readonly RagServiceInterface $ragService,
    ) {
    }

    public function getName(): string
    {
        return 'knowledge_search';
    }

    public function getDescription(): string
    {
        return 'Durchsucht die Wissensbasis dieser Website (Seiten, Beitraege, PDFs, FAQ-Eintraege) '
            . 'nach Informationen zu einer bestimmten Frage oder einem Suchbegriff.';
    }

    public function getParameterSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Die Suchanfrage oder Frage, zu der passende Informationen gesucht werden sollen.',
                ],
            ],
            'required' => ['query'],
        ];
    }

    public function execute(array $arguments): ToolResult
    {
        $query = trim((string) ($arguments['query'] ?? ''));

        if ($query === '') {
            return new ToolResult(success: false, error: 'Parameter "query" fehlt oder ist leer.');
        }

        $result = $this->ragService->retrieve($query);

        if ($result->contextText === '') {
            return new ToolResult(success: true, data: [
                'found' => false,
                'message' => 'Keine passenden Informationen in der Wissensbasis gefunden.',
            ]);
        }

        return new ToolResult(success: true, data: [
            'found' => true,
            'context' => $result->contextText,
            'sources' => array_map(
                static fn ($source): array => ['title' => $source->title],
                $result->sources,
            ),
        ]);
    }

    public function isAllowedFor(ToolExecutionContext $context): bool
    {
        // Oeffentlich wie der Chat-Endpunkt selbst (Bauplan Abschnitt 9/ChatController-Docblock:
        // "der Chat ist fuer anonyme Website-Besucher gedacht") — dieselbe Wissensbasis, die auch
        // das automatische M5-Retrieval fuer jeden Besucher durchsucht, keine zusaetzliche
        // Einschraenkung noetig.
        return true;
    }
}
