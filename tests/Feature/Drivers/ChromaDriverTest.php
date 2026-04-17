<?php

declare(strict_types=1);

use Frolax\VectorStore\Compilers\ChromaFilterCompiler;
use Frolax\VectorStore\Drivers\ChromaDriver;
use Frolax\VectorStore\ValueObjects\VectorRecord;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->driver = new ChromaDriver(
        ['host' => 'http://test-chroma:8000', 'collection' => 'test_col'],
        new ChromaFilterCompiler
    );

    // Mock collection ID resolution
    Http::fake([
        'http://test-chroma:8000/api/v1/collections/test_col' => Http::response(['id' => 'col-123']),
    ]);
});

it('upserts a vector', function () {
    Http::fake([
        'http://test-chroma:8000/api/v1/collections/col-123/upsert' => Http::response(true),
    ]);

    $this->driver->upsert('1', [0.1, 0.2], ['name' => 'test']);

    Http::assertSent(function ($request) {
        return $request->url() === 'http://test-chroma:8000/api/v1/collections/col-123/upsert' &&
            $request['ids'] === ['1'] &&
            $request['embeddings'] === [[0.1, 0.2]] &&
            ((array) $request['metadatas'][0])['name'] === 'test';
    });
});

it('fetches a vector', function () {
    Http::fake([
        'http://test-chroma:8000/api/v1/collections/col-123/get' => Http::response([
            'ids' => ['1'],
            'embeddings' => [[0.1, 0.2]],
            'metadatas' => [['name' => 'test']],
        ]),
    ]);

    $record = $this->driver->fetch('1');

    expect($record)->toBeInstanceOf(VectorRecord::class);
    expect($record->id)->toBe('1');
    expect($record->vector)->toBe([0.1, 0.2]);
    expect($record->metadata)->toBe(['name' => 'test']);

    Http::assertSent(function ($request) {
        return $request->url() === 'http://test-chroma:8000/api/v1/collections/col-123/get' &&
            $request['ids'] === ['1'];
    });
});

it('queries vectors', function () {
    Http::fake([
        'http://test-chroma:8000/api/v1/collections/col-123/query' => Http::response([
            'ids' => [['1']],
            'distances' => [[0.1]], // distance 0.1 means score 0.9
            'metadatas' => [[['name' => 'test']]],
            'embeddings' => [[[0.1, 0.2]]],
        ]),
    ]);

    $results = $this->driver->query([0.1, 0.2])
        ->topK(2)
        ->where('name', 'test')
        ->includeVectors()
        ->get();

    expect($results)->toHaveCount(1);
    expect($results[0]->id)->toBe('1');
    expect($results[0]->score)->toBe(0.9);
    expect($results[0]->vector)->toBe([0.1, 0.2]);

    Http::assertSent(function ($request) {
        return $request->url() === 'http://test-chroma:8000/api/v1/collections/col-123/query' &&
            $request['query_embeddings'] === [[0.1, 0.2]] &&
            $request['n_results'] === 2 &&
            $request['where'] === ['name' => ['$eq' => 'test']];
    });
});
