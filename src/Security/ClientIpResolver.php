<?php

declare(strict_types=1);

namespace WPAiSuite\Security;

/**
 * Umbauplan Post-MVP Punkt 7: loest die tatsaechliche Client-IP auf. Standardmaessig NUR
 * REMOTE_ADDR (sicher out-of-the-box) — nur wenn explizit aktiviert UND REMOTE_ADDR selbst zu
 * einer eingetragenen vertrauenswuerdigen Proxy-IP/einem CIDR-Block gehoert, wird zusaetzlich
 * X-Real-IP/X-Forwarded-For ausgewertet. Ohne diese Einschraenkung waere das Rate-Limit per
 * gefaelschtem XFF-Header trivial umgehbar — jeder Website-Besucher kann sich selbst einen
 * beliebigen X-Forwarded-For-Header mitschicken.
 *
 * Bewusst eine eigene, duenne Klasse statt Logik in ChatController: mit expliziten
 * $serverParams/$trustProxy/$trustedProxies-Parametern statt direktem $_SERVER/get_option()-
 * Zugriff komplett WP-frei unit-testbar (Fake-Arrays statt echter Superglobals) — analog zu
 * CosineSimilarity/RecursiveTextChunker, die aus demselben Grund keine WordPress-Funktionen
 * direkt aufrufen.
 */
final class ClientIpResolver
{
    /**
     * @param array<string,mixed> $serverParams typischerweise $_SERVER — als Parameter statt
     *        direktem Zugriff, siehe Klassen-Docblock.
     * @param string[] $trustedProxies Einzelne IPs oder CIDR-Notation (z.B. "10.0.0.0/8").
     */
    public function resolve(array $serverParams, bool $trustProxy, array $trustedProxies): string
    {
        $remoteAddr = $this->normalizeIp((string) ($serverParams['REMOTE_ADDR'] ?? ''));

        if ($remoteAddr === null) {
            return 'unknown';
        }

        if (!$trustProxy || !$this->isTrustedProxy($remoteAddr, $trustedProxies)) {
            return $remoteAddr;
        }

        foreach (['HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR'] as $header) {
            if (empty($serverParams[$header])) {
                continue;
            }

            // X-Forwarded-For kann eine Kette sein ("Client, Proxy1, Proxy2") — der am weitesten
            // links stehende Eintrag ist der urspruengliche Client, alles rechts davon sind die
            // (vertrauenswuerdigen) Proxys dazwischen.
            $candidates = explode(',', (string) $serverParams[$header]);
            $clientIp = $this->normalizeIp(trim($candidates[0]));

            if ($clientIp !== null) {
                return $clientIp;
            }
        }

        return $remoteAddr;
    }

    private function normalizeIp(string $ip): ?string
    {
        $ip = trim($ip);

        return $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }

    /** @param string[] $trustedProxies */
    private function isTrustedProxy(string $ip, array $trustedProxies): bool
    {
        foreach ($trustedProxies as $entry) {
            $entry = trim($entry);

            if ($entry === '') {
                continue;
            }

            if (!str_contains($entry, '/')) {
                if (hash_equals($entry, $ip)) {
                    return true;
                }

                continue;
            }

            if ($this->ipInCidr($ip, $entry)) {
                return true;
            }
        }

        return false;
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        $parts = explode('/', $cidr, 2);

        if (count($parts) !== 2 || !ctype_digit($parts[1])) {
            return false;
        }

        [$subnet, $maskBits] = $parts;
        $maskBits = (int) $maskBits;

        $ipBinary = @inet_pton($ip);
        $subnetBinary = @inet_pton($subnet);

        if ($ipBinary === false || $subnetBinary === false || strlen($ipBinary) !== strlen($subnetBinary)) {
            return false;
        }

        $maxBits = strlen($ipBinary) * 8;

        if ($maskBits < 0 || $maskBits > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($maskBits, 8);
        $remainderBits = $maskBits % 8;

        if ($fullBytes > 0 && substr($ipBinary, 0, $fullBytes) !== substr($subnetBinary, 0, $fullBytes)) {
            return false;
        }

        if ($remainderBits === 0) {
            return true;
        }

        $mask = (~(0xFF >> $remainderBits)) & 0xFF;

        return (ord($ipBinary[$fullBytes]) & $mask) === (ord($subnetBinary[$fullBytes]) & $mask);
    }
}
