<?php

declare(strict_types=1);

use Frolax\VectorStore\Compilers\PineconeFilterCompiler;
use Frolax\VectorStore\Drivers\PineconeDriver;
use Frolax\VectorStore\ValueObjects\VectorRecord;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->driver = new PineconeDriver(
        ['api_key' => 'test-key', 'host' => 'https://test-host.pinecone.io', 'namespace' => 'test-ns'],
        new PineconeFilterCompiler
    );
});

it('upserts a vector', function () {
    Http::fake([
        'https://test-host.pinecone.io/vectors/upsert' => Http::response(['upsertedCount' => 1]),
    ]);

    $this->driver->upsert('1', [0.1, 0.2], ['name' => 'test']);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://test-host.pinecone.io/vectors/upsert' &&
            $request['vectors'][0]['id'] === '1' &&
            $request['vectors'][0]['values'] === [0.1, 0.2] &&
            ((array) $request['vectors'][0]['metadata'])['name'] === 'test' &&
            $request['namespace'] === 'test-ns' &&
            $request->header('Api-Key')[0] === 'test-key';
    });
});

it('upserts batch of vectors', function () {
    Http::fake([
        'https://test-host.pinecone.io/vectors/upsert' => Http::response(['upsertedCount' => 2]),
    ]);

    $this->driver->upsertBatch([
        ['id' => '1', 'vector' => [0.1, 0.2], 'metadata' => ['name' => 'one']],
        ['id' => '2', 'vector' => [0.3, 0.4]],
    ]);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://test-host.pinecone.io/vectors/upsert' &&
            count($request['vectors']) === 2 &&
            $request['vectors'][0]['id'] === '1' &&
            $request['vectors'][1]['id'] === '2';
    });
});

it('deletes vectors', function () {
    Http::fake([
        'https://test-host.pinecone.io/vectors/delete' => Http::response([]),
    ]);

    $this->driver->delete(['1', '2']);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://test-host.pinecone.io/vectors/delete' &&
            $request['ids'] === ['1', '2'] &&
            $request['namespace'] === 'test-ns';
    });
});

it('fetches a vector', function () {
    Http::fake([
        'https://test-host.pinecone.io/vectors/fetch*' => Http::response([
            'vectors' => [
                '1' => ['id' => '1', 'values' => [0.1, 0.2], 'metadata' => ['name' => 'one']],
            ],
        ]),
    ]);

    $record = $this->driver->fetch('1');

    expect($record)->toBeInstanceOf(VectorRecord::class);
    expect($record->id)->toBe('1');
    expect($record->vector)->toBe([0.1, 0.2]);
    expect($record->metadata)->toBe(['name' => 'one']);

    Http::assertSent(function ($request) {
        return str_starts_with($request->url(), 'https://test-host.pinecone.io/vectors/fetch') &&
            str_contains($request->url(), 'ids') &&
            str_contains($request->url(), 'namespace=test-ns');
    });
});

it('queries vectors', function () {
    Http::fake([
        'https://test-host.pinecone.io/query' => Http::response([
            'matches' => [
                ['id' => '1', 'score' => 0.9, 'metadata' => ['name' => 'one'], 'values' => [0.1, 0.2]],
                ['id' => '2', 'score' => 0.8, 'metadata' => ['name' => 'two'], 'values' => [0.3, 0.4]],
            ],
        ]),
    ]);

    $results = $this->driver->query([0.1, 0.2])
        ->topK(2)
        ->where('category', 'test')
        ->includeVectors()
        ->get();

    expect($results)->toHaveCount(2);
    expect($results[0]->id)->toBe('1');
    expect($results[0]->score)->toBe(0.9);
    expect($results[0]->vector)->toBe([0.1, 0.2]);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://test-host.pinecone.io/query' &&
            $request['vector'] === [0.1, 0.2] &&
            $request['topK'] === 2 &&
            $request['filter'] === ['category' => ['$eq' => 'test']] &&
            $request['includeMetadata'] === true &&
            $request['includeValues'] === true;
    });
});
