<?php

declare(strict_types=1);

namespace WPAiSuite\AiCore\Conversation;

use WPAiSuite\AiCore\Conversation\Repository\ConversationRepositoryInterface;
use WPAiSuite\AiCore\Prompt\SystemPromptBuilder;
use WPAiSuite\AiCore\Provider\Contract\AiProviderInterface;
use WPAiSuite\AiCore\Provider\Contract\ChatRequest;
use WPAiSuite\AiCore\Provider\Contract\ToolCall;
use WPAiSuite\Knowledge\RagQueryBuilder;
use WPAiSuite\Knowledge\RagServiceInterface;
use WPAiSuite\Tools\Contract\ToolExecutionContext;
use WPAiSuite\Tools\ToolRegistry;

/**
 * Herzstueck von M2, seit M5 inkl. RAG-Retrieval, seit M7 inkl. Tool-Calling-Loop. Kennt weder
 * wpdb noch WordPress-REST-Mechanik direkt — die stecken hinter
 * ConversationRepositoryInterface/RagServiceInterface bzw. werden von aussen (ChatController)
 * injiziert. Dadurch ist die eigentliche Orchestrierungs-Logik ohne WP-Bootstrap unit-testbar
 * (Abschnitt 14), obwohl sie am Ende von einem WP-REST-Endpunkt aufgerufen wird.
 *
 * $provider und $model sind bewusst PRO INSTANZ fest (nicht pro Aufruf neu aufgeloest) — die
 * Aufloesung "welcher Provider ist gerade aktiv" ist WordPress-Options-Zugriff und passiert daher
 * in Plugin.php/Container (siehe dort), nicht hier. $toolRegistry (M7) wird aus demselben Grund
 * wie $ragService PRO REQUEST in ChatController gebaut (KnowledgeSearchTool braucht den
 * RagService desselben Requests) und hier nur injiziert.
 */
final class ConversationService
{
    /**
     * Sicherheitsnetz gegen einen Tool-Loop, der nie von selbst mit reinem Text antwortet
     * (Bauplan Abschnitt 8 macht dazu keine Vorgabe). 5 erlaubt legitime Mehrfach-Tool-Nutzung
     * in einer Antwort (z.B. erst knowledge_search, dann woocommerce_product_search), der
     * erzwungene letzte Durchlauf (siehe handleUserMessage()) bekommt gar keine Tools mehr
     * angeboten — das Modell MUSS dort mit Text antworten, es gibt keinen Fall, in dem der Loop
     * ohne finale assistant-Nachricht endet.
     */
    private const MAX_TOOL_ITERATIONS = 5;

