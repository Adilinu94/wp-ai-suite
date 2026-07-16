<?php

declare(strict_types=1);

namespace WPAiSuite\Tools;

use WPAiSuite\AiCore\Provider\Contract\ToolDefinition;
use WPAiSuite\Tools\Contract\ToolExecutionContext;
use WPAiSuite\Tools\Contract\ToolInterface;
use WPAiSuite\Tools\Contract\ToolResult;

/**
 * Bauplan Abschnitt 8: "ToolRegistry sammelt alle registrierten Tools und reicht sie als
 * ToolDefinition[] an den aktiven Provider weiter." Genau diese Registry wird laut Bauplan in
 * Phase 2 unveraendert von einem Mcp\McpServerAdapter nach aussen exponiert — deshalb hier schon
 * eine stabile, kleine oeffentliche Flaeche (definitionsFor()/execute()) statt z.B. direktem
 * Array-Zugriff auf die Tools von aussen.
 *
 * Bewusst KEIN eigenes Interface (anders als ToolInterface selbst): ToolRegistry ist
 * Orchestrierung, kein austauschbarer Adapter — analog zu DocumentIngestionService (M4) und
 * EmbeddingService (M4), die aus demselben Grund ebenfalls konkrete Klassen sind.
 *
 * Wird PRO REQUEST frisch gebaut (in ChatController::handle(), nicht im Container), weil
 * KnowledgeSearchTool einen RagServiceInterface braucht, der seinerseits erst mit dem gerade
 * aufgeloesten aktiven Provider gebaut werden kann — derselbe Grund, aus dem RagService/
 * ConversationService selbst schon seit M5 pro Request gebaut werden (siehe deren Docblocks).
 */
final class ToolRegistry
{
    /** @var array<string, ToolInterface> */
    private array $toolsByName = [];

    /** @param ToolInterface[] $tools */
    public function __construct(array $tools)
    {
        foreach ($tools as $tool) {
            $this->toolsByName[$tool->getName()] = $tool;
        }
    }

    /** @return ToolDefinition[] Nur Tools, fuer die isAllowedFor($context) zutrifft. */
    public function definitionsFor(ToolExecutionContext $context): array
    {
        $definitions = [];

        foreach ($this->toolsByName as $tool) {
            if (!$tool->isAllowedFor($context)) {
                continue;
            }

            $definitions[] = new ToolDefinition($tool->getName(), $tool->getDescription(), $tool->getParameterSchema());
        }

        return $definitions;
    }

    /**
     * Wird NIE eine Exception werfen (auch bei unbekanntem/nicht erlaubtem Tool-Namen) — ein vom
     * Modell angefordertes, aber ungueltiges Tool ist fuer den Tool-Loop in ConversationService
     * kein Abbruchgrund, sondern selbst wieder ein ganz normales (fehlgeschlagenes) Tool-Ergebnis,
     * das an das Modell zurueckgeht (siehe ToolResult-Docblock).
     *
     * @param array<string,mixed> $arguments
     */
    public function execute(string $name, array $arguments, ToolExecutionContext $context): ToolResult
    {
        $tool = $this->toolsByName[$name] ?? null;

        if ($tool === null) {
            return new ToolResult(success: false, error: sprintf('Unbekanntes Tool: "%s".', $name));
        }

        if (!$tool->isAllowedFor($context)) {
            return new ToolResult(success: false, error: sprintf('Kein Zugriff auf Tool "%s" in diesem Kontext.', $name));
        }

        return $tool->execute($arguments);
    }
}
