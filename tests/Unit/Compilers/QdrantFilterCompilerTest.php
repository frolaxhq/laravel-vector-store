<?php

declare(strict_types=1);

use Frolax\VectorStore\Compilers\QdrantFilterCompiler;
use Frolax\VectorStore\Exceptions\FilterCompilationException;

beforeEach(function () {
    $this->compiler = new QdrantFilterCompiler;
});

it('returns empty array for no conditions', function () {
    expect($this->compiler->compile([]))->toBe([]);
});

it('compiles equality condition into must match', function () {
    $result = $this->compiler->compile([
        ['field' => 'category', 'op' => '=', 'value' => 'shoes'],
    ]);

    expect($result)->toBe([
        'must' => [
            ['key' => 'category', 'match' => ['value' => 'shoes']],
        ],
    ]);
});

it('compiles not-equal condition into must_not match', function () {
    $result = $this->compiler->compile([
        ['field' => 'status', 'op' => '!=', 'value' => 'archived'],
    ]);

    expect($result)->toBe([
        'must_not' => [
            ['key' => 'status', 'match' => ['value' => 'archived']],
        ],
    ]);
});

it('compiles greater-than condition into range', function () {
    $result = $this->compiler->compile([
        ['field' => 'price', 'op' => '>', 'value' => 10.0],
    ]);

    expect($result)->toBe([
        'must' => [
            ['key' => 'price', 'range' => ['gt' => 10.0]],
        ],
    ]);
});

it('compiles greater-than-or-equal condition into range', function () {
    $result = $this->compiler->compile([
        ['field' => 'price', 'op' => '>=', 'value' => 49.99],
    ]);

    expect($result)->toBe([
        'must' => [
            ['key' => 'price', 'range' => ['gte' => 49.99]],
        ],
    ]);
});

it('compiles less-than condition into range', function () {
    $result = $this->compiler->compile([
        ['field' => 'price', 'op' => '<', 'value' => 100.0],
    ]);

    expect($result)->toBe([
        'must' => [
            ['key' => 'price', 'range' => ['lt' => 100.0]],
        ],
    ]);
});

it('compiles less-than-or-equal condition into range', function () {
    $result = $this->compiler->compile([
        ['field' => 'price', 'op' => '<=', 'value' => 99.99],
    ]);

    expect($result)->toBe([
        'must' => [
            ['key' => 'price', 'range' => ['lte' => 99.99]],
        ],
    ]);
});

it('compiles in condition into match any', function () {
    $result = $this->compiler->compile([
        ['field' => 'brand', 'op' => 'in', 'value' => ['nike', 'adidas']],
    ]);

    expect($result)->toBe([
        'must' => [
            ['key' => 'brand', 'match' => ['any' => ['nike', 'adidas']]],
        ],
    ]);
});

it('compiles not-in condition into must_not match any', function () {
    $result = $this->compiler->compile([
        ['field' => 'status', 'op' => 'not_in', 'value' => ['archived', 'deleted']],
    ]);

    expect($result)->toBe([
        'must_not' => [
            ['key' => 'status', 'match' => ['any' => ['archived', 'deleted']]],
        ],
    ]);
});

it('compiles between condition into range with gte and lte', function () {
    $result = $this->compiler->compile([
        ['field' => 'stock', 'op' => 'between', 'value' => [1, 500]],
    ]);

    expect($result)->toBe([
        'must' => [
            ['key' => 'stock', 'range' => ['gte' => 1, 'lte' => 500]],
        ],
    ]);
});

it('compiles null condition into is_null must', function () {
    $result = $this->compiler->compile([
        ['field' => 'deleted_at', 'op' => 'null'],
    ]);

    expect($result)->toBe([
        'must' => [
            ['is_null' => ['key' => 'deleted_at']],
        ],
    ]);
});

it('compiles not-null condition into is_null must_not', function () {
    $result = $this->compiler->compile([
        ['field' => 'published_at', 'op' => 'not_null'],
    ]);

    expect($result)->toBe([
        'must_not' => [
            ['is_null' => ['key' => 'published_at']],
        ],
    ]);
});

it('groups must and must_not conditions together', function () {
    $result = $this->compiler->compile([
        ['field' => 'category', 'op' => '=', 'value' => 'shoes'],
        ['field' => 'status', 'op' => '!=', 'value' => 'archived'],
        ['field' => 'price', 'op' => '>=', 'value' => 49.99],
    ]);

    expect($result)->toBe([
        'must' => [
            ['key' => 'category', 'match' => ['value' => 'shoes']],
            ['key' => 'price', 'range' => ['gte' => 49.99]],
        ],
        'must_not' => [
            ['key' => 'status', 'match' => ['value' => 'archived']],
        ],
    ]);
});

it('throws on unsupported operator', function () {
    $this->compiler->compile([
        ['field' => 'x', 'op' => 'like', 'value' => '%foo%'],
    ]);
})->throws(FilterCompilationException::class);
