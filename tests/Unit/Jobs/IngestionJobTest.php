<?php

declare(strict_types=1);

use WPAiSuite\Jobs\IngestionJob;
use WPAiSuite\Knowledge\Ingestion\RawDocument;

test('serialize() then deserialize() round-trips all RawDocument fields', function (): void {
    $original = new RawDocument('wp_content', '42', 'Preisliste', 'Der Versand kostet 4,90 Euro.');

    $roundTripped = IngestionJob::deserialize(IngestionJob::serialize($original));

    expect($roundTripped->sourceType)->toBe($original->sourceType)
        ->and($roundTripped->sourceRef)->toBe($original->sourceRef)
        ->and($roundTripped->title)->toBe($original->title)
        ->and($roundTripped->content)->toBe($original->content)
        ->and($roundTripped->extractionError)->toBeNull();
});

test('a null sourceRef round-trips as null, not as an empty string or "0"', function (): void {
    $original = new RawDocument('custom_text', null, 'Notiz', 'Text.');

    $roundTripped = IngestionJob::deserialize(IngestionJob::serialize($original));

    expect($roundTripped->sourceRef)->toBeNull();
});

test('a set extractionError round-trips unchanged', function (): void {
    $original = new RawDocument('pdf', '7', 'Scan', '', 'Kein Text extrahierbar.');

    $roundTripped = IngestionJob::deserialize(IngestionJob::serialize($original));

    expect($roundTripped->extractionError)->toBe('Kein Text extrahierbar.');
});

test('serialize() produces a plain array with the expected snake_case keys', function (): void {
    $payload = IngestionJob::serialize(new RawDocument('wp_content', '1', 'Titel', 'Inhalt.'));

    expect($payload)->toBe([
        'source_type' => 'wp_content',
        'source_ref' => '1',
        'title' => 'Titel',
        'content' => 'Inhalt.',
        'extraction_error' => null,
    ]);
});
