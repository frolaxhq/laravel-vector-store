<?php

declare(strict_types=1);

use Frolax\VectorStore\Compilers\QdrantFilterCompiler;
use Frolax\VectorStore\Drivers\QdrantDriver;
use Frolax\VectorStore\ValueObjects\VectorRecord;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->driver = new QdrantDriver(
        ['host' => 'http://test-qdrant:6333', 'api_key' => 'q-key', 'collection' => 'test_col'],
        new QdrantFilterCompiler()
    );
});

it('upserts a vector', function () {
    Http::fake([
        'http://test-qdrant:6333/collections/test_col/points' => Http::response(['status' => 'ok']),
    ]);

    $this->driver->upsert('123e4567-e89b-12d3-a456-426614174000', [0.1, 0.2], ['name' => 'test']);

    Http::assertSent(function ($request) {
        return $request->url() === 'http://test-qdrant:6333/collections/test_col/points' &&
            $request->method() === 'PUT' &&
            $request['points'][0]['id'] === '123e4567-e89b-12d3-a456-426614174000' &&
            $request['points'][0]['vector'] === [0.1, 0.2] &&
            ((array) $request['points'][0]['payload'])['name'] === 'test' &&
            $request->header('api-key')[0] === 'q-key';
    });
});

it('fetches a vector', function () {
    $id = '123e4567-e89b-12d3-a456-426614174000';
    Http::fake([
        "http://test-qdrant:6333/collections/test_col/points/{$id}" => Http::response([
            'result' => [
                'id' => $id,
                'vector' => [0.1, 0.2],
                'payload' => ['name' => 'test'],
            ],
        ]),
    ]);

    $record = $this->driver->fetch($id);

    expect($record)->toBeInstanceOf(VectorRecord::class);
    expect($record->id)->toBe($id);
    expect($record->vector)->toBe([0.1, 0.2]);
    expect($record->metadata)->toBe(['name' => 'test']);
});

it('fetches null when vector not found', function () {
    $id = '123e4567-e89b-12d3-a456-426614174000';
    Http::fake([
        "http://test-qdrant:6333/collections/test_col/points/{$id}" => Http::response(null, 404),
    ]);

    $record = $this->driver->fetch($id);

    expect($record)->toBeNull();
});

it('queries vectors', function () {
    Http::fake([
        'http://test-qdrant:6333/collections/test_col/points/search' => Http::response([
            'result' => [
                ['id' => '1', 'score' => 0.9, 'payload' => ['name' => 'test'], 'vector' => [0.1, 0.2]],
            ],
        ]),
    ]);

    $results = $this->driver->query([0.1, 0.2])
        ->topK(5)
        ->where('name', 'test')
        ->includeVectors()
        ->get();

    expect($results)->toHaveCount(1);
    expect($results[0]->id)->toBe('1');
    expect($results[0]->score)->toBe(0.9);

    Http::assertSent(function ($request) {
        return $request->url() === 'http://test-qdrant:6333/collections/test_col/points/search' &&
            $request['vector'] === [0.1, 0.2] &&
            $request['limit'] === 5 &&
            $request['with_payload'] === true &&
            $request['with_vector'] === true &&
            $request['filter']['must'][0]['key'] === 'name' &&
            $request['filter']['must'][0]['match']['value'] === 'test';
    });
});
