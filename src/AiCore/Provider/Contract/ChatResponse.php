<?php

declare(strict_types=1);

namespace WPAiSuite\AiCore\Provider\Contract;

final class ChatResponse
{
    /**
     * @param ToolCall[] $toolCalls
     */
    public function __construct(
        public readonly string $content,
        public readonly int $tokensInput,
        public readonly int $tokensOutput,
        public readonly array $toolCalls = [],
        public readonly string $finishReason = 'stop',
    ) {
    }
}
