<?php

declare(strict_types=1);

namespace WPAiSuite\Tools\Contract;

/**
 * Aufruf-Kontext fuer ToolInterface::isAllowedFor() (Bauplan Abschnitt 8). Phase 1 nutzt ihn nur
 * rudimentaer (angemeldet/nicht angemeldet, siehe $isLoggedIn) — Phase 2 erweitert ihn laut
 * Bauplan um MCP-Client-Identitaet, wenn dieselbe ToolRegistry von einem
 * Mcp\McpServerAdapter nach aussen exponiert wird, OHNE den ToolInterface-Vertrag selbst zu
 * brechen (deshalb jetzt schon als eigenes Objekt statt z.B. eines rohen `?int $wpUserId`
 * Parameters direkt auf isAllowedFor()).
 */
final class ToolExecutionContext
{
    public function __construct(
        public readonly bool $isLoggedIn,
        public readonly ?int $wpUserId = null,
    ) {
    }
}
