<?php

declare(strict_types=1);

namespace WPAiSuite\AiCore\Conversation;

use WPAiSuite\AiCore\Conversation\Repository\ConversationRepositoryInterface;
use WPAiSuite\AiCore\Prompt\SystemPromptBuilder;
use WPAiSuite\AiCore\Provider\Contract\AiProviderInterface;
use WPAiSuite\AiCore\Provider\Contract\ChatRequest;

/**
 * Herzstueck von M2. Kennt weder wpdb noch WordPress-REST-Mechanik direkt — beide stecken hinter
 * ConversationRepositoryInterface bzw. werden von aussen (ChatController) injiziert. Dadurch ist
 * die eigentliche Orchestrierungs-Logik ohne WP-Bootstrap unit-testbar (Abschnitt 14), obwohl sie
 * am Ende von einem WP-REST-Endpunkt aufgerufen wird.
 *
 * $provider und $model sind bewusst PRO INSTANZ fest (nicht pro Aufruf neu aufgeloest) — die
 * Aufloesung "welcher Provider ist gerade aktiv" ist WordPress-Options-Zugriff und passiert daher
 * in Plugin.php/Container (siehe dort), nicht hier.
 */
final class ConversationService
{
    public function __construct(
        private readonly ConversationRepositoryInterface $conversations,
        private readonly SystemPromptBuilder $promptBuilder,
        private readonly AiProviderInterface $provider,
        private readonly string $model,
    ) {
    }

    /**
     * Session-Tokens werden bei Neuanlage IMMER server-seitig frisch generiert — auch wenn der
     * Client bereits einen (unbekannten/abgelaufenen) $sessionToken mitgeschickt hat. Ein Client
     * darf sein eigenes Konversations-Token nie selbst bestimmen (sonst waeren vorhersagbare/
     * kollidierende Tokens moeglich); der zurueckgegebene Conversation::$sessionToken ist die
     * einzige gueltige Referenz fuer Folge-Requests.
     *
     * @throws ConversationAccessDeniedException Wenn $sessionToken zu einer Konversation eines
     *         anderen eingeloggten Nutzers gehoert.
     */
    public function resolveConversation(?string $sessionToken, ?int $wpUserId, string $channel = 'website'): Conversation
    {
        if ($sessionToken !== null) {
            $existing = $this->conversations->findByToken($sessionToken);

            if ($existing !== null) {
                if ($existing->wpUserId !== null && $existing->wpUserId !== $wpUserId) {
                    throw new ConversationAccessDeniedException(
                        'Diese Konversation gehoert zu einem anderen angemeldeten Nutzer.',
                    );
                }

                return $existing;
            }
        }

        return $this->conversations->create(
            sessionToken: $this->generateSessionToken(),
            wpUserId: $wpUserId,
            channel: $channel,
        );
    }

    /** @return StoredMessage[] */
    public function getHistory(Conversation $conversation): array
    {
        return $this->conversations->getMessages($conversation->id);
    }

    /**
     * Persistiert die User-Nachricht, ruft den Provider (streamend) auf, persistiert die
     * Assistant-Antwort + Usage-Log. $onToken wird pro Streaming-Chunk aufgerufen (z.B. um es als
     * SSE-Event auszugeben) — diese Methode selbst weiss nichts von SSE/HTTP.
     *
     * @param callable(string): void $onToken
     */
    public function handleUserMessage(Conversation $conversation, string $userMessage, callable $onToken): ChatCompletionResult
    {
        $this->conversations->appendMessage($conversation->id, new StoredMessage(role: 'user', content: $userMessage));

        $history = $this->conversations->getMessages($conversation->id);
        $chatRequest = new ChatRequest(messages: $this->promptBuilder->buildMessages($history), model: $this->model);

        $response = $this->provider->chatStream($chatRequest, $onToken);

        $this->conversations->appendMessage($conversation->id, new StoredMessage(
            role: 'assistant',
            content: $response->content,
            provider: $this->provider->getKey(),
            model: $this->model,
            tokensInput: $response->tokensInput,
            tokensOutput: $response->tokensOutput,
        ));

        $this->conversations->logUsage(
            $conversation->id,
            $this->provider->getKey(),
            $this->model,
            $response->tokensInput,
            $response->tokensOutput,
        );

        return new ChatCompletionResult(
            content: $response->content,
            finishReason: $response->finishReason,
            tokensInput: $response->tokensInput,
            tokensOutput: $response->tokensOutput,
        );
    }

    private function generateSessionToken(): string
    {
        if (function_exists('wp_generate_uuid4')) {
            return wp_generate_uuid4();
        }

        // Fallback nur relevant in Nicht-WP-Kontexten (z.B. Unit-Tests ohne WP-Bootstrap).
        return bin2hex(random_bytes(16));
    }
}
