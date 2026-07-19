<?php

declare(strict_types=1);

namespace WPAiSuite\AiCore\Conversation\Repository;

use WPAiSuite\AiCore\Conversation\Conversation;
use WPAiSuite\AiCore\Conversation\StoredMessage;

/**
 * wpdb-Adapter fuer wpais_conversations/wpais_messages/wpais_usage_logs (Bauplan Abschnitt 4,
 * Schema unveraendert aus M0/Migrator.php). Integration-Test-Territorium (Abschnitt 14:
 * WP_UnitTestCase, echte wpdb) — analog zu WpdbApiKeyRepository aus M1.
 */
final class WpdbConversationRepository implements ConversationRepositoryInterface
{
    public function __construct(
        private readonly \wpdb $wpdb,
    ) {
    }

    private function conversationsTable(): string
    {
        return $this->wpdb->prefix . 'wpais_conversations';
    }

    private function messagesTable(): string
    {
        return $this->wpdb->prefix . 'wpais_messages';
    }

    private function usageLogsTable(): string
    {
        return $this->wpdb->prefix . 'wpais_usage_logs';
    }

    public function create(string $sessionToken, ?int $wpUserId, string $channel = 'website'): Conversation
    {
        $now = current_time('mysql', true);

        $this->wpdb->insert(
            $this->conversationsTable(),
            [
                'session_token' => $sessionToken,
                'wp_user_id' => $wpUserId,
                'channel' => $channel,
                'status' => 'open',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%s', $wpUserId === null ? null : '%d', '%s', '%s', '%s', '%s'],
        );

        return new Conversation(
            id: (int) $this->wpdb->insert_id,
            sessionToken: $sessionToken,
            wpUserId: $wpUserId,
            channel: $channel,
            status: 'open',
        );
    }

    public function findByToken(string $sessionToken): ?Conversation
    {
        $table = $this->conversationsTable();

        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT id, session_token, wp_user_id, channel, status FROM {$table} WHERE session_token = %s",
                $sessionToken,
            ),
            ARRAY_A,
        );

        if (!is_array($row)) {
            return null;
        }

        return new Conversation(
            id: (int) $row['id'],
            sessionToken: $row['session_token'],
            wpUserId: $row['wp_user_id'] !== null ? (int) $row['wp_user_id'] : null,
            channel: $row['channel'],
            status: $row['status'],
        );
    }

    public function getMessages(int $conversationId): array
    {
        $table = $this->messagesTable();

        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT role, content, provider, model, tokens_input, tokens_output, tool_calls
                 FROM {$table} WHERE conversation_id = %d ORDER BY id ASC",
                $conversationId,
            ),
            ARRAY_A,
        );

        return array_map(static function (array $row): StoredMessage {
            $decoded = $row['tool_calls'] !== null ? json_decode((string) $row['tool_calls'], true) : null;

            return new StoredMessage(
                role: $row['role'],
                content: $row['content'],
                provider: $row['provider'],
                model: $row['model'],
                tokensInput: $row['tokens_input'] !== null ? (int) $row['tokens_input'] : null,
                tokensOutput: $row['tokens_output'] !== null ? (int) $row['tokens_output'] : null,
                toolCalls: $row['role'] !== 'tool' && is_array($decoded) ? $decoded : [],
                toolCallId: $row['role'] === 'tool' && is_array($decoded) ? (($decoded['tool_call_id'] ?? null) !== null ? (string) $decoded['tool_call_id'] : null) : null,
            );
        }, $rows ?: []);
    }

    public function appendMessage(int $conversationId, StoredMessage $message): void
    {
        $table = $this->messagesTable();

        $toolCallsColumn = null;
        if ($message->role === 'tool' && $message->toolCallId !== null) {
            $toolCallsColumn = json_encode(['tool_call_id' => $message->toolCallId], JSON_THROW_ON_ERROR);
        } elseif ($message->toolCalls !== []) {
            $toolCallsColumn = json_encode($message->toolCalls, JSON_THROW_ON_ERROR);
        }

        $this->wpdb->insert(
            $table,
            [
                'conversation_id' => $conversationId,
                'role' => $message->role,
                'content' => $message->content,
                'tool_calls' => $toolCallsColumn,
                'provider' => $message->provider,
                'model' => $message->model,
                'tokens_input' => $message->tokensInput,
                'tokens_output' => $message->tokensOutput,
                'created_at' => current_time('mysql', true),
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s'],
        );

        $this->wpdb->update(
            $this->conversationsTable(),
            ['updated_at' => current_time('mysql', true)],
            ['id' => $conversationId],
            ['%s'],
            ['%d'],
        );
    }

    public function logUsage(int $conversationId, string $provider, string $model, int $tokensInput, int $tokensOutput): void
    {
        $this->wpdb->insert(
            $this->usageLogsTable(),
            [
                'conversation_id' => $conversationId,
                'provider' => $provider,
                'model' => $model,
                'tokens_input' => $tokensInput,
                'tokens_output' => $tokensOutput,
                'created_at' => current_time('mysql', true),
            ],
            ['%d', '%s', '%s', '%d', '%d', '%s'],
        );
    }

    public function delete(int $conversationId): void
    {
        // wpais_usage_logs bleibt bewusst unangetastet: aggregierte Kosten-/Token-Zahlen ohne
        // Nachrichteninhalt sind keine personenbezogenen Daten im Sinne der DSGVO-Loeschpflicht
        // (Bauplan Abschnitt 9), sondern eine legitime Kostenabrechnung des Website-Betreibers.
        // Kein FK-Constraint auf conversation_id, ein "verwaister" Verweis ist unschaedlich.
        $this->wpdb->delete($this->messagesTable(), ['conversation_id' => $conversationId], ['%d']);
        $this->wpdb->delete($this->conversationsTable(), ['id' => $conversationId], ['%d']);
    }

    /**
     * Bauplan Abschnitt 9 ("konfigurierbare Aufbewahrungsfrist", M9) — von der taeglichen
     * WP-Cron-Aufgabe genutzt (siehe Security\RetentionCleanup). Prueft
     * wpais_conversations.updated_at (wird bei jedem appendMessage() aktualisiert, siehe dort) —
     * eine seit $threshold inaktive Konversation gilt als abgelaufen, unabhaengig vom
     * urspruenglichen Erstellungsdatum.
     *
     * @return int Anzahl geloeschter Konversationen.
     */
    public function deleteOlderThan(\DateTimeImmutable $threshold): int
    {
        $thresholdMysql = $threshold->format('Y-m-d H:i:s');

        /** @var string[] $expiredIds */
        $expiredIds = $this->wpdb->get_col($this->wpdb->prepare(
            "SELECT id FROM {$this->conversationsTable()} WHERE updated_at < %s",
            $thresholdMysql,
        ));

        foreach ($expiredIds as $id) {
            $this->delete((int) $id);
        }

        return count($expiredIds);
    }

    /**
     * @return array<int, array{id:int, conversation_id:?int, provider:string, model:string, tokens_input:int, tokens_output:int, created_at:string}>
     */
    public function listUsageLogs(int $limit = 200): array
    {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->usageLogsTable()} ORDER BY created_at DESC LIMIT %d",
                $limit,
            ),
            ARRAY_A,
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'conversation_id' => $row['conversation_id'] !== null ? (int) $row['conversation_id'] : null,
            'provider' => (string) $row['provider'],
            'model' => (string) $row['model'],
            'tokens_input' => (int) $row['tokens_input'],
            'tokens_output' => (int) $row['tokens_output'],
            'created_at' => (string) $row['created_at'],
        ], is_array($rows) ? $rows : []);
    }
}
