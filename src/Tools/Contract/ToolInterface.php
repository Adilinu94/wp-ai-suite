<?php

declare(strict_types=1);

namespace WPAiSuite\Tools\Contract;

/**
 * Bauplan Abschnitt 5/8. Einer der vier in Abschnitt 5 benannten Kern-Contracts
 * (AiProviderInterface, VectorStoreInterface, ToolInterface, ConversationRepositoryInterface).
 * ToolRegistry (M7) uebersetzt eine Sammlung von ToolInterface-Implementierungen in
 * WPAiSuite\AiCore\Provider\Contract\ToolDefinition[] fuer den Provider Layer — der Provider Layer
 * kennt dieses Interface bewusst nicht (siehe ToolDefinition-Docblock, Ports & Adapters).
 */
interface ToolInterface
{
    /** Eindeutiger Name — wird 1:1 zum MCP-Tool-Namen in Phase 2. */
    public function getName(): string;

    public function getDescription(): string;

    /**
     * JSON-Schema — geht 1:1 an Provider-Function-Calling UND spaeter an MCP.
     *
     * @return array<string,mixed>
     */
    public function getParameterSchema(): array;

    /** @param array<string,mixed> $arguments */
    public function execute(array $arguments): ToolResult;

    /** Rechte-Check: darf dieser Aufruf-Kontext das Tool nutzen? */
    public function isAllowedFor(ToolExecutionContext $context): bool;
}
