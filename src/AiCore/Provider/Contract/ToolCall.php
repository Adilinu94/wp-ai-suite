<?php

declare(strict_types=1);

namespace WPAiSuite\AiCore\Provider\Contract;

/**
 * Vom Modell angeforderter Tool-Aufruf, wie er in ChatResponse::$toolCalls zurueckgegeben wird.
 */
final class ToolCall
{
    /**
     * @param array<string,mixed> $arguments Bereits JSON-dekodiert (roh vom Provider oft als
     *        JSON-String geliefert — die jeweilige Adapter-Klasse dekodiert vor Ruecklieferung).
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $arguments,
    ) {
    }
}
