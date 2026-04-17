<?php

declare(strict_types=1);

use Frolax\VectorStore\Compilers\ChromaFilterCompiler;
use Frolax\VectorStore\Exceptions\FilterCompilationException;

beforeEach(function () {
    $this->compiler = new ChromaFilterCompiler;
});

it('returns empty array for no conditions', function () {
    expect($this->compiler->compile([]))->toBe([]);
});

it('compiles equality condition', function () {
    $result = $this->compiler->compile([
        ['field' => 'category', 'op' => '=', 'value' => 'shoes'],
    ]);
    expect($result)->toBe(['category' => ['$eq' => 'shoes']]);
});

it('compiles not-equal condition', function () {
    $result = $this->compiler->compile([
        ['field' => 'status', 'op' => '!=', 'value' => 'archived'],
    ]);
    expect($result)->toBe(['status' => ['$ne' => 'archived']]);
});

it('compiles greater-than-or-equal condition', function () {
    $result = $this->compiler->compile([
        ['field' => 'price', 'op' => '>=', 'value' => 49.99],
    ]);
    expect($result)->toBe(['price' => ['$gte' => 49.99]]);
});

it('compiles in condition', function () {
    $result = $this->compiler->compile([
        ['field' => 'brand', 'op' => 'in', 'value' => ['nike', 'adidas']],
    ]);
    expect($result)->toBe(['brand' => ['$in' => ['nike', 'adidas']]]);
});

it('compiles not-in condition', function () {
    $result = $this->compiler->compile([
        ['field' => 'status', 'op' => 'not_in', 'value' => ['archived', 'deleted']],
    ]);
    expect($result)->toBe(['status' => ['$nin' => ['archived', 'deleted']]]);
});

it('compiles between condition', function () {
    $result = $this->compiler->compile([
        ['field' => 'stock', 'op' => 'between', 'value' => [1, 500]],
    ]);
    expect($result)->toBe([
        '$and' => [
            ['stock' => ['$gte' => 1]],
            ['stock' => ['$lte' => 500]],
        ],
    ]);
});

it('compiles null condition', function () {
    $result = $this->compiler->compile([
        ['field' => 'deleted_at', 'op' => 'null'],
    ]);
    expect($result)->toBe(['deleted_at' => ['$eq' => null]]);
});

it('compiles not-null condition', function () {
    $result = $this->compiler->compile([
        ['field' => 'published_at', 'op' => 'not_null'],
    ]);
    expect($result)->toBe(['published_at' => ['$ne' => null]]);
});

it('wraps multiple conditions in $and', function () {
    $result = $this->compiler->compile([
        ['field' => 'category', 'op' => '=', 'value' => 'shoes'],
        ['field' => 'price', 'op' => '>=', 'value' => 49.99],
    ]);
    expect($result)->toBe([
        '$and' => [
            ['category' => ['$eq' => 'shoes']],
            ['price' => ['$gte' => 49.99]],
        ],
    ]);
});

it('throws on unsupported operator', function () {
    $this->compiler->compile([
        ['field' => 'x', 'op' => 'like', 'value' => '%foo%'],
    ]);
})->throws(FilterCompilationException::class);
