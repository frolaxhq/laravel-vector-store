<?php

declare(strict_types=1);

use Frolax\VectorStore\Compilers\WeaviateFilterCompiler;
use Frolax\VectorStore\Exceptions\FilterCompilationException;

beforeEach(function () {
    $this->compiler = new WeaviateFilterCompiler;
});

it('returns empty array for no conditions', function () {
    expect($this->compiler->compile([]))->toBe([]);
});

it('compiles equality condition with string value', function () {
    $result = $this->compiler->compile([
        ['field' => 'category', 'op' => '=', 'value' => 'shoes'],
    ]);

    expect($result)->toBe([
        'path' => ['category'],
        'operator' => 'Equal',
        'valueText' => 'shoes',
    ]);
});

it('compiles equality condition with numeric value', function () {
    $result = $this->compiler->compile([
        ['field' => 'price', 'op' => '=', 'value' => 49.99],
    ]);

    expect($result)->toBe([
        'path' => ['price'],
        'operator' => 'Equal',
        'valueNumber' => 49.99,
    ]);
});

it('compiles equality condition with integer value', function () {
    $result = $this->compiler->compile([
        ['field' => 'count', 'op' => '=', 'value' => 42],
    ]);

    expect($result)->toBe([
        'path' => ['count'],
        'operator' => 'Equal',
        'valueInt' => 42,
    ]);
});

it('compiles not-equal condition', function () {
    $result = $this->compiler->compile([
        ['field' => 'status', 'op' => '!=', 'value' => 'archived'],
    ]);

    expect($result)->toBe([
        'path' => ['status'],
        'operator' => 'NotEqual',
        'valueText' => 'archived',
    ]);
});

it('compiles greater-than condition', function () {
    $result = $this->compiler->compile([
        ['field' => 'price', 'op' => '>', 'value' => 10.0],
    ]);

    expect($result)->toBe([
        'path' => ['price'],
        'operator' => 'GreaterThan',
        'valueNumber' => 10.0,
    ]);
});

it('compiles greater-than-or-equal condition', function () {
    $result = $this->compiler->compile([
        ['field' => 'price', 'op' => '>=', 'value' => 49.99],
    ]);

    expect($result)->toBe([
        'path' => ['price'],
        'operator' => 'GreaterThanEqual',
        'valueNumber' => 49.99,
    ]);
});

it('compiles less-than condition', function () {
    $result = $this->compiler->compile([
        ['field' => 'price', 'op' => '<', 'value' => 100.0],
    ]);

    expect($result)->toBe([
        'path' => ['price'],
        'operator' => 'LessThan',
        'valueNumber' => 100.0,
    ]);
});

it('compiles less-than-or-equal condition', function () {
    $result = $this->compiler->compile([
        ['field' => 'price', 'op' => '<=', 'value' => 99.99],
    ]);

    expect($result)->toBe([
        'path' => ['price'],
        'operator' => 'LessThanEqual',
        'valueNumber' => 99.99,
    ]);
});

it('compiles in condition as Or of Equals', function () {
    $result = $this->compiler->compile([
        ['field' => 'brand', 'op' => 'in', 'value' => ['nike', 'adidas']],
    ]);

    expect($result)->toBe([
        'operator' => 'Or',
        'operands' => [
            ['path' => ['brand'], 'operator' => 'Equal', 'valueText' => 'nike'],
            ['path' => ['brand'], 'operator' => 'Equal', 'valueText' => 'adidas'],
        ],
    ]);
});

it('compiles not-in condition as multiple NotEquals', function () {
    $result = $this->compiler->compile([
        ['field' => 'status', 'op' => 'not_in', 'value' => ['archived', 'deleted']],
    ]);

    // not_in with multiple values and nothing else wraps in And
    expect($result)->toBe([
        'operator' => 'And',
        'operands' => [
            ['path' => ['status'], 'operator' => 'NotEqual', 'valueText' => 'archived'],
            ['path' => ['status'], 'operator' => 'NotEqual', 'valueText' => 'deleted'],
        ],
    ]);
});

it('compiles between condition as two range clauses', function () {
    $result = $this->compiler->compile([
        ['field' => 'stock', 'op' => 'between', 'value' => [1, 500]],
    ]);

    expect($result)->toBe([
        'operator' => 'And',
        'operands' => [
            ['path' => ['stock'], 'operator' => 'GreaterThanEqual', 'valueInt' => 1],
            ['path' => ['stock'], 'operator' => 'LessThanEqual', 'valueInt' => 500],
        ],
    ]);
});

it('compiles null condition', function () {
    $result = $this->compiler->compile([
        ['field' => 'deleted_at', 'op' => 'null'],
    ]);

    expect($result)->toBe([
        'path' => ['deleted_at'],
        'operator' => 'IsNull',
        'valueBoolean' => true,
    ]);
});

it('compiles not-null condition', function () {
    $result = $this->compiler->compile([
        ['field' => 'published_at', 'op' => 'not_null'],
    ]);

    expect($result)->toBe([
        'path' => ['published_at'],
        'operator' => 'IsNull',
        'valueBoolean' => false,
    ]);
});

it('wraps multiple conditions in And operator', function () {
    $result = $this->compiler->compile([
        ['field' => 'category', 'op' => '=', 'value' => 'shoes'],
        ['field' => 'price', 'op' => '>=', 'value' => 49.99],
    ]);

    expect($result)->toBe([
        'operator' => 'And',
        'operands' => [
            ['path' => ['category'], 'operator' => 'Equal', 'valueText' => 'shoes'],
            ['path' => ['price'], 'operator' => 'GreaterThanEqual', 'valueNumber' => 49.99],
        ],
    ]);
});

it('throws on unsupported operator', function () {
    $this->compiler->compile([
        ['field' => 'x', 'op' => 'like', 'value' => '%foo%'],
    ]);
})->throws(FilterCompilationException::class);
