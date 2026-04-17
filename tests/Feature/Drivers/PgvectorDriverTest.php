<?php

declare(strict_types=1);

use Frolax\VectorStore\Compilers\PgvectorFilterCompiler;
use Frolax\VectorStore\Drivers\PgvectorDriver;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->connection = Mockery::mock(Connection::class);
    $this->builder = Mockery::mock(Builder::class);

    $this->connection->shouldReceive('table')->with('test_table')->andReturn($this->builder);

    DB::shouldReceive('connection')->with('test_conn')->andReturn($this->connection);
    DB::shouldReceive('raw')->andReturnUsing(fn ($val) => new Expression($val));

    $this->driver = new PgvectorDriver(
        ['connection' => 'test_conn', 'table' => 'test_table', 'dimensions' => 3, 'metric' => 'cosine'],
        new PgvectorFilterCompiler
    );
});

it('upserts a vector', function () {
    $this->builder->shouldReceive('updateOrInsert')->once()->with(
        ['id' => '1'],
        Mockery::on(function ($values) {
            return $values['vector'] === '[0.1,0.2]' &&
                   $values['metadata'] === '{"name":"test"}' &&
                   isset($values['updated_at']);
        })
    )->andReturn(true);

    $this->driver->upsert('1', [0.1, 0.2], ['name' => 'test']);
});

it('fetches a vector', function () {
    $row = (object) [
        'id' => '1',
        'vector' => '[0.1,0.2]',
        'metadata' => '{"name":"test"}',
    ];

    $this->builder->shouldReceive('where')->with('id', '1')->andReturnSelf();
    $this->builder->shouldReceive('first')->andReturn($row);

    $record = $this->driver->fetch('1');

    expect($record->id)->toBe('1');
    expect($record->vector)->toBe([0.1, 0.2]);
    expect($record->metadata)->toBe(['name' => 'test']);
});

it('queries vectors', function () {
    $row = (object) [
        'id' => '1',
        'score' => 0.1, // Distance 0.1 -> Score 0.9 for cosine
        'metadata' => '{"name":"test"}',
        'vector' => '[0.1,0.2]',
    ];

    $this->builder->shouldReceive('select')->andReturnSelf();
    $this->builder->shouldReceive('addBinding')->andReturnSelf();
    $this->builder->shouldReceive('selectRaw')->with('vector <=> ? as score', ['[0.1,0.2]'])->andReturnSelf();
    $this->builder->shouldReceive('whereRaw')->andReturnSelf();
    $this->builder->shouldReceive('orderBy')->andReturnSelf();
    $this->builder->shouldReceive('limit')->with(5)->andReturnSelf();
    $this->builder->shouldReceive('get')->andReturn(collect([$row]));

    $results = $this->driver->query([0.1, 0.2])
        ->topK(5)
        ->where('name', 'test')
        ->includeVectors()
        ->get();

    expect($results)->toHaveCount(1);
    expect($results[0]->id)->toBe('1');
    expect($results[0]->score)->toBe(0.9);
    expect($results[0]->metadata)->toBe(['name' => 'test']);
    expect($results[0]->vector)->toBe([0.1, 0.2]);
});
