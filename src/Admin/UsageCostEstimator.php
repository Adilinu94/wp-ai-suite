<?php

declare(strict_types=1);

namespace WPAiSuite\Admin;

/**
 * Bauplan Abschnitt 11 (M10): "Kostenschätzung als simple Multiplikation, keine Abrechnungslogik
 * nötig (BYOK)." "Keine Abrechnungslogik" heisst hier woertlich: die Preise unten sind eine grobe,
 * im Code hinterlegte Naeherung (Stand Trainingsdaten), KEIN Live-Abruf einer Preisliste — fuer
 * BYOK ("Bring Your Own Key") zahlt der Kunde ohnehin direkt beim Provider, diese Klasse dient nur
 * der groben Kostenorientierung in UsageLogsPage, nicht der Abrechnung.
 *
 * Eigene, WP-freie Klasse statt Rechenlogik direkt in UsageLogsPage: dieselbe Trennung wie
 * ueberall sonst im Plugin (z.B. PromptGuard/RateLimiter, M9) — reine Logik bleibt unit-testbar,
 * ohne dass die umgebende WP-Admin-Seite das mitreissen muss.
 */
final class UsageCostEstimator
{
    /**
     * Grobe Naeherung in USD pro 1M Tokens (input, output). Deckt nur die in ProviderFactory
     * bekannten Provider-Keys ab; unbekannte Provider (z.B. "custom"/OpenAI-kompatibel mit
     * selbst gehostetem Modell) haben keinen sinnvollen Default-Preis und werden von der
     * Schaetzung ausgenommen (0,00 $ statt eines frei erfundenen Werts).
     *
     * @var array<string, array{input: float, output: float}>
     */
    private const APPROX_PRICE_PER_MILLION_TOKENS = [
        'openai' => ['input' => 0.40, 'output' => 1.60],
        'anthropic' => ['input' => 3.00, 'output' => 15.00],
    ];

    public function estimate(string $provider, int $tokensInput, int $tokensOutput): float
    {
        $prices = self::APPROX_PRICE_PER_MILLION_TOKENS[$provider] ?? null;

        if ($prices === null) {
            return 0.0;
        }

        return ($tokensInput / 1_000_000 * $prices['input']) + ($tokensOutput / 1_000_000 * $prices['output']);
    }
}
