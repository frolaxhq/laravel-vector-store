<?php

declare(strict_types=1);

use Frolax\VectorStore\Contracts\VectorStoreContract;
use Frolax\VectorStore\Query\VectorQueryBuilder;

beforeEach(function () {
    $this->store = Mockery::mock(VectorStoreContract::class);
    $this->builder = new VectorQueryBuilder([0.1, 0.2, 0.3], $this->store);
});

it('sets and gets vector', function () {
    expect($this->builder->getVector())->toBe([0.1, 0.2, 0.3]);
});

it('adds where conditions correctly', function () {
    $this->builder->where('status', 'active')
        ->where('age', '>=', 18);

    $conditions = $this->builder->getConditions();

    expect($conditions)->toHaveCount(2);
    expect($conditions[0])->toBe([
        'field' => 'status',
        'op' => '=',
        'value' => 'active',
    ]);
    expect($conditions[1])->toBe([
        'field' => 'age',
        'op' => '>=',
        'value' => 18,
    ]);
});

it('supports whereIn condition', function () {
    $this->builder->whereIn('role', ['admin', 'user']);

    $conditions = $this->builder->getConditions();

    expect($conditions)->toHaveCount(1);
    expect($conditions[0]['op'])->toBe('in');
    expect($conditions[0]['value'])->toBe(['admin', 'user']);
});

it('supports whereNotIn condition', function () {
    $this->builder->whereNotIn('role', ['guest']);

    $conditions = $this->builder->getConditions();

    expect($conditions)->toHaveCount(1);
    expect($conditions[0]['op'])->toBe('not_in');
    expect($conditions[0]['value'])->toBe(['guest']);
});

it('supports whereBetween condition', function () {
    $this->builder->whereBetween('price', [10, 50]);

    $conditions = $this->builder->getConditions();

    expect($conditions)->toHaveCount(1);
    expect($conditions[0]['op'])->toBe('between');
    expect($conditions[0]['value'])->toBe([10, 50]);
});

it('supports whereNull condition', function () {
    $this->builder->whereNull('deleted_at');

    $conditions = $this->builder->getConditions();

    expect($conditions)->toHaveCount(1);
    expect($conditions[0]['op'])->toBe('null');
});

it('supports whereNotNull condition', function () {
    $this->builder->whereNotNull('published_at');

    $conditions = $this->builder->getConditions();

    expect($conditions)->toHaveCount(1);
    expect($conditions[0]['op'])->toBe('not_null');
});

it('handles basic query modifiers', function () {
    $this->builder->topK(20)
        ->minScore(0.8)
        ->collection('custom_index')
        ->includeVectors(false)
        ->includeMetadata(false);

    expect($this->builder->getTopK())->toBe(20);
    expect($this->builder->getMinScore())->toBe(0.8);
    expect($this->builder->getCollection())->toBe('custom_index');
    expect($this->builder->shouldIncludeVectors())->toBeFalse();
    expect($this->builder->shouldIncludeMetadata())->toBeFalse();
});

it('delegates execution to store', function () {
    $expectedCollection = collect(['result']);

    $this->store->shouldReceive('executeQuery')
        ->once()
        ->with($this->builder)
        ->andReturn($expectedCollection);

    $results = $this->builder->get();

    expect($results)->toBe($expectedCollection);
});

it('delegates pagination to store', function () {
    $this->store->shouldReceive('executeQuery')
        ->once()
        ->with($this->builder)
        ->andReturn(collect(['result1', 'result2']));

    $paginator = $this->builder->paginate(15);

    expect($paginator)->toBeArray();
    expect($paginator['data']->all())->toBe(['result1', 'result2']);
    expect($paginator['has_more'])->toBeFalse();
});
