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
use WPAiSuite\Knowledge\DocumentRepositoryInterface;
use WPAiSuite\Knowledge\Embedding\EmbeddingProviderResolver;
use WPAiSuite\Knowledge\Embedding\EmbeddingService;
use WPAiSuite\Knowledge\RagService;
use WPAiSuite\Knowledge\RetrievedSource;
use WPAiSuite\Knowledge\VectorStore\VectorStoreInterface;
use WPAiSuite\Security\ClientIpResolver;
use WPAiSuite\Security\PromptGuard;
use WPAiSuite\Security\RateLimiter;
use WPAiSuite\Tools\Builtin\KnowledgeSearchTool;
use WPAiSuite\Tools\Builtin\WooCommerceProductSearchTool;
use WPAiSuite\Tools\ToolRegistry;

/**
 * POST /wpais/v1/chat — Bauplan Abschnitt 12. M2-DoD: "funktioniert ohne Frontend (curl/Postman),
 * Streaming via SSE, Nachrichten landen in wpais_messages". Seit M5 zusaetzlich: RAG-Retrieval
 * vor dem Prompt-Bau, Quellen als eigenes "sources"-SSE-Event VOR dem ersten "token"-Event (siehe
 * ConversationService::handleUserMessage()-Docblock — Retrieval laeuft vor dem Provider-Aufruf).
 *
 * RagService wird — wie ConversationService selbst — ERST INNERHALB von handle() gebaut, mit dem
 * dort frisch aufgeloesten Provider (derselbe Provider fuer Chat UND Embedding, Abschnitt 7:
 * "ueber den aktiven Provider"). Ein im Container vorgefertigtes RagService waere zwangslaeufig
 * an EINEN zum Wiring-Zeitpunkt noch unbekannten Provider gebunden.
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
 *
 * M7 (Tool Engine): der eigentliche Tool-Aufruf-Loop steckt vollstaendig in
 * ConversationService::handleUserMessage() — dieser Controller baut nur die ToolRegistry (analog
 * zu RagService: pro Request, siehe unten) und reicht sie durch, weiss selbst nichts von
 * Tool-Aufrufen.
 *
 * M9 (Security-Haertung): zwei zusaetzliche Pruefungen VOR startSse() (dieselbe Begruendung wie
 * beim Provider-503 oben — ein sauberer WP_Error statt eines kaputten SSE-Streams). RateLimiter
 * schluesselt bevorzugt ueber session_token (stabil ueber die ganze Konversation), faellt fuer
 * den allerersten Request einer neuen Konversation (noch kein Token vom Client) auf die
 * anfragende IP zurueck — die IP wird dabei NICHT gespeichert, nur kurzlebig als Cache-Schluessel
 * verwendet (siehe RateLimiter-Docblock). PromptGuard prueft NUR den Nachrichtentext, ist eine
 * zusaetzliche Filterschicht, keine Voraussetzung fuer die eigentliche Sicherheit (siehe dortiger
 * Docblock).
 */
