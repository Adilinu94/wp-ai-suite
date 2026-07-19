<?php

declare(strict_types=1);

namespace WPAiSuite\Tests\Unit\Tools;

use WPAiSuite\Tools\Contract\ToolExecutionContext;
use WPAiSuite\Tools\Contract\ToolInterface;
use WPAiSuite\Tools\Contract\ToolResult;

final class FakeTool implements ToolInterface
{
    /** @var array<string,mixed>[] Aufgezeichnete Argumente jedes execute()-Aufrufs. */
    public array $receivedArguments = [];

    private ToolResult $nextResult;

    public function __construct(
        private readonly string $name = 'fake_tool',
        private readonly bool $allowed = true,
    ) {
        $this->nextResult = new ToolResult(success: true, data: ['ok' => true]);
    }

    public function queueResult(ToolResult $result): void
    {
        $this->nextResult = $result;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return 'Ein Test-Tool.';
    }

    public function getParameterSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function execute(array $arguments): ToolResult
    {
        $this->receivedArguments[] = $arguments;

        return $this->nextResult;
    }

    public function isAllowedFor(ToolExecutionContext $context): bool
    {
        return $this->allowed;
    }
}
