<?php

declare(strict_types=1);

namespace WPAiSuite\AiCore\Conversation;

/**
 * Spiegelt eine Zeile aus wpais_messages (siehe Core/Database/Migrator.php) 1:1. provider/model/
 * tokensInput/tokensOutput sind nur bei role="assistant" gesetzt.
 *
 * $toolCalls und $toolCallId teilen sich beide dieselbe DB-Spalte tool_calls (JSON, NULLable) —
 * es gibt bewusst keine zweite Spalte fuer Letzteres (M0s Schema ist bindend, siehe Bauplan
 * Abschnitt 4/ConversationRepositoryInterface-Docblock). Welche der beiden PHP-Felder gemeint
 * ist, ergibt sich eindeutig aus $role, nicht aus der JSON-Form selbst:
 * - role="assistant": $toolCalls enthaelt die vom Modell in DIESER Runde angeforderten Aufrufe
 *   (Liste von {id,name,arguments} — Spiegel von ChatResponse::$toolCalls), $toolCallId bleibt
 *   null.
 * - role="tool": $toolCallId referenziert den ToolCall, auf den geantwortet wird, $toolCalls
 *   bleibt leer.
 * WpdbConversationRepository (M2/M7) kodiert/dekodiert entsprechend anhand von $row['role'].
 */
final class StoredMessage
{
    /** @param array<int,array<string,mixed>> $toolCalls */
    public function __construct(
        public readonly string $role,
        public readonly string $content,
        public readonly ?string $provider = null,
        public readonly ?string $model = null,
        public readonly ?int $tokensInput = null,
        public readonly ?int $tokensOutput = null,
        public readonly array $toolCalls = [],
        public readonly ?string $toolCallId = null,
    ) {
    }
}
