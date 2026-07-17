<?php

declare(strict_types=1);

use WPAiSuite\Frontend\ChatWidget\ChatWidgetRenderer;

test('renders the container div with mode and welcome attributes', function (): void {
    $html = (new ChatWidgetRenderer())->render('inline', 'Hallo!');

    expect($html)->toBe('<div class="wpais-chat" data-mode="inline" data-welcome="Hallo!"></div>');
});

test('accepts all four documented display modes', function (): void {
    $renderer = new ChatWidgetRenderer();

    foreach (['inline', 'floating', 'popup', 'sidebar'] as $mode) {
        expect($renderer->render($mode, 'W'))->toContain('data-mode="' . $mode . '"');
    }
});

test('falls back to inline for an unknown mode instead of outputting it verbatim', function (): void {
    $html = (new ChatWidgetRenderer())->render('not-a-real-mode', 'W');

    expect($html)->toContain('data-mode="inline"');
});

test('escapes special characters in the welcome message', function (): void {
    $html = (new ChatWidgetRenderer())->render('inline', 'Hi "there" & <friend>');

    expect($html)->not->toContain('<friend>');
    expect($html)->toContain('data-welcome="Hi');
});

test('M8: omits the data-icon attribute entirely when no icon class is given', function (): void {
    $html = (new ChatWidgetRenderer())->render('floating', 'W');

    expect($html)->not->toContain('data-icon');
});

test('M8: includes an escaped data-icon attribute when an icon class is given', function (): void {
    $html = (new ChatWidgetRenderer())->render('floating', 'W', 'fas fa-comment-dots');

    expect($html)->toContain('data-icon="fas fa-comment-dots"');
});
