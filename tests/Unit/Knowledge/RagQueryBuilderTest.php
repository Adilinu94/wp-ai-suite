<?php

declare(strict_types=1);

use WPAiSuite\AiCore\Conversation\StoredMessage;
use WPAiSuite\Knowledge\RagQueryBuilder;

beforeEach(function (): void {
    $this->builder = new RagQueryBuilder();
});

test('with no prior history, the query is just the current message', function (): void {
    $query = $this->builder->fromHistory([], 'Was kostet Versand?');

    expect($query)->toBe('Was kostet Versand?');
});

test('a follow-up question is enriched with the prior user message (Umbauplan Punkt 5 example)', function (): void {
    $history = [
        new StoredMessage(role: 'user', content: 'Was kostet Versand?'),
        new StoredMessage(role: 'assistant', content: 'Versand kostet 4,90 Euro.'),
    ];

    $query = $this->builder->fromHistory($history, 'und wie teuer?');

    expect($query)->toBe('Was kostet Versand? und wie teuer?')
        ->and($query)->toContain('Versand');
});

test('assistant and tool messages are skipped, only user messages count', function (): void {
    $history = [
        new StoredMessage(role: 'user', content: 'A'),
        new StoredMessage(role: 'assistant', content: 'B'),
        new StoredMessage(role: 'tool', content: 'C'),
        new StoredMessage(role: 'user', content: 'D'),
    ];

    $query = $this->builder->fromHistory($history, 'E');

    expect($query)->toBe('A D E');
});

test('only the last K=2 prior user messages are kept by default, older ones are dropped', function (): void {
    $history = [
        new StoredMessage(role: 'user', content: 'first'),
        new StoredMessage(role: 'user', content: 'second'),
        new StoredMessage(role: 'user', content: 'third'),
    ];

    $query = $this->builder->fromHistory($history, 'fourth');

    expect($query)->toBe('second third fourth');
});

test('maxPriorUserMessages is configurable', function (): void {
    $history = [
        new StoredMessage(role: 'user', content: 'first'),
        new StoredMessage(role: 'user', content: 'second'),
    ];

    $query = $this->builder->fromHistory($history, 'third', maxPriorUserMessages: 1);

    expect($query)->toBe('second third');
});

test('truncation keeps the current message (at the end) and drops the oldest content first', function (): void {
    $history = [new StoredMessage(role: 'user', content: str_repeat('x', 480))];

    $query = $this->builder->fromHistory($history, 'wichtige aktuelle frage', maxChars: 30);

    expect(mb_strlen($query))->toBeLessThanOrEqual(30)
        ->and($query)->toEndWith('frage');
});

test('blank prior messages are filtered out instead of leaving stray whitespace', function (): void {
    $history = [new StoredMessage(role: 'user', content: '   ')];

    $query = $this->builder->fromHistory($history, 'current');

    expect($query)->toBe('current');
});
