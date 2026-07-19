<?php

declare(strict_types=1);

namespace WPAiSuite\AiCore\Prompt;

use WPAiSuite\AiCore\Conversation\StoredMessage;
use WPAiSuite\AiCore\Provider\Contract\ChatMessage;
use WPAiSuite\AiCore\Provider\Contract\ToolCall;

/**
 * Baut die Nachrichtenliste fuer ChatRequest aus System-Prompt + Konversationshistorie.
 * Bewusst ohne get_option()-Aufruf: der konfigurierte System-Prompt kommt als String in den
 * Konstruktor (aufgeloest von Plugin.php/Container, dem WP-Beruehrungspunkt) — dadurch ist diese
 * Klasse ohne WP-Bootstrap unit-testbar (Abschnitt 14: "Unit ... testet ... Prompt-Building").
 *
 * Rollentrennung ist bindend (Abschnitt 9, PromptGuard-Grundprinzip): eine als "system"
 * gespeicherte historische Nachricht wuerde hier NIE erneut als System-Rolle eingespeist — kann
 * regulaer gar nicht vorkommen (StoredMessage speichert nur user/assistant/tool), ist hier aber
 * defensiv trotzdem abgesichert.
 */
final class SystemPromptBuilder
{
    public const DEFAULT_SYSTEM_PROMPT = 'Du bist ein hilfreicher Assistent auf dieser Website. '
        . 'Antworte freundlich, praezise und auf Deutsch, sofern der Nutzer erkennbar eine andere Sprache verwendet.';

    private const RETRIEVED_CONTEXT_HEADER = 'Nutze die folgenden Auszuege aus der Wissensbasis, sofern sie '
        . 'relevant sind, um die Frage zu beantworten. Sind sie nicht relevant, ignoriere sie und antworte aus '
        . 'eigenem Wissen:';

    public function __construct(
        private readonly string $configuredSystemPrompt = '',
    ) {
    }

    /**
     * @param StoredMessage[] $history Bisherige Nachrichten dieser Konversation, aelteste zuerst,
     *        INKLUSIVE der neuen User-Nachricht (der Aufrufer persistiert sie vor diesem Aufruf).
     * @param string $retrievedContext M5: Ergebnis von RagServiceInterface::retrieve()->contextText.
     *        Bauplan Abschnitt 7: "danach direkt in den System-Prompt injiziert — kein Re-Ranking,
     *        keine Hybrid-Search". Leerer String (Default) = kein RAG-Kontext, Prompt unveraendert
     *        wie vor M5.
     * @return ChatMessage[]
     */
    public function buildMessages(array $history, string $retrievedContext = ''): array
    {
        $messages = [new ChatMessage(role: 'system', content: $this->currentSystemPrompt($retrievedContext))];

        foreach ($history as $stored) {
            // Defensive Rollentrennung: koennte regulaer nicht vorkommen (StoredMessage
            // repraesentiert nur user/assistant/tool), schuetzt aber davor, dass gespeicherte
            // Inhalte je als System-Rolle re-injiziert werden.
            $role = $stored->role === 'system' ? 'user' : $stored->role;
            $messages[] = new ChatMessage(
                role: $role,
                content: $stored->content,
                toolCallId: $stored->toolCallId,
                toolCalls: array_map(
                    static fn (array $tc): ToolCall => new ToolCall(
                        (string) ($tc['id'] ?? ''),
                        (string) ($tc['name'] ?? ''),
                        (array) ($tc['arguments'] ?? []),
                    ),
                    $stored->toolCalls,
                ),
            );
        }

        return $messages;
    }

    private function currentSystemPrompt(string $retrievedContext): string
    {
        $base = trim($this->configuredSystemPrompt) !== '' ? $this->configuredSystemPrompt : self::DEFAULT_SYSTEM_PROMPT;

        if (trim($retrievedContext) === '') {
            return $base;
        }

        return $base . "\n\n" . self::RETRIEVED_CONTEXT_HEADER . "\n" . $retrievedContext;
    }
}
