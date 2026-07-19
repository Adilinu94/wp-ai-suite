<?php

declare(strict_types=1);

namespace WPAiSuite\Elementor;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use WPAiSuite\Frontend\ChatWidget\AssetManager;
use WPAiSuite\Frontend\ChatWidget\ChatWidgetRenderer;

/**
 * Bauplan Abschnitt 10 (M8, vollstaendiger Code-Schnipsel dort) — klassisches Widget
 * (`\Elementor\Widget_Base`), bewusst KEIN natives V4-Atomic-Element: "läuft identisch auf V3-
 * und V4-Seiten, ist heute stabil und dokumentiert buildbar" (siehe Bauplan-Begruendung,
 * bindende Grundsatzentscheidung). Anders als die Elementor-V4-Atomic-Widget-Arbeit in Adis
 * anderen Repos (novamira-adrianv2 etc.) ist das hier ausdruecklich NICHT dasselbe Widget-Modell.
 *
 * render() ruft dieselbe ChatWidgetRenderer/AssetManager-Instanz wie Shortcode (M3), damit beide
 * Einbettungswege exakt dieselbe Markup/dasselbe JS-Bundle nutzen (siehe dortiger Docblock) — der
 * Bauplan-Schnipsel zeigt render() mit direktem printf(), diese Klasse delegiert stattdessen an
 * die bestehende Klasse, um Divergenz zwischen Shortcode und Widget zu vermeiden.
 *
 * $renderer/$assets werden NICHT ueber den Konstruktor injiziert: Elementor instanziiert Widgets
 * selbst (new static(...) intern beim Registrieren, siehe Plugin.php), ein eigener Konstruktor mit
 * Pflicht-Parametern wuerde dort brechen. Stattdessen holt sich die Klasse beide lazy aus dem
 * globalen Container (WPAiSuite\Core\Plugin::container(), siehe dortiger Docblock) — der einzige
 * Ort im gesamten Plugin, an dem das noetig ist, weil Elementor selbst die Instanziierung
 * kontrolliert.
 */
final class ChatWidget extends Widget_Base
{
    public function get_name(): string
    {
        return 'wpais_chat_widget';
    }

    public function get_title(): string
    {
        return __('AI Chat', 'wp-ai-suite');
    }

    public function get_icon(): string
    {
        return 'eicon-chat';
    }

    /** @return string[] */
    public function get_categories(): array
    {
        return ['general'];
    }

    /** @return string[] */
    public function get_keywords(): array
    {
        return ['ai', 'chat', 'assistant'];
    }

