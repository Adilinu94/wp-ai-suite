<?php

declare(strict_types=1);

namespace WPAiSuite\AiCore\Provider\Contract;

/**
 * Providerunabhaengige Beschreibung eines aufrufbaren Tools fuer Function-Calling. Bewusst kein
 * Verweis auf WPAiSuite\Tools\Contract\ToolInterface (Tools-Modul) — der Provider Layer kennt nur
 * dieses schlanke DTO, nicht das Tools-Modul selbst (Ports & Adapters, Bauplan Abschnitt 1).
 * WPAiSuite\Tools\ToolRegistry (spaetere Milestones) uebersetzt ToolInterface -> ToolDefinition.
 */
final class ToolDefinition
{
    /**
     * @param array<string,mixed> $parameterSchema JSON-Schema-artiges Array, siehe
     *        ToolInterface::getParameterSchema() (Bauplan Abschnitt 8) — geht 1:1 an
     *        Provider-Function-Calling und spaeter an MCP.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $parameterSchema,
    ) {
    }
}
