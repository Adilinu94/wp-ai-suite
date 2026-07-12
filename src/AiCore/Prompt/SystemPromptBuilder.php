<?php

declare(strict_types=1);

namespace WPAiSuite\AiCore\Prompt;

use WPAiSuite\AiCore\Conversation\StoredMessage;
use WPAiSuite\AiCore\Provider\Contract\ChatMessage;

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

    public function __construct(
        private readonly string $configuredSystemPrompt = '',
    ) {
    }

    /**
     * @param StoredMessage[] $history Bisherige Nachrichten dieser Konversation, aelteste zuerst,
     *        INKLUSIVE der neuen User-Nachricht (der Aufrufer persistiert sie vor diesem Aufruf).
     * @return ChatMessage[]
     */
    public function buildMessages(array $history): array
    {
        $messages = [new ChatMessage(role: 'system', content: $this->currentSystemPrompt())];

        foreach ($history as $stored) {
            // Defensive Rollentrennung: koennte regulaer nicht vorkommen (StoredMessage
            // repraesentiert nur user/assistant, solange Tool-Calling noch nicht verdrahtet ist -
            // das ist M7), schuetzt aber davor, dass gespeicherte Inhalte je als System-Rolle
            // re-injiziert werden.
            $role = $stored->role === 'system' ? 'user' : $stored->role;
            $messages[] = new ChatMessage(role: $role, content: $stored->content);
        }

        return $messages;
    }

    private function currentSystemPrompt(): string
    {
        return trim($this->configuredSystemPrompt) !== '' ? $this->configuredSystemPrompt : self::DEFAULT_SYSTEM_PROMPT;
    }
}
