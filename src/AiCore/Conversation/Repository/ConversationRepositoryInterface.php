<?php

declare(strict_types=1);

namespace WPAiSuite\AiCore\Conversation\Repository;

use WPAiSuite\AiCore\Conversation\Conversation;
use WPAiSuite\AiCore\Conversation\StoredMessage;

/**
 * Persistenz-Port fuer wpais_conversations/wpais_messages (Bauplan Abschnitt 4). Einer der vier
 * in Abschnitt 5 benannten Kern-Contracts (AiProviderInterface, VectorStoreInterface,
 * ToolInterface, ConversationRepositoryInterface) — der Bauplan zeigt dafuer keinen Code-Schnipsel,
 * das Schema in Abschnitt 4 ist aber bindend und bestimmt diese Methoden.
 */
interface ConversationRepositoryInterface
{
    public function create(string $sessionToken, ?int $wpUserId, string $channel = 'website'): Conversation;

    public function findByToken(string $sessionToken): ?Conversation;

    /** @return StoredMessage[] Aelteste zuerst. */
    public function getMessages(int $conversationId): array;

    public function appendMessage(int $conversationId, StoredMessage $message): void;

    /** Fuer die Kostenschaetzung im Admin-Bereich (Abschnitt 11), unabhaengig von wpais_messages. */
    public function logUsage(int $conversationId, string $provider, string $model, int $tokensInput, int $tokensOutput): void;

    /**
     * M9 (DSGVO, "manuelle Konversation-loeschen-Aktion"): loescht die Konversation und alle
     * ihre Nachrichten unwiderruflich. Kein Fehler, wenn $conversationId nicht existiert (bereits
     * geloescht = Zielzustand bereits erreicht).
     */
    public function delete(int $conversationId): void;

    /**
     * M9 (DSGVO, "konfigurierbare Aufbewahrungsfrist"): loescht alle Konversationen (inkl.
     * Nachrichten), die seit $threshold nicht mehr aktualisiert wurden (siehe
     * WpdbConversationRepository::deleteOlderThan()-Docblock fuer das genaue Kriterium).
     *
     * @return int Anzahl geloeschter Konversationen.
     */
    public function deleteOlderThan(\DateTimeImmutable $threshold): int;
}
