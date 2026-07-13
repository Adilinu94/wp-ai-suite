<?php

declare(strict_types=1);

namespace WPAiSuite\Frontend\ChatWidget;

/**
 * Rendert ausschliesslich die Container-Markup (`<div class="wpais-chat" data-mode="..."
 * data-welcome="...">`), die vom in AssetManager enqueueten JS-Bundle "wpais-chat.js" gelesen und
 * mit dem eigentlichen Chat-UI gefuellt wird. Bewusst eine eigene, kleine Klasse statt direkt im
 * Shortcode: dieselbe Markup wird in M8 vom Elementor-Widget (`\Elementor\Widget_Base::render()`)
 * wiederverwendet, damit beide Einbettungswege exakt dasselbe JS/CSS ansprechen.
 *
 * "inline" ist der einzige in M3 tatsaechlich mit Verhalten/CSS hinterlegte Modus. floating/popup/
 * sidebar sind Bauplan Abschnitt 15 zufolge M8-Scope ("Alle 4 Display-Modi funktionieren") — das
 * data-mode-Attribut wird hier trotzdem schon fuer alle vier akzeptiert, damit M8 nichts an dieser
 * Klasse aendern muss, nur noch CSS/JS-Verhalten fuer die drei weiteren Modi ergaenzt.
 */
final class ChatWidgetRenderer
{
    private const ALLOWED_MODES = ['inline', 'floating', 'popup', 'sidebar'];
    private const DEFAULT_MODE = 'inline';

    public function render(string $mode, string $welcomeMessage): string
    {
        $safeMode = in_array($mode, self::ALLOWED_MODES, true) ? $mode : self::DEFAULT_MODE;

        return sprintf(
            '<div class="wpais-chat" data-mode="%s" data-welcome="%s"></div>',
            esc_attr($safeMode),
            esc_attr($welcomeMessage),
        );
    }
}
