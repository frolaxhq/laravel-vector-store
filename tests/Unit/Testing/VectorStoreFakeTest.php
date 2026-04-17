<?php

declare(strict_types=1);

use Frolax\VectorStore\Testing\VectorStoreFake;
use Frolax\VectorStore\ValueObjects\VectorRecord;
use Illuminate\Support\Collection;

beforeEach(function () {
    $this->fake = new VectorStoreFake();
});

it('records upserts and fetch', function () {
    $this->fake->upsert('1', [0.1, 0.2], ['name' => 'test']);
    
    $this->fake->assertUpserted('1');
    $this->fake->assertUpserted('1', fn($data) => $data['metadata']['name'] === 'test');
    $this->fake->assertNotUpserted('2');

    $record = $this->fake->fetch('1');
    expect($record)->toBeInstanceOf(VectorRecord::class);
    expect($record->id)->toBe('1');
});

it('records deletes', function () {
    $this->fake->upsert('1', [0.1]);
    $this->fake->delete('1');

    $this->fake->assertDeleted('1');
    $this->fake->assertNotDeleted('2');
});

it('records queries', function () {
    $this->fake->assertNotQueried();

    $this->fake->query([0.1])->get();

    $this->fake->assertQueried();
});

it('returns limited records in query', function () {
    $this->fake->upsert('1', [0.1]);
    $this->fake->upsert('2', [0.2]);
    $this->fake->upsert('3', [0.3]);

    $results = $this->fake->query([0.1])->topK(2)->get();

    expect($results)->toHaveCount(2);
});

it('records index creation and deletion', function () {
    $this->fake->createIndex('new_index', 1536);
    
    $this->fake->assertIndexCreated('new_index');
    expect($this->fake->listIndexes())->toContain('new_index');

    $this->fake->deleteIndex('new_index');
    $this->fake->assertIndexDeleted('new_index');
    expect($this->fake->listIndexes())->not->toContain('new_index');
});

it('can be flushed', function () {
    $this->fake->upsert('1', [0.1]);
    $this->fake->assertRecordCount(1);

    $this->fake->flush();

    $this->fake->assertRecordCount(0);
    $this->fake->assertFlushed();
});

it('acts as a proxy for stores', function () {
    $store = $this->fake->store('pinecone');
    
    expect($store)->toBe($this->fake);
});
