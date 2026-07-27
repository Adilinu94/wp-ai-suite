<?php

declare(strict_types=1);

use WPAiSuite\Knowledge\DocumentListCriteria;
use WPAiSuite\Tests\Unit\Knowledge\FakeDocumentRepository;

test('delete() removes the document row', function (): void {
    $repo = new FakeDocumentRepository();
    $doc = $repo->upsertDocument('faq', 'versandkosten', 'Versandkosten', 'checksum-1');

    $repo->delete($doc->id);

    expect($repo->findById($doc->id))->toBeNull();
});

test('delete() together with deleteChunks() removes chunks too', function (): void {
    $repo = new FakeDocumentRepository();
    $doc = $repo->upsertDocument('faq', 'versandkosten', 'Versandkosten', 'checksum-1');
    $repo->addChunk($doc->id, 0, 'Versand kostet 4,99 Euro.', 10);

    $repo->deleteChunks($doc->id);
    $repo->delete($doc->id);

    expect($repo->chunksByDocument[$doc->id] ?? [])->toBe([]);
});

test('a deleted document no longer counts in list()', function (): void {
    $repo = new FakeDocumentRepository();
    $doc = $repo->upsertDocument('faq', 'versandkosten', 'Versandkosten', 'checksum-1');

    $repo->delete($doc->id);

    expect($repo->list(new DocumentListCriteria())->total)->toBe(0);
});
