<?php

declare(strict_types=1);

namespace WPAiSuite\AiCore\Provider\Contract;

final class ChatMessage
{
    /**
     * @param string $role system|user|assistant|tool
     * @param string $toolCallId nur bei role="tool": id des ToolCall, auf den geantwortet wird
     * @param string $name optionaler Teilnehmername (OpenAI-Format) bzw. wird bei Anthropic ignoriert
     * @param ToolCall[] $toolCalls M7: NUR bei role="assistant" gesetzt, wenn diese Nachricht
     *        selbst ein oder mehrere Tool-Aufrufe war (ChatResponse::$toolCalls einer vorherigen
     *        Provider-Antwort, unveraendert zurueck in die Historie gereicht). Noetig, damit ein
     *        nachfolgendes role="tool"-Ergebnis bei BEIDEN Providern korrekt zugeordnet werden
     *        kann: OpenAI erwartet das tool_calls-Array in der assistant-Nachricht selbst wieder
     *        im Request; Anthropic erwartet die urspruenglichen tool_use-Content-Bloecke, auf die
     *        das nachfolgende tool_result per tool_use_id verweist. Ohne dieses Feld wuerde der
     *        zweite Provider-Aufruf im Tool-Loop (Bauplan Abschnitt 8) mit einem fuer den
     *        Provider nicht zuordenbaren Tool-Ergebnis fehlschlagen.
     */
    public function __construct(
        public readonly string $role,
        public readonly string $content,
        public readonly ?string $toolCallId = null,
        public readonly ?string $name = null,
        public readonly array $toolCalls = [],
    ) {
    }
}
