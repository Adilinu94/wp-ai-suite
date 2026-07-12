<?php

declare(strict_types=1);

namespace WPAiSuite\AiCore\Conversation;

/**
 * Ergebnis eines abgeschlossenen Konversations-Turns, wie es ChatController nach aussen gibt
 * (finales SSE-Event bzw. Rueckgabewert). Bewusst eine eigene, kleine Klasse statt direkt
 * AiCore\Provider\Contract\ChatResponse durchzureichen — der Rest-Controller soll nicht vom
 * Provider-Contract-Namespace abhaengen muessen.
 */
final class ChatCompletionResult
{
    public function __construct(
        public readonly string $content,
        public readonly string $finishReason,
        public readonly int $tokensInput,
        public readonly int $tokensOutput,
    ) {
    }
}
