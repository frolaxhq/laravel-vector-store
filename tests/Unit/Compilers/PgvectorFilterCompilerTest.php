<?php

declare(strict_types=1);

use Frolax\VectorStore\Compilers\PgvectorFilterCompiler;
use Frolax\VectorStore\Exceptions\FilterCompilationException;

beforeEach(function () {
    $this->compiler = new PgvectorFilterCompiler('metadata');
});

it('returns empty sql for no conditions', function () {
    $result = $this->compiler->compile([]);
    expect($result)->toBe(['sql' => '', 'bindings' => []]);
});

it('compiles equality condition with string', function () {
    $result = $this->compiler->compile([
        ['field' => 'category', 'op' => '=', 'value' => 'shoes'],
    ]);
    expect($result['sql'])->toBe("((metadata->>'category'))::text = ?");
    expect($result['bindings'])->toBe(['shoes']);
});

it('compiles equality condition with float', function () {
    $result = $this->compiler->compile([
        ['field' => 'price', 'op' => '>=', 'value' => 49.99],
    ]);
    expect($result['sql'])->toBe("((metadata->>'price'))::float >= ?");
    expect($result['bindings'])->toBe([49.99]);
});

it('compiles equality condition with integer', function () {
    $result = $this->compiler->compile([
        ['field' => 'count', 'op' => '=', 'value' => 42],
    ]);
    expect($result['sql'])->toBe("((metadata->>'count'))::integer = ?");
    expect($result['bindings'])->toBe([42]);
});

it('compiles in condition', function () {
    $result = $this->compiler->compile([
        ['field' => 'brand', 'op' => 'in', 'value' => ['nike', 'adidas']],
    ]);
    expect($result['sql'])->toBe("((metadata->>'brand'))::text IN (?, ?)");
    expect($result['bindings'])->toBe(['nike', 'adidas']);
});

it('compiles not-in condition', function () {
    $result = $this->compiler->compile([
        ['field' => 'status', 'op' => 'not_in', 'value' => ['archived']],
    ]);
    expect($result['sql'])->toBe("((metadata->>'status'))::text NOT IN (?)");
    expect($result['bindings'])->toBe(['archived']);
});

it('compiles between condition', function () {
    $result = $this->compiler->compile([
        ['field' => 'stock', 'op' => 'between', 'value' => [1, 500]],
    ]);
    expect($result['sql'])->toBe("((metadata->>'stock'))::integer BETWEEN ? AND ?");
    expect($result['bindings'])->toBe([1, 500]);
});

it('compiles null condition', function () {
    $result = $this->compiler->compile([
        ['field' => 'deleted_at', 'op' => 'null'],
    ]);
    expect($result['sql'])->toBe("(metadata->>'deleted_at') IS NULL");
    expect($result['bindings'])->toBe([]);
});

it('compiles not-null condition', function () {
    $result = $this->compiler->compile([
        ['field' => 'published_at', 'op' => 'not_null'],
    ]);
    expect($result['sql'])->toBe("(metadata->>'published_at') IS NOT NULL");
    expect($result['bindings'])->toBe([]);
});

it('joins multiple conditions with AND', function () {
    $result = $this->compiler->compile([
        ['field' => 'category', 'op' => '=', 'value' => 'shoes'],
        ['field' => 'price', 'op' => '>=', 'value' => 49.99],
    ]);
    expect($result['sql'])->toBe("((metadata->>'category'))::text = ? AND ((metadata->>'price'))::float >= ?");
    expect($result['bindings'])->toBe(['shoes', 49.99]);
});

it('throws on unsupported operator', function () {
    $this->compiler->compile([
        ['field' => 'x', 'op' => 'like', 'value' => '%foo%'],
    ]);
})->throws(FilterCompilationException::class);