    protected function register_controls(): void
    {
        $this->start_controls_section('content_section', [
            'label' => __('Inhalt', 'wp-ai-suite'),
            'tab' => Controls_Manager::TAB_CONTENT,
        ]);
        $this->add_control('display_mode', [
            'label' => __('Anzeigemodus', 'wp-ai-suite'),
            'type' => Controls_Manager::SELECT,
            'default' => 'inline',
            'options' => [
                'inline' => __('Inline', 'wp-ai-suite'),
                'floating' => __('Floating Bubble', 'wp-ai-suite'),
                'popup' => __('Popup (Button-getriggert)', 'wp-ai-suite'),
                'sidebar' => __('Sidebar', 'wp-ai-suite'),
            ],
        ]);
        $this->add_control('welcome_message', [
            'label' => __('Begruessungstext', 'wp-ai-suite'),
            'type' => Controls_Manager::TEXTAREA,
            'default' => __('Wie kann ich helfen?', 'wp-ai-suite'),
        ]);
        $this->end_controls_section();

        $this->start_controls_section('style_section', [
            'label' => __('Stil', 'wp-ai-suite'),
            'tab' => Controls_Manager::TAB_STYLE,
        ]);
        $this->add_control('primary_color', [
            'label' => __('Primaerfarbe', 'wp-ai-suite'),
            'type' => Controls_Manager::COLOR,
            'default' => '#0E2A3B',
            'selectors' => ['{{WRAPPER}} .wpais-chat' => '--wpais-primary: {{VALUE}};'],
        ]);
        // Bauplan Abschnitt 10 liess die folgenden vier bewusst nur als "weitere Controls nach
        // demselben Muster" (bubble_color, text_color, border_radius, spacing, icon) offen — hier
        // konkretisiert, jeweils an eine schon bestehende CSS-Custom-Property gebunden
        // (wpais-chat.css, M3), ausser --wpais-bubble/--wpais-spacing, die M8 dafuer neu einfuehrt.
        $this->add_control('bubble_color', [
            'label' => __('Sprechblasenfarbe (Nutzer)', 'wp-ai-suite'),
            'type' => Controls_Manager::COLOR,
            // Leerer Default = Elementor gibt KEINE Selector-Regel aus, CSS faellt auf
            // var(--wpais-bubble, var(--wpais-primary)) zurueck (siehe wpais-chat.css).
            'default' => '',
            'selectors' => ['{{WRAPPER}} .wpais-chat' => '--wpais-bubble: {{VALUE}};'],
        ]);
        $this->add_control('text_color', [
            'label' => __('Textfarbe', 'wp-ai-suite'),
            'type' => Controls_Manager::COLOR,
            'default' => '',
            'selectors' => ['{{WRAPPER}} .wpais-chat' => '--wpais-text: {{VALUE}};'],
        ]);
        $this->add_control('border_radius', [
            'label' => __('Eckenradius', 'wp-ai-suite'),
            'type' => Controls_Manager::SLIDER,
            'size_units' => ['px'],
            'range' => ['px' => ['min' => 0, 'max' => 40]],
            'default' => ['size' => 10, 'unit' => 'px'],
            'selectors' => ['{{WRAPPER}} .wpais-chat' => '--wpais-radius: {{SIZE}}{{UNIT}};'],
        ]);
        $this->add_control('spacing', [
            'label' => __('Innenabstand (Nachrichtenbereich)', 'wp-ai-suite'),
            'type' => Controls_Manager::DIMENSIONS,
            'size_units' => ['px'],
            'default' => ['top' => '20', 'right' => '20', 'bottom' => '20', 'left' => '20', 'unit' => 'px'],
            'selectors' => [
                '{{WRAPPER}} .wpais-chat' => '--wpais-spacing: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
            ],
        ]);
        $this->add_control('icon', [
            'label' => __('Icon (nur Floating/Popup)', 'wp-ai-suite'),
            'type' => Controls_Manager::ICONS,
            'default' => ['value' => 'fas fa-comment-dots', 'library' => 'fa-solid'],
            'condition' => ['display_mode' => ['floating', 'popup']],
        ]);
        $this->end_controls_section();
    }

    protected function render(): void
    {
        $settings = $this->get_settings_for_display();

        // ICONS-Control liefert bei Font-Awesome-Auswahl ['value' => 'fas fa-...', 'library' =>
        // 'fa-solid'], bei SVG-Upload dagegen ['value' => ['url'=>..,'id'=>..], 'library'=>'svg'].
        // Bewusste Vereinfachung (Bauplan macht dazu keine Vorgabe): nur der Font-Awesome-
        // Klassenname-Fall wird unterstuetzt, render() bleibt dadurch minimal (siehe Klassen-
        // Docblock) statt Icons_Manager::render_icon()-Markup ueber ein Data-Attribut
        // durchzureichen. SVG-Upload faellt still auf das JS-seitige Standard-Icon zurueck.
        $iconValue = $settings['icon']['value'] ?? '';
        $iconClass = is_string($iconValue) ? $iconValue : '';

        echo $this->renderer()->render(
            (string) $settings['display_mode'],
            (string) $settings['welcome_message'],
            $iconClass,
        );
        // Das eigentliche UI kommt aus einem einzigen enqueued JS-Bundle, das dieses
        // data-Attribut liest. render() bleibt dadurch minimal (Bauplan-Vorgabe) und ist
        // unabhaengig vom Frontend-Modul testbar.
        $this->assets()->enqueue();
    }

    private function renderer(): ChatWidgetRenderer
    {
        return \WPAiSuite\Core\Plugin::container()->get(ChatWidgetRenderer::class);
    }

    private function assets(): AssetManager
    {
        return \WPAiSuite\Core\Plugin::container()->get(AssetManager::class);
    }
}
