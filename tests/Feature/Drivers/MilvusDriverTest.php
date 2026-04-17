<?php

declare(strict_types=1);

use Frolax\VectorStore\Compilers\MilvusFilterCompiler;
use Frolax\VectorStore\Drivers\MilvusDriver;
use Frolax\VectorStore\ValueObjects\VectorRecord;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->driver = new MilvusDriver(
        ['host' => 'http://test-milvus:19530', 'token' => 'm-token', 'collection' => 'test_col'],
        new MilvusFilterCompiler()
    );
});

it('upserts a vector', function () {
    Http::fake([
        'http://test-milvus:19530/v2/vectordb/entities/upsert' => Http::response(['code' => 0]),
    ]);

    $this->driver->upsert('1', [0.1, 0.2], ['name' => 'test']);

    Http::assertSent(function ($request) {
        return $request->url() === 'http://test-milvus:19530/v2/vectordb/entities/upsert' &&
            $request['collectionName'] === 'test_col' &&
            $request['data'][0]['id'] === '1' &&
            $request['data'][0]['vector'] === [0.1, 0.2] &&
            $request['data'][0]['name'] === 'test' &&
            $request->header('Authorization')[0] === 'Bearer m-token';
    });
});

it('fetches a vector', function () {
    Http::fake([
        'http://test-milvus:19530/v2/vectordb/entities/get' => Http::response([
            'data' => [
                ['id' => '1', 'vector' => [0.1, 0.2], 'name' => 'test'],
            ],
        ]),
    ]);

    $record = $this->driver->fetch('1');

    expect($record)->toBeInstanceOf(VectorRecord::class);
    expect($record->id)->toBe('1');
    expect($record->vector)->toBe([0.1, 0.2]);
    expect($record->metadata)->toBe(['name' => 'test']); // System fields are filtered

    Http::assertSent(function ($request) {
        return $request->url() === 'http://test-milvus:19530/v2/vectordb/entities/get' &&
            $request['id'] === ['1'];
    });
});

it('queries vectors', function () {
    Http::fake([
        'http://test-milvus:19530/v2/vectordb/entities/search' => Http::response([
            'data' => [
                ['id' => '1', 'distance' => 0.9, 'name' => 'test', 'vector' => [0.1, 0.2]],
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
        return $request->url() === 'http://test-milvus:19530/v2/vectordb/entities/search' &&
            $request['data'] === [[0.1, 0.2]] &&
            $request['limit'] === 2 &&
            $request['filter'] === "name == 'test'";
    });
});
