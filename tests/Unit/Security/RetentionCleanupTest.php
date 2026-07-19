<?php

declare(strict_types=1);

use WPAiSuite\Security\RetentionCleanup;
use WPAiSuite\Tests\Unit\AiCore\Conversation\FakeConversationRepository;

beforeEach(function (): void {
    $this->repository = new FakeConversationRepository();
    $this->cleanup = new RetentionCleanup($this->repository);
});

test('deletes a conversation whose last activity is older than the retention period', function (): void {
    $conversation = $this->repository->create('tok-old', null);
    $this->repository->setUpdatedAt($conversation->id, new DateTimeImmutable('-40 days'));

    $deleted = $this->cleanup->run(30);

    expect($deleted)->toBe(1);
    expect($this->repository->findByToken('tok-old'))->toBeNull();
});

test('keeps a conversation whose last activity is within the retention period', function (): void {
    $conversation = $this->repository->create('tok-recent', null);
    $this->repository->setUpdatedAt($conversation->id, new DateTimeImmutable('-5 days'));

    $deleted = $this->cleanup->run(30);

    expect($deleted)->toBe(0);
    expect($this->repository->findByToken('tok-recent'))->not->toBeNull();
});

test('a retention value of 0 disables cleanup entirely, regardless of how old data is', function (): void {
    $conversation = $this->repository->create('tok-ancient', null);
    $this->repository->setUpdatedAt($conversation->id, new DateTimeImmutable('-9999 days'));

    $deleted = $this->cleanup->run(0);

    expect($deleted)->toBe(0);
    expect($this->repository->findByToken('tok-ancient'))->not->toBeNull();
});

test('a negative retention value also disables cleanup (never silently deletes everything)', function (): void {
    $this->repository->create('tok-x', null);

    expect($this->cleanup->run(-5))->toBe(0);
});

test('only deletes conversations older than the threshold, not all of them', function (): void {
    $old = $this->repository->create('tok-old', null);
    $this->repository->setUpdatedAt($old->id, new DateTimeImmutable('-100 days'));
    $recent = $this->repository->create('tok-recent', null);
    $this->repository->setUpdatedAt($recent->id, new DateTimeImmutable('-1 day'));

    $deleted = $this->cleanup->run(30);

    expect($deleted)->toBe(1);
    expect($this->repository->findByToken('tok-old'))->toBeNull();
    expect($this->repository->findByToken('tok-recent'))->not->toBeNull();
});
