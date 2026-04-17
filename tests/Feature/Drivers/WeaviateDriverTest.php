<?php

declare(strict_types=1);

use Frolax\VectorStore\Compilers\WeaviateFilterCompiler;
use Frolax\VectorStore\Drivers\WeaviateDriver;
use Frolax\VectorStore\ValueObjects\VectorRecord;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->driver = new WeaviateDriver(
        ['host' => 'http://test-weaviate:8080', 'api_key' => 'w-key', 'class' => 'TestClass'],
        new WeaviateFilterCompiler
    );
});

it('upserts a vector via PUT then POST if not found', function () {
    Http::fake([
        'http://test-weaviate:8080/v1/objects/TestClass/1*' => Http::response(null, 404),
        'http://test-weaviate:8080/v1/objects*' => Http::response(['id' => '1'], 200),
    ]);

    $this->driver->upsert('1', [0.1, 0.2], ['name' => 'test']);

    Http::assertSent(function ($request) {
        return str_starts_with($request->url(), 'http://test-weaviate:8080/v1/objects') &&
            $request->method() === 'POST' &&
            $request['class'] === 'TestClass' &&
            $request['id'] === '1' &&
            $request['vector'] === [0.1, 0.2] &&
            ((array) $request['properties'])['name'] === 'test' &&
            $request->header('Authorization')[0] === 'Bearer w-key';
    });
});

it('fetches a vector', function () {
    Http::fake([
        'http://test-weaviate:8080/v1/objects/TestClass/1*' => Http::response([
            'id' => '1',
            'vector' => [0.1, 0.2],
            'properties' => ['name' => 'test'],
        ]),
    ]);

    $record = $this->driver->fetch('1');

    expect($record)->toBeInstanceOf(VectorRecord::class);
    expect($record->id)->toBe('1');
    expect($record->vector)->toBe([0.1, 0.2]);
    expect($record->metadata)->toBe(['name' => 'test']);
});

it('fetches null when vector not found', function () {
    Http::fake([
        'http://test-weaviate:8080/v1/objects/TestClass/1*' => Http::response(null, 404),
    ]);

    $record = $this->driver->fetch('1');

    expect($record)->toBeNull();
});

it('queries vectors via GraphQL', function () {
    Http::fake([
        'http://test-weaviate:8080/v1/graphql*' => Http::response([
            'data' => [
                'Get' => [
                    'TestClass' => [
                        [
                            'name' => 'test',
                            '_additional' => ['id' => '1', 'certainty' => 0.9, 'vector' => [0.1, 0.2]],
                        ],
                    ],
                ],
            ],
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
    expect($results[0]->metadata)->toBe(['name' => 'test']);
    expect($results[0]->vector)->toBe([0.1, 0.2]);

    Http::assertSent(function ($request) {
        return str_starts_with($request->url(), 'http://test-weaviate:8080/v1/graphql') &&
            str_contains($request['query'], 'nearVector:') &&
            str_contains($request['query'], 'operator: "Equal"') &&
            str_contains($request['query'], 'valueText: "test"');
    });
});
