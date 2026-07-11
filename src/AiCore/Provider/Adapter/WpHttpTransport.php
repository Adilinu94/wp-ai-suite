<?php

declare(strict_types=1);

namespace WPAiSuite\AiCore\Provider\Adapter;

use WPAiSuite\AiCore\Provider\Contract\HttpTransportInterface;
use WPAiSuite\AiCore\Provider\Contract\ProviderException;

/**
 * Produktions-Implementierung von HttpTransportInterface.
 *
 * get()/post() nutzen die WordPress-HTTP-API (wp_remote_get/wp_remote_post), damit Proxy- und
 * SSL-Einstellungen des Hosts respektiert werden. postStreaming() nutzt bewusst rohes cURL: die
 * WP-HTTP-API bietet keinen Callback pro empfangenem Chunk (wp_remote_post puffert die komplette
 * Antwort), was fuer Server-Sent-Events ungeeignet ist. Diese Klasse ist der einzige Ort im
 * Provider Layer, an dem WordPress- bzw. cURL-Funktionen direkt aufgerufen werden.
 *
 * Integration-Test-Territorium (Bauplan Abschnitt 14) — fuer Unit-Tests der eigentlichen
 * Provider-Adapter wird stattdessen ein Fake von HttpTransportInterface injiziert.
 */
final class WpHttpTransport implements HttpTransportInterface
{
    public function get(string $url, array $headers, int $timeoutSeconds = 30): array
    {
        $response = wp_remote_get($url, [
            'headers' => $headers,
            'timeout' => $timeoutSeconds,
        ]);

        return $this->toResult($response);
    }

    public function post(string $url, array $headers, string $jsonBody, int $timeoutSeconds = 60): array
    {
        $response = wp_remote_post($url, [
            'headers' => $headers,
            'body' => $jsonBody,
            'timeout' => $timeoutSeconds,
        ]);

        return $this->toResult($response);
    }

    public function postStreaming(string $url, array $headers, string $jsonBody, callable $onChunk, int $timeoutSeconds = 120): void
    {
        if (!function_exists('curl_init')) {
            throw new ProviderException('cURL-Extension wird fuer Streaming-Requests benoetigt.');
        }

        $curlHeaders = [];
        foreach ($headers as $name => $value) {
            $curlHeaders[] = $name . ': ' . $value;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonBody,
            CURLOPT_HTTPHEADER => $curlHeaders,
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use ($onChunk): int {
                $onChunk($chunk);

                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($ch);
        $errorNumber = curl_errno($ch);
        $errorMessage = curl_error($ch);
        curl_close($ch);

        if ($ok === false || $errorNumber !== 0) {
            throw new ProviderException('Streaming-Request fehlgeschlagen: ' . $errorMessage);
        }
    }

    /** @return array{status:int, body:string} */
    private function toResult(mixed $response): array
    {
        if (is_wp_error($response)) {
            throw new ProviderException($response->get_error_message());
        }

        return [
            'status' => (int) wp_remote_retrieve_response_code($response),
            'body' => (string) wp_remote_retrieve_body($response),
        ];
    }
}
