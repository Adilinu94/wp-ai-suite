<?php

declare(strict_types=1);

namespace WPAiSuite\AiCore\Provider\Contract;

final class ChatRequest
{
    /**
     * @param ChatMessage[] $messages
     * @param ToolDefinition[] $tools
     */
    public function __construct(
        public readonly array $messages,
        public readonly string $model,
        public readonly float $temperature = 0.7,
        public readonly int $maxTokens = 1024,
        public readonly array $tools = [],
    ) {
    }
}
