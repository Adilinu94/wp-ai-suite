<?php

declare(strict_types=1);

use WPAiSuite\AiCore\Conversation\Repository\WpdbConversationRepository;
use WPAiSuite\AiCore\Conversation\StoredMessage;

/**
 * Braucht eine echte (Test-)wpdb-Instanz inkl. wpais_conversations/wpais_messages/
 * wpais_usage_logs — siehe tests/Integration/README.md. In dieser Sandbox nicht ausfuehrbar;
 * fachliche Korrektheit stattdessen ueber ConversationService-Unit-Tests (mit
 * FakeConversationRepository) sowie manuelle Pruefung gegen Migrator::createTables() abgesichert.
 */
beforeEach(function (): void {
    global $wpdb;

    $this->repository = new WpdbConversationRepository($wpdb);
});

test('create() then findByToken() round-trips through the real wpais_conversations table', function (): void {
    $created = $this->repository->create('tok-integration-1', null, 'website');

    $found = $this->repository->findByToken('tok-integration-1');

    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($created->id)
        ->and($found->channel)->toBe('website');
});

test('appendMessage() persists messages retrievable via getMessages() in insertion order', function (): void {
    $conversation = $this->repository->create('tok-integration-2', null);

    $this->repository->appendMessage($conversation->id, new StoredMessage('user', 'Hallo'));
    $this->repository->appendMessage($conversation->id, new StoredMessage(
        role: 'assistant',
        content: 'Hallo zurueck!',
        provider: 'openai',
        model: 'gpt-test',
        tokensInput: 5,
        tokensOutput: 3,
    ));

    $messages = $this->repository->getMessages($conversation->id);

    expect($messages)->toHaveCount(2)
        ->and($messages[0]->role)->toBe('user')
        ->and($messages[1]->provider)->toBe('openai')
        ->and($messages[1]->tokensOutput)->toBe(3);
});

test('logUsage() writes a row into wpais_usage_logs', function (): void {
    global $wpdb;

    $conversation = $this->repository->create('tok-integration-3', null);
    $this->repository->logUsage($conversation->id, 'anthropic', 'claude-test', 10, 4);

    $table = $wpdb->prefix . 'wpais_usage_logs';
    $count = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE conversation_id = %d AND provider = %s",
        $conversation->id,
        'anthropic',
    ));

    expect($count)->toBe(1);
});