    public function __construct(
        private readonly ConversationRepositoryInterface $conversations,
        private readonly SystemPromptBuilder $promptBuilder,
        private readonly AiProviderInterface $provider,
        private readonly string $model,
        private readonly RagServiceInterface $ragService,
        private readonly RagQueryBuilder $ragQueryBuilder,
        private readonly ToolRegistry $toolRegistry,
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
     * Persistiert die User-Nachricht, holt RAG-Kontext (M5: Bauplan Abschnitt 15 —
     * "Retrieval laeuft vor Prompt-Bau"; seit Umbauplan Post-MVP Punkt 5 mit einer per
     * RagQueryBuilder aus den letzten Turns angereicherten Query statt nur der aktuellen
     * Nachricht — siehe dessen Docblock), ruft dann den Provider in einer Schleife auf (M7:
     * Bauplan Abschnitt 8 Tool-Loop) — jede Runde, in der der Provider toolCalls zurueckgibt,
     * wird die Tool-Aufruf-Absicht des Modells UND jedes Tool-Ergebnis als eigene Nachricht
     * persistiert (noetig fuer die naechste Runde, siehe ChatMessage::$toolCalls-Docblock), bis
     * entweder reiner Text zurueckkommt oder MAX_TOOL_ITERATIONS erzwingt eine finale Runde ohne
     * Tools. $onToken wird pro Streaming-Chunk JEDER Runde aufgerufen (auch bei Zwischenrunden,
     * die meist keinen sichtbaren Text haben); $onSources (falls gesetzt) wird EINMALIG
     * aufgerufen, sobald das AUTOMATISCHE M5-Retrieval abgeschlossen ist — also vor der ersten
     * Provider-Runde. Diese Methode selbst weiss nichts von SSE/HTTP.
     *
     * @param callable(string): void $onToken
     * @param null|callable(\WPAiSuite\Knowledge\RetrievedSource[]): void $onSources
     */
    public function handleUserMessage(Conversation $conversation, string $userMessage, callable $onToken, ?callable $onSources = null): ChatCompletionResult
    {
        // Umbauplan Post-MVP Punkt 5: Historie VOR dem Anhaengen der aktuellen Nachricht holen,
        // damit RagQueryBuilder sauber zwischen "bisherige Turns" und "aktuelle Nachricht"
        // unterscheiden kann (siehe dessen Docblock) statt den letzten Eintrag der Historie
        // wieder herausfiltern zu muessen.
        $priorHistory = $this->conversations->getMessages($conversation->id);
        $this->conversations->appendMessage($conversation->id, new StoredMessage(role: 'user', content: $userMessage));

        $retrievalQuery = $this->ragQueryBuilder->fromHistory($priorHistory, $userMessage);
        $retrieval = $this->ragService->retrieve($retrievalQuery);

        if ($onSources !== null) {
            $onSources($retrieval->sources);
        }

        $toolContext = new ToolExecutionContext(
            isLoggedIn: $conversation->wpUserId !== null,
            wpUserId: $conversation->wpUserId,
        );
        $toolDefinitions = $this->toolRegistry->definitionsFor($toolContext);

        $totalTokensInput = 0;
        $totalTokensOutput = 0;
        $response = null;

        for ($iteration = 0; $iteration <= self::MAX_TOOL_ITERATIONS; $iteration++) {
            $history = $this->conversations->getMessages($conversation->id);
            // Der RAG-Kontext aus dem automatischen Retrieval oben gehoert nur in die ALLERERSTE
            // Runde in den System-Prompt (M5-Verhalten unveraendert) — Folgerunden innerhalb
            // desselben Tool-Loops bekommen ihn nicht nochmal; ihre "frische" Information kommt
            // stattdessen ueber die role="tool"-Ergebnisse, die schon Teil von $history sind.
            $messages = $this->promptBuilder->buildMessages($history, $iteration === 0 ? $retrieval->contextText : '');

            $isForcedFinalIteration = $iteration === self::MAX_TOOL_ITERATIONS;
            $chatRequest = new ChatRequest(
                messages: $messages,
                model: $this->model,
                tools: $isForcedFinalIteration ? [] : $toolDefinitions,
            );

            $response = $this->provider->chatStream($chatRequest, $onToken);
            $totalTokensInput += $response->tokensInput;
            $totalTokensOutput += $response->tokensOutput;

            if ($response->toolCalls === [] || $isForcedFinalIteration) {
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

                break;
            }

            $this->conversations->appendMessage($conversation->id, new StoredMessage(
                role: 'assistant',
                content: $response->content,
                provider: $this->provider->getKey(),
                model: $this->model,
                tokensInput: $response->tokensInput,
                tokensOutput: $response->tokensOutput,
                toolCalls: array_map(
                    static fn (ToolCall $tc): array => ['id' => $tc->id, 'name' => $tc->name, 'arguments' => $tc->arguments],
                    $response->toolCalls,
                ),
            ));
            $this->conversations->logUsage(
                $conversation->id,
                $this->provider->getKey(),
                $this->model,
                $response->tokensInput,
                $response->tokensOutput,
            );

            foreach ($response->toolCalls as $toolCall) {
                $result = $this->toolRegistry->execute($toolCall->name, $toolCall->arguments, $toolContext);

                $this->conversations->appendMessage($conversation->id, new StoredMessage(
                    role: 'tool',
                    content: $result->toModelContent(),
                    toolCallId: $toolCall->id,
                ));
            }
        }

        return new ChatCompletionResult(
            content: $response->content,
            finishReason: $response->finishReason,
            tokensInput: $totalTokensInput,
            tokensOutput: $totalTokensOutput,
            sources: $retrieval->sources,
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
