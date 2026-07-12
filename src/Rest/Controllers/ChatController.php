<?php

declare(strict_types=1);

namespace WPAiSuite\Rest\Controllers;

use WPAiSuite\AiCore\Conversation\ConversationAccessDeniedException;
use WPAiSuite\AiCore\Conversation\ConversationService;
use WPAiSuite\AiCore\Conversation\Repository\ConversationRepositoryInterface;
use WPAiSuite\AiCore\Prompt\SystemPromptBuilder;
use WPAiSuite\AiCore\Provider\ActiveProviderResolver;
use WPAiSuite\AiCore\Provider\Contract\ProviderException;
use WPAiSuite\AiCore\Provider\NoActiveProviderException;

/**
 * POST /wpais/v1/chat — Bauplan Abschnitt 12. M2-DoD: "funktioniert ohne Frontend (curl/Postman),
 * Streaming via SSE, Nachrichten landen in wpais_messages".
 *
 * Auth (Abschnitt 9): Nonce (CSRF-Schutz, auch fuer anonyme Besucher moeglich) +
 * Session-Token-Bindung (die eigentliche Besitz-Pruefung, siehe ConversationService). BEWUSST
 * KEIN current_user_can()-Capability-Gate: der Chat ist fuer anonyme Website-Besucher gedacht,
 * die WordPress-seitig grundsaetzlich keine Capabilities haben. Capability-Checks aus Abschnitt 9
 * gelten fuer die admin-only-Endpunkte /documents und /settings, nicht fuer /chat.
 *
 * Wichtig: ActiveProviderResolver::resolve() (und damit ConversationService) wird ERST INNERHALB
 * von handle() aufgeloest, NICHT im Konstruktor/bei register(). Wuerde die Aufloesung schon beim
 * Registrieren der REST-Route (rest_api_init, laeuft auf JEDER Anfrage) einen fehlenden/falschen
 * Provider werfen, wuerde das potenziell die gesamte REST-API der Seite brechen statt nur den
 * /chat-Aufruf sauber mit 503 zu beantworten.
 *
 * Echtes SSE innerhalb der WP-REST-API bedeutet: Header/Body manuell senden und danach exit(),
 * statt die Rueckgabe durch WP_REST_Server serialisieren zu lassen (die WP-HTTP-API selbst puffert
 * die komplette Antwort und eignet sich nicht fuer progressives Senden).
 */
final class ChatController
{
    public function __construct(
        private readonly ConversationRepositoryInterface $conversations,
        private readonly SystemPromptBuilder $promptBuilder,
        private readonly ActiveProviderResolver $providerResolver,
    ) {
    }

    public function register(): void
    {
        add_action('rest_api_init', function (): void {
            register_rest_route('wpais/v1', '/chat', [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'handle'],
                'permission_callback' => [$this, 'checkPermission'],
                'args' => [
                    'message' => ['required' => true, 'type' => 'string'],
                    'session_token' => ['required' => false, 'type' => 'string'],
                ],
            ]);
        });
    }

    public function checkPermission(\WP_REST_Request $request): bool
    {
        $nonce = $request->get_header('X-WP-Nonce');

        if (!is_string($nonce) || $nonce === '') {
            $nonce = $request->get_param('_wpnonce');
        }

        return is_string($nonce) && wp_verify_nonce($nonce, 'wp_rest') !== false;
    }

    /** @return \WP_Error|void Nur im Fehlerfall vor Streaming-Beginn; danach wird direkt geschrieben + exit(). */
    public function handle(\WP_REST_Request $request)
    {
        $message = trim((string) $request->get_param('message'));

        if ($message === '') {
            return new \WP_Error('wpais_empty_message', __('Nachricht darf nicht leer sein.', 'wp-ai-suite'), ['status' => 400]);
        }

        try {
            [$provider, $model] = $this->providerResolver->resolve();
        } catch (NoActiveProviderException $e) {
            return new \WP_Error('wpais_no_active_provider', $e->getMessage(), ['status' => 503]);
        }

        $conversationService = new ConversationService($this->conversations, $this->promptBuilder, $provider, $model);

        $sessionTokenParam = $request->get_param('session_token');
        $sessionToken = is_string($sessionTokenParam) && $sessionTokenParam !== '' ? $sessionTokenParam : null;
        $wpUserId = get_current_user_id() ?: null;

        try {
            $conversation = $conversationService->resolveConversation($sessionToken, $wpUserId);
        } catch (ConversationAccessDeniedException $e) {
            return new \WP_Error('wpais_conversation_denied', $e->getMessage(), ['status' => 403]);
        }

        $this->startSse();
        $this->sendSseEvent('conversation', ['session_token' => $conversation->sessionToken]);

        try {
            $result = $conversationService->handleUserMessage(
                $conversation,
                $message,
                function (string $token): void {
                    $this->sendSseEvent('token', ['delta' => $token]);
                },
            );

            $this->sendSseEvent('final', [
                'content' => $result->content,
                'finish_reason' => $result->finishReason,
                'tokens_input' => $result->tokensInput,
                'tokens_output' => $result->tokensOutput,
            ]);
        } catch (ProviderException $e) {
            $this->sendSseEvent('error', ['message' => $e->getMessage()]);
        }

        $this->endSse();
        exit;
    }

    private function startSse(): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        nocache_headers();
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
    }

    /** @param array<string,mixed> $data */
    private function sendSseEvent(string $eventType, array $data): void
    {
        echo 'event: ' . $eventType . "\n";
        echo 'data: ' . json_encode($data, JSON_THROW_ON_ERROR) . "\n\n";

        if (ob_get_level() > 0) {
            ob_flush();
        }

        flush();
    }

    private function endSse(): void
    {
        $this->sendSseEvent('done', []);
    }
}
