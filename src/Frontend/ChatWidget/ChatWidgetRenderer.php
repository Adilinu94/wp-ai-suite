<?php

declare(strict_types=1);

namespace WPAiSuite\Frontend\ChatWidget;

/**
 * Rendert ausschliesslich die Container-Markup (`<div class="wpais-chat" data-mode="..."
 * data-welcome="..." data-icon="...">`), die vom in AssetManager enqueueten JS-Bundle
 * "wpais-chat.js" gelesen und mit dem eigentlichen Chat-UI gefuellt wird. Bewusst eine eigene,
 * kleine Klasse statt direkt im Shortcode: dieselbe Markup wird seit M8 vom Elementor-Widget
 * (`\Elementor\Widget_Base::render()`) wiederverwendet, damit beide Einbettungswege exakt
 * dasselbe JS/CSS ansprechen.
 *
 * "inline" war der einzige in M3 tatsaechlich mit Verhalten/CSS hinterlegte Modus; seit M8 haben
 * auch floating/popup/sidebar echtes CSS/JS-Verhalten (Bauplan Abschnitt 15, M8-DoD "Alle 4
 * Display-Modi funktionieren").
 *
 * $iconClass (M8): nur fuer floating/popup relevant (Launcher-Bubble-Icon), kommt aus Elementors
 * ICONS-Control (`\Elementor\ChatWidget::register_controls()`), NUR fuer den Font-Awesome-
 * Klassenname-Fall unterstuetzt (kein SVG-Upload-Icon) — bewusste Vereinfachung, siehe
 * `ChatWidget::render()`-Docblock. Bleibt leer fuer den Shortcode (M3 hat kein Icon-Attribut).
 */
final class ChatWidgetRenderer
{
    private const ALLOWED_MODES = ['inline', 'floating', 'popup', 'sidebar'];
    private const DEFAULT_MODE = 'inline';

    public function render(string $mode, string $welcomeMessage, string $iconClass = ''): string
    {
        $safeMode = in_array($mode, self::ALLOWED_MODES, true) ? $mode : self::DEFAULT_MODE;

        $iconAttribute = $iconClass !== '' ? sprintf(' data-icon="%s"', esc_attr($iconClass)) : '';

        return sprintf(
            '<div class="wpais-chat" data-mode="%s" data-welcome="%s"%s></div>',
            esc_attr($safeMode),
            esc_attr($welcomeMessage),
            $iconAttribute,
        );
    }
}
