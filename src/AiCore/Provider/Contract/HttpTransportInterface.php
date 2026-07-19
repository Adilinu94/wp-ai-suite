<?php

declare(strict_types=1);

namespace WPAiSuite\AiCore\Provider\Contract;

/**
 * Eigener kleiner Port, nicht woertlich in Abschnitt 2 aufgefuehrt, aber zwingend fuer
 * Abschnitt 14 ("Unit [Core, kein WP-Bootstrap] ... Provider-Adapter gegen gemockte
 * HTTP-Responses"): Ohne diese Abstraktion muessten die Provider-Adapter direkt
 * wp_remote_post()/wp_remote_get() aufrufen und waeren nur noch mit WP-Bootstrap testbar.
 * Die WordPress-spezifische Implementierung (WpHttpTransport) lebt bewusst im Adapter/-Ordner,
 * nicht in Contract/ — "Core kennt WordPress nicht" (Bauplan Abschnitt 1).
 */
interface HttpTransportInterface
{
    /**
     * @param array<string,string> $headers
     * @return array{status:int, body:string}
     * @throws ProviderException Bei Netzwerkfehlern (Transport-Ebene, nicht HTTP-4xx/5xx —
     *         die wertet der Aufrufer anhand von $response['status'] selbst aus).
     */
    public function get(string $url, array $headers, int $timeoutSeconds = 30): array;

    /**
     * @param array<string,string> $headers
     * @return array{status:int, body:string}
     * @throws ProviderException Bei Netzwerkfehlern.
     */
    public function post(string $url, array $headers, string $jsonBody, int $timeoutSeconds = 60): array;

    /**
     * Streaming-POST; ruft $onChunk fuer jedes empfangene Rohdaten-Fragment auf (z.B. eine oder
     * mehrere SSE-"data: ..."-Zeilen). Das Zeilen-/Event-Format ist providerspezifisch und wird
     * vom Aufrufer (der jeweilige Adapter) geparst, nicht vom Transport.
     *
     * @param array<string,string> $headers
     * @param callable(string): void $onChunk
     * @throws ProviderException Bei Netzwerkfehlern.
     */
    public function postStreaming(string $url, array $headers, string $jsonBody, callable $onChunk, int $timeoutSeconds = 120): void;
}
