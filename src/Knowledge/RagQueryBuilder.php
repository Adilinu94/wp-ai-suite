<?php

declare(strict_types=1);

namespace WPAiSuite\Knowledge;

use WPAiSuite\AiCore\Conversation\StoredMessage;

/**
 * Umbauplan Post-MVP Punkt 5: baut die Retrieval-Anfrage fuer RagService::retrieve() aus der
 * bisherigen Konversation statt nur aus der aktuellen Nachricht — Anschlussfragen ("und wie
 * teuer?") tragen ihr eigenes Thema oft nicht mehr in sich, das vorherige "Was kostet Versand?"
 * schon.
 *
 * Bewusst deterministisch (kein LLM-Rewrite, kein zusaetzlicher Provider-Call): nur die letzten
 * $maxPriorUserMessages User-Nachrichten (Standard 2) plus die aktuelle werden aneinandergehaengt,
 * mit Truncation vom ANFANG her, falls das Ergebnis $maxChars ueberschreitet — die aktuelle Frage
 * steht am Ende der zusammengesetzten Query und ist fuer die Anschlussfrage am wichtigsten, aeltere
 * Turns duerfen zuerst wegfallen (siehe Risiko-Abschnitt im Umbauplan: zu lange Queries verschlechtern
 * die Hash-Embeddings).
 *
 * Nur `user`-Rollen werden beruecksichtigt (keine assistant-/tool-Nachrichten) — bewusst die
 * einfachere der beiden im Umbauplan genannten Varianten (Regel 2, kein Overengineering ohne
 * konkreten Bedarf).
 *
 * WP-frei (reine String-/Array-Logik, keine WordPress-Funktionen) — unit-testbar ohne Bootstrap.
 */
final class RagQueryBuilder
{
    private const DEFAULT_MAX_PRIOR_USER_MESSAGES = 2;
    private const DEFAULT_MAX_CHARS = 500;

    /** @param StoredMessage[] $storedMessages Bisherige Historie, OHNE die aktuelle Nachricht. */
    public function fromHistory(
        array $storedMessages,
        string $currentUserMessage,
        int $maxPriorUserMessages = self::DEFAULT_MAX_PRIOR_USER_MESSAGES,
        int $maxChars = self::DEFAULT_MAX_CHARS,
    ): string {
        $priorUserMessages = [];

        foreach (array_reverse($storedMessages) as $message) {
            if ($message->role !== 'user') {
                continue;
            }

            $priorUserMessages[] = trim($message->content);

            if (count($priorUserMessages) >= max(0, $maxPriorUserMessages)) {
                break;
            }
        }

        $orderedParts = [...array_reverse($priorUserMessages), trim($currentUserMessage)];
        $query = trim(implode(' ', array_filter($orderedParts, static fn (string $part): bool => $part !== '')));

        if ($maxChars > 0 && mb_strlen($query) > $maxChars) {
            $query = ltrim(mb_substr($query, -$maxChars));
        }

        return $query;
    }
}
