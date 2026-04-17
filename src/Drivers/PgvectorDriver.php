<?php

declare(strict_types=1);

namespace Frolax\VectorStore\Drivers;

use Frolax\VectorStore\Compilers\PgvectorFilterCompiler;
use Frolax\VectorStore\Contracts\VectorStoreContract;
use Frolax\VectorStore\Exceptions\IndexNotFoundException;
use Frolax\VectorStore\Exceptions\VectorStoreException;
use Frolax\VectorStore\Query\VectorQueryBuilder;
use Frolax\VectorStore\ValueObjects\VectorRecord;
use Frolax\VectorStore\ValueObjects\VectorResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class PgvectorDriver implements VectorStoreContract
{
    protected string $connection;

    protected string $table;

    protected int $dimensions;

    protected string $metric;

    protected PgvectorFilterCompiler $compiler;

    /**
     * @param  array{connection?: string, table?: string, dimensions?: int, metric?: string} $config
     */
    public function __construct(array $config, PgvectorFilterCompiler $compiler)
    {
        $this->connection = $config['connection'] ?? 'pgsql';
        $this->table = $config['table'] ?? 'vector_records';
        $this->dimensions = (int) ($config['dimensions'] ?? 1536);
        $this->metric = $config['metric'] ?? 'cosine';
        $this->compiler = $compiler;
    }

    public function upsert(string $id, array $vector, array $metadata = []): void
    {
        Log::debug('VectorStore[pgvector]: upsert', ['id' => $id]);

        $vectorString = $this->vectorToString($vector);

        $this->db()->updateOrInsert(
            ['id' => $id],
            [
                'vector' => $vectorString,
                'metadata' => json_encode($metadata),
                'updated_at' => now(),
            ]
        );
    }

    public function upsertBatch(array $records): void
    {
        Log::debug('VectorStore[pgvector]: upsertBatch', ['count' => count($records)]);

        foreach ($records as $record) {
            $this->upsert(
                $record['id'],
                $record['vector'],
                $record['metadata'] ?? [],
            );
        }
    }

    public function delete(string|array $ids): void
    {
        $ids = is_array($ids) ? $ids : [$ids];

        Log::debug('VectorStore[pgvector]: delete', ['ids' => $ids]);

        $this->db()->whereIn('id', $ids)->delete();
    }

    public function fetch(string $id): ?VectorRecord
    {
        Log::debug('VectorStore[pgvector]: fetch', ['id' => $id]);

        $row = $this->db()->where('id', $id)->first();

        if (is_null($row)) {
            return null;
        }

        return $this->rowToRecord($row);
    }

    public function fetchBatch(array $ids): Collection
    {
        Log::debug('VectorStore[pgvector]: fetchBatch', ['ids' => $ids]);

        return $this->db()
            ->whereIn('id', $ids)
            ->get()
            ->map(fn ($row) => $this->rowToRecord($row));
    }

    public function createIndex(string $name, int $dimensions, array $options = []): void
    {
        Log::debug('VectorStore[pgvector]: createIndex', [
            'name' => $name,
            'dimensions' => $dimensions,
        ]);

        $metric = $options['metric'] ?? $this->metric;
        $indexType = $options['index_type'] ?? 'ivfflat';

        $opsClass = match ($metric) {
            'cosine' => 'vector_cosine_ops',
            'l2' => 'vector_l2_ops',
            'ip' => 'vector_ip_ops',
            default => 'vector_cosine_ops',
        };

        $indexName = "{$this->table}_{$name}_idx";

        DB::connection($this->connection)->statement(
            "CREATE INDEX IF NOT EXISTS {$indexName} ON {$this->table} USING {$indexType} (vector {$opsClass})"
        );
    }

    public function deleteIndex(string $name): void
    {
        Log::debug('VectorStore[pgvector]: deleteIndex', ['name' => $name]);

        $indexName = "{$this->table}_{$name}_idx";

        DB::connection($this->connection)->statement(
            "DROP INDEX IF EXISTS {$indexName}"
        );
    }

    public function listIndexes(): array
    {
        Log::debug('VectorStore[pgvector]: listIndexes');

        $results = DB::connection($this->connection)->select(
            "SELECT indexname FROM pg_indexes WHERE tablename = ?",
            [$this->table]
        );

        return array_map(fn ($row) => $row->indexname, $results);
    }

    public function query(array $vector): VectorQueryBuilder
    {
        return new VectorQueryBuilder($vector, $this);
    }

    public function executeQuery(VectorQueryBuilder $builder): Collection
    {
        Log::debug('VectorStore[pgvector]: executeQuery', [
            'topK' => $builder->getTopK(),
            'hasConditions' => $builder->hasConditions(),
        ]);

        $vectorString = $this->vectorToString($builder->getVector());
        $operator = $this->distanceOperator();
        $bindings = [$vectorString];

        $selectColumns = [
            'id',
            DB::raw("(vector {$operator} ?) as score"),
        ];

        if ($builder->shouldIncludeMetadata()) {
            $selectColumns[] = 'metadata';
        }

        if ($builder->shouldIncludeVectors()) {
            $selectColumns[] = 'vector';
        }

        $query = $this->db()->select($selectColumns);
        $query->addBinding($vectorString, 'select');

        // Apply filter conditions
        if ($builder->hasConditions()) {
            $compiled = $this->compiler->compile($builder->getConditions());

            if ($compiled['sql'] !== '') {
                $query->whereRaw($compiled['sql'], $compiled['bindings']);
            }
        }

        // Apply minimum score filter
        if ($builder->getMinScore() !== null) {
            $query->having('score', '<=', $this->scoreToDistance($builder->getMinScore()));
        }

        $query->orderBy('score', 'asc');
        $query->limit($builder->getTopK());

        $results = $query->get();

        return $results->map(function ($row) use ($builder) {
            return new VectorResult(
                id: $row->id,
                score: $this->distanceToScore((float) $row->score),
                metadata: $builder->shouldIncludeMetadata()
                    ? json_decode($row->metadata ?? '{}', true)
                    : [],
                vector: $builder->shouldIncludeVectors()
                    ? $this->parseVector($row->vector)
                    : null,
            );
        });
    }

    public function flush(): void
    {
        Log::debug('VectorStore[pgvector]: flush');

        $this->db()->truncate();
    }

    /**
     * Get the database query builder for the vector records table.
     */
    protected function db(): \Illuminate\Database\Query\Builder
    {
        return DB::connection($this->connection)->table($this->table);
    }

    /**
     * Get the PostgreSQL distance operator for the configured metric.
     */
    protected function distanceOperator(): string
    {
        return match ($this->metric) {
            'cosine' => '<=>',
            'l2' => '<->',
            'ip' => '<#>',
            default => '<=>',
        };
    }

    /**
     * Convert a float array to a pgvector string representation.
     *
     * @param  float[] $vector
     */
    protected function vectorToString(array $vector): string
    {
        return '[' . implode(',', $vector) . ']';
    }

    /**
     * Parse a pgvector string back into a float array.
     *
     * @return float[]
     */
    protected function parseVector(string $vectorString): array
    {
        $cleaned = trim($vectorString, '[]');

        return array_map('floatval', explode(',', $cleaned));
    }

    /**
     * Convert a pgvector distance value to a similarity score (0-1 range for cosine).
     */
    protected function distanceToScore(float $distance): float
    {
        return match ($this->metric) {
            'cosine' => 1.0 - $distance,
            'ip' => -$distance, // inner product distance is negative
            'l2' => 1.0 / (1.0 + $distance),
            default => 1.0 - $distance,
        };
    }

    /**
     * Convert a similarity score back to a distance value for filtering.
     */
    protected function scoreToDistance(float $score): float
    {
        return match ($this->metric) {
            'cosine' => 1.0 - $score,
            'ip' => -$score,
            'l2' => (1.0 / $score) - 1.0,
            default => 1.0 - $score,
        };
    }

    /**
     * Convert a database row to a VectorRecord value object.
     */
    protected function rowToRecord(object $row): VectorRecord
    {
        return new VectorRecord(
            id: $row->id,
            vector: $this->parseVector($row->vector),
            metadata: json_decode($row->metadata ?? '{}', true),
        );
    }
}
