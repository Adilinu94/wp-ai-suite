<?php

declare(strict_types=1);

namespace WPAiSuite\Frontend\ChatWidget;

/**
 * [wpais_chat mode="inline" welcome="..."] — Bauplan Abschnitt 15, M3-DoD ("Shortcode + JS-Bundle
 * rendert Chat"). Attribute bewusst minimal: nur was M3 tatsaechlich braucht. Provider/Modell
 * werden nicht hier gewaehlt (das macht ActiveProviderResolver serverseitig, M2) — der Shortcode
 * kennt nur Darstellung (Modus, Begruessungstext).
 */
final class Shortcode
{
    private const TAG = 'wpais_chat';

    public function __construct(
        private readonly ChatWidgetRenderer $renderer,
        private readonly AssetManager $assets,
    ) {
    }

    public function register(): void
    {
        add_shortcode(self::TAG, [$this, 'render']);
    }

    /** @param array<string,string>|string $atts */
    public function render($atts = []): string
    {
        $atts = shortcode_atts(
            [
                'mode' => 'inline',
                'welcome' => __('Hallo! Wie kann ich dir helfen?', 'wp-ai-suite'),
            ],
            $atts,
            self::TAG,
        );

        $this->assets->enqueue();

        return $this->renderer->render((string) $atts['mode'], (string) $atts['welcome']);
    }
}
