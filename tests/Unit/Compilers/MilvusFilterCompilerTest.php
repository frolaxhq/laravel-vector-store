<?php

declare(strict_types=1);

use Frolax\VectorStore\Compilers\MilvusFilterCompiler;
use Frolax\VectorStore\Exceptions\FilterCompilationException;

beforeEach(function () {
    $this->compiler = new MilvusFilterCompiler;
});

it('returns empty string for no conditions', function () {
    expect($this->compiler->compile([]))->toBe('');
});

it('compiles equality condition', function () {
    $result = $this->compiler->compile([
        ['field' => 'category', 'op' => '=', 'value' => 'shoes'],
    ]);
    expect($result)->toBe("category == 'shoes'");
});

it('compiles not-equal condition', function () {
    $result = $this->compiler->compile([
        ['field' => 'status', 'op' => '!=', 'value' => 'archived'],
    ]);
    expect($result)->toBe("status != 'archived'");
});

it('compiles numeric comparison', function () {
    $result = $this->compiler->compile([
        ['field' => 'price', 'op' => '>=', 'value' => 49.99],
    ]);
    expect($result)->toBe('price >= 49.99');
});

it('compiles integer comparison', function () {
    $result = $this->compiler->compile([
        ['field' => 'count', 'op' => '>', 'value' => 10],
    ]);
    expect($result)->toBe('count > 10');
});

it('compiles in condition', function () {
    $result = $this->compiler->compile([
        ['field' => 'brand', 'op' => 'in', 'value' => ['nike', 'adidas']],
    ]);
    expect($result)->toBe("brand in ['nike', 'adidas']");
});

it('compiles not-in condition', function () {
    $result = $this->compiler->compile([
        ['field' => 'status', 'op' => 'not_in', 'value' => ['archived']],
    ]);
    expect($result)->toBe("status not in ['archived']");
});

it('compiles between condition', function () {
    $result = $this->compiler->compile([
        ['field' => 'stock', 'op' => 'between', 'value' => [1, 500]],
    ]);
    expect($result)->toBe('(stock >= 1 and stock <= 500)');
});

it('compiles null condition', function () {
    $result = $this->compiler->compile([
        ['field' => 'deleted_at', 'op' => 'null'],
    ]);
    expect($result)->toBe('deleted_at == null');
});

it('compiles not-null condition', function () {
    $result = $this->compiler->compile([
        ['field' => 'published_at', 'op' => 'not_null'],
    ]);
    expect($result)->toBe('published_at != null');
});

it('joins multiple conditions with and', function () {
    $result = $this->compiler->compile([
        ['field' => 'category', 'op' => '=', 'value' => 'shoes'],
        ['field' => 'price', 'op' => '>=', 'value' => 49.99],
    ]);
    expect($result)->toBe("category == 'shoes' and price >= 49.99");
});

it('escapes single quotes in string values', function () {
    $result = $this->compiler->compile([
        ['field' => 'name', 'op' => '=', 'value' => "it's"],
    ]);
    expect($result)->toBe("name == 'it\\'s'");
});

it('throws on unsupported operator', function () {
    $this->compiler->compile([
        ['field' => 'x', 'op' => 'like', 'value' => '%foo%'],
    ]);
})->throws(FilterCompilationException::class);
