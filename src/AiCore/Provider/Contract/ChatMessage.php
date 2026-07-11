<?php

declare(strict_types=1);

namespace WPAiSuite\AiCore\Provider\Contract;

final class ChatMessage
{
    /**
     * @param string $role system|user|assistant|tool
     * @param string $toolCallId nur bei role="tool": id des ToolCall, auf den geantwortet wird
     * @param string $name optionaler Teilnehmername (OpenAI-Format) bzw. wird bei Anthropic ignoriert
     */
    public function __construct(
        public readonly string $role,
        public readonly string $content,
        public readonly ?string $toolCallId = null,
        public readonly ?string $name = null,
    ) {
    }
}
