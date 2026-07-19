<?php

declare(strict_types=1);

namespace WPAiSuite\Tests\Unit\AiCore\Provider\Adapter;

use WPAiSuite\AiCore\Provider\Contract\HttpTransportInterface;

/**
 * Test-Double: liefert vorab in die Queue gelegte Responses zurueck, zeichnet alle Requests auf.
 * Genau das, was Bauplan Abschnitt 14 mit "Provider-Adapter gegen gemockte HTTP-Responses" meint.
 */
final class FakeHttpTransport implements HttpTransportInterface
{
    /** @var array{status:int, body:string}[] */
    private array $queuedResponses = [];

    /** @var string[] fuer chatStream(): SSE-Rohchunks, die postStreaming() nacheinander an $onChunk gibt */
    public array $streamChunks = [];

    /** @var array{method:string, url:string, headers:array<string,string>, body:?string}[] */
    public array $requests = [];

    public function queueResponse(int $status, string $body): void
    {
        $this->queuedResponses[] = ['status' => $status, 'body' => $body];
    }

    public function get(string $url, array $headers, int $timeoutSeconds = 30): array
    {
        $this->requests[] = ['method' => 'GET', 'url' => $url, 'headers' => $headers, 'body' => null];

        return $this->nextResponse();
    }

    public function post(string $url, array $headers, string $jsonBody, int $timeoutSeconds = 60): array
    {
        $this->requests[] = ['method' => 'POST', 'url' => $url, 'headers' => $headers, 'body' => $jsonBody];

        return $this->nextResponse();
    }

    public function postStreaming(string $url, array $headers, string $jsonBody, callable $onChunk, int $timeoutSeconds = 120): void
    {
        $this->requests[] = ['method' => 'POST-STREAM', 'url' => $url, 'headers' => $headers, 'body' => $jsonBody];

        foreach ($this->streamChunks as $chunk) {
            $onChunk($chunk);
        }
    }

    /** @return array{status:int, body:string} */
    private function nextResponse(): array
    {
        if ($this->queuedResponses === []) {
            throw new \RuntimeException('FakeHttpTransport: keine Response mehr in der Queue.');
        }

        return array_shift($this->queuedResponses);
    }
}