final class ChatController
{
    public function __construct(
        private readonly ConversationRepositoryInterface $conversations,
        private readonly SystemPromptBuilder $promptBuilder,
        private readonly ActiveProviderResolver $providerResolver,
        private readonly VectorStoreInterface $vectorStore,
        private readonly DocumentRepositoryInterface $documents,
        private readonly RateLimiter $rateLimiter,
        private readonly PromptGuard $promptGuard,
        private readonly EmbeddingProviderResolver $embeddingProviderResolver,
        private readonly ClientIpResolver $clientIpResolver,
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

        $sessionTokenParam = $request->get_param('session_token');
        $sessionToken = is_string($sessionTokenParam) && $sessionTokenParam !== '' ? $sessionTokenParam : null;
        // Umbauplan Post-MVP Punkt 7: Session-Token bleibt primaerer Key (unveraendert). Der
        // IP-Fallback laeuft jetzt ueber ClientIpResolver statt rohem $_SERVER['REMOTE_ADDR'] —
        // siehe dessen Docblock fuer die Proxy-Trust-Logik.
        $rateLimitKey = $sessionToken ?? ('ip:' . $this->clientIpResolver->resolve(
            $_SERVER,
            (bool) get_option('wpais_trust_proxy', false),
            $this->trustedProxiesList(),
        ));

        if (!$this->rateLimiter->attempt($rateLimitKey)) {
            return new \WP_Error(
                'wpais_rate_limited',
                __('Zu viele Nachrichten in kurzer Zeit. Bitte kurz warten und erneut versuchen.', 'wp-ai-suite'),
                ['status' => 429],
            );
        }

        if ($this->promptGuard->isSuspicious($message)) {
            // Bewusst keine Details darueber, WAS erkannt wurde (siehe PromptGuard-Docblock) —
            // eine generische Ablehnung verraet einem Angreifer nicht, welches Muster gegriffen
            // hat und wie es sich umgehen liesse.
            return new \WP_Error(
                'wpais_message_rejected',
                __('Deine Nachricht konnte nicht verarbeitet werden. Bitte formuliere sie anders.', 'wp-ai-suite'),
                ['status' => 400],
            );
        }

        try {
            [$provider, $model] = $this->providerResolver->resolve();
        } catch (NoActiveProviderException $e) {
            return new \WP_Error('wpais_no_active_provider', $e->getMessage(), ['status' => 503]);
        }

        // Umbauplan Post-MVP Punkt 1: eigenstaendig konfigurierter Embedding-Provider hat
        // Vorrang, faellt aber auf den bereits aufgeloesten Chat-Provider zurueck, wenn keiner
        // eingerichtet ist — siehe EmbeddingProviderResolver-Docblock.
        $embeddingProvider = $this->embeddingProviderResolver->resolve() ?? $provider;
        $ragService = new RagService($this->vectorStore, new EmbeddingService($embeddingProvider), $this->documents);

        // M7: KnowledgeSearchTool braucht denselben $ragService wie das automatische M5-
        // Retrieval oben (bzw. wird gleich unten aufgerufen) — deshalb hier, nicht im
        // Container, gebaut (siehe ToolRegistry-Docblock). WooCommerceProductSearchTool nur
        // anbieten, wenn WooCommerce ueberhaupt aktiv ist — ein Tool anzubieten, das garantiert
        // fehlschlaegt, ist schlechteres Modellverhalten als es gar nicht erst zu erwaehnen.
        $tools = [new KnowledgeSearchTool($ragService)];
        if (function_exists('wc_get_products')) {
            $tools[] = new WooCommerceProductSearchTool();
        }
        $toolRegistry = new ToolRegistry($tools);

        $conversationService = new ConversationService($this->conversations, $this->promptBuilder, $provider, $model, $ragService, $toolRegistry);

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
                function (array $sources): void {
                    $this->sendSources($sources);
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

    /**
     * M5: "sources"-SSE-Event, gesendet sobald Retrieval abgeschlossen ist (vor dem ersten
     * "token"-Event). Loest bei source_type="wp_content" den echten Permalink auf — das ist der
     * einzige Grund, warum diese Uebersetzung hier in der WP-gekoppelten Rest-Schicht passiert
     * und nicht in RagService (das bleibt WP-frei).
     *
     * @param RetrievedSource[] $sources
     */
    private function sendSources(array $sources): void
    {
        if ($sources === []) {
            return;
        }

        $payload = array_map(function (RetrievedSource $source): array {
            $url = null;

            if ($source->sourceType === 'wp_content' && $source->sourceRef !== null) {
                $permalink = get_permalink((int) $source->sourceRef);
                $url = $permalink !== false ? $permalink : null;
            }

            return ['title' => $source->title, 'url' => $url];
        }, $sources);

        $this->sendSseEvent('sources', ['sources' => $payload]);
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

    /**
     * Umbauplan Post-MVP Punkt 7: eine Eintragung pro Zeile in der Admin-Textarea
     * (`wpais_trusted_proxies`), Leerzeilen ignoriert — siehe ProviderSettingsPage.
     *
     * @return string[]
     */
    private function trustedProxiesList(): array
    {
        $raw = (string) get_option('wpais_trusted_proxies', '');

        return array_values(array_filter(array_map('trim', explode("\n", $raw)), static fn (string $line): bool => $line !== ''));
    }
}
