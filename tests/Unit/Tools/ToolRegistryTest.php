<?php

declare(strict_types=1);

use WPAiSuite\Tools\Contract\ToolExecutionContext;
use WPAiSuite\Tools\Contract\ToolResult;
use WPAiSuite\Tools\ToolRegistry;
use WPAiSuite\Tests\Unit\Tools\FakeTool;

beforeEach(function (): void {
    $this->context = new ToolExecutionContext(isLoggedIn: false);
});

test('definitionsFor() returns one ToolDefinition per registered tool', function (): void {
    $registry = new ToolRegistry([new FakeTool('tool_a'), new FakeTool('tool_b')]);

    $definitions = $registry->definitionsFor($this->context);

    expect($definitions)->toHaveCount(2);
    expect(array_map(static fn ($d) => $d->name, $definitions))->toBe(['tool_a', 'tool_b']);
});

test('definitionsFor() excludes tools that are not allowed for the given context', function (): void {
    $registry = new ToolRegistry([
        new FakeTool('public_tool', allowed: true),
        new FakeTool('restricted_tool', allowed: false),
    ]);

    $definitions = $registry->definitionsFor($this->context);

    expect($definitions)->toHaveCount(1);
    expect($definitions[0]->name)->toBe('public_tool');
});

test('execute() dispatches to the matching tool and returns its result', function (): void {
    $tool = new FakeTool('search');
    $tool->queueResult(new ToolResult(success: true, data: ['hits' => 3]));
    $registry = new ToolRegistry([$tool]);

    $result = $registry->execute('search', ['query' => 'Versand'], $this->context);

    expect($result->success)->toBeTrue()
        ->and($result->data['hits'])->toBe(3)
        ->and($tool->receivedArguments)->toBe([['query' => 'Versand']]);
});

test('execute() returns a failed ToolResult instead of throwing for an unknown tool name', function (): void {
    $registry = new ToolRegistry([new FakeTool('search')]);

    $result = $registry->execute('does_not_exist', [], $this->context);

    expect($result->success)->toBeFalse()
        ->and($result->error)->toContain('does_not_exist');
});

test('execute() returns a failed ToolResult instead of throwing for a disallowed tool', function (): void {
    $tool = new FakeTool('restricted', allowed: false);
    $registry = new ToolRegistry([$tool]);

    $result = $registry->execute('restricted', [], $this->context);

    expect($result->success)->toBeFalse();
    expect($tool->receivedArguments)->toBe([]); // execute() wurde gar nicht erst aufgerufen
});

test('ToolResult::toModelContent() JSON-encodes data on success', function (): void {
    $result = new ToolResult(success: true, data: ['price' => 4.9]);

    expect(json_decode($result->toModelContent(), true))->toBe(['price' => 4.9]);
});

test('ToolResult::toModelContent() JSON-encodes an error object on failure', function (): void {
    $result = new ToolResult(success: false, error: 'Nicht gefunden.');

    expect(json_decode($result->toModelContent(), true))->toBe(['error' => 'Nicht gefunden.']);
});
