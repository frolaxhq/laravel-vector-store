<?php

declare(strict_types=1);

namespace Frolax\VectorStore\Testing;

use Frolax\VectorStore\Contracts\VectorStoreContract;
use Frolax\VectorStore\Query\VectorQueryBuilder;
use Frolax\VectorStore\ValueObjects\VectorRecord;
use Frolax\VectorStore\ValueObjects\VectorResult;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Assert;

/**
 * A fake vector store for testing, mirroring Storage::fake().
 *
 * Records all calls and provides assertion methods.
 */
class VectorStoreFake implements VectorStoreContract
{
    /** @var array<string, VectorRecord> */
    protected array $records = [];

    /** @var array<string, array> */
    protected array $upsertedIds = [];

    /** @var string[] */
    protected array $deletedIds = [];

    protected bool $queried = false;

    /** @var array<string, array{dimensions: int, options: array}> */
    protected array $indexes = [];

    /** @var string[] */
    protected array $deletedIndexes = [];

    protected bool $flushed = false;

    // ---- VectorStoreContract implementation ----

    public function upsert(string $id, array $vector, array $metadata = []): void
    {
        $this->records[$id] = new VectorRecord($id, $vector, $metadata);
        $this->upsertedIds[$id] = ['vector' => $vector, 'metadata' => $metadata];
    }

    public function upsertBatch(array $records): void
    {
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

        foreach ($ids as $id) {
            unset($this->records[$id]);
            $this->deletedIds[] = $id;
        }
    }

    public function fetch(string $id): ?VectorRecord
    {
        return $this->records[$id] ?? null;
    }

    public function fetchBatch(array $ids): Collection
    {
        return collect($ids)
            ->map(fn (string $id) => $this->records[$id] ?? null)
            ->filter()
            ->values();
    }

    public function createIndex(string $name, int $dimensions, array $options = []): void
    {
        $this->indexes[$name] = ['dimensions' => $dimensions, 'options' => $options];
    }

    public function deleteIndex(string $name): void
    {
        unset($this->indexes[$name]);
        $this->deletedIndexes[] = $name;
    }

    public function listIndexes(): array
    {
        return array_keys($this->indexes);
    }

    public function query(array $vector): VectorQueryBuilder
    {
        return new VectorQueryBuilder($vector, $this);
    }

    public function executeQuery(VectorQueryBuilder $builder): Collection
    {
        $this->queried = true;

        // Return all records as results with a fake score
        return collect($this->records)->map(fn (VectorRecord $r, string $id) => new VectorResult(
            id: $id,
            score: 1.0,
            metadata: $r->metadata,
            vector: $builder->shouldIncludeVectors() ? $r->vector : null,
        ))->values()->take($builder->getTopK());
    }

    /**
     * Flush all records (used by the flush command testing).
     */
    public function flush(): void
    {
        $this->records = [];
        $this->flushed = true;
    }

    // ---- Manager proxy: allow store() calls on the fake ----

    public function store(?string $name = null): VectorStoreContract
    {
        return $this;
    }

    // ---- Assertion methods ----

    public function assertUpserted(string $id, ?callable $callback = null): void
    {
        Assert::assertArrayHasKey($id, $this->upsertedIds, "Vector [{$id}] was not upserted.");

        if ($callback) {
            Assert::assertTrue(
                $callback($this->upsertedIds[$id]),
                "Vector [{$id}] was upserted but the callback assertion failed."
            );
        }
    }

    public function assertNotUpserted(string $id): void
    {
        Assert::assertArrayNotHasKey($id, $this->upsertedIds, "Vector [{$id}] was unexpectedly upserted.");
    }

    public function assertQueried(): void
    {
        Assert::assertTrue($this->queried, 'Expected a vector query to be executed, but none was.');
    }

    public function assertNotQueried(): void
    {
        Assert::assertFalse($this->queried, 'A vector query was executed unexpectedly.');
    }

    public function assertDeleted(string $id): void
    {
        Assert::assertContains($id, $this->deletedIds, "Vector [{$id}] was not deleted.");
    }

    public function assertNotDeleted(string $id): void
    {
        Assert::assertNotContains($id, $this->deletedIds, "Vector [{$id}] was unexpectedly deleted.");
    }

    public function assertIndexCreated(string $name): void
    {
        Assert::assertArrayHasKey($name, $this->indexes, "Index [{$name}] was not created.");
    }

    public function assertIndexDeleted(string $name): void
    {
        Assert::assertContains($name, $this->deletedIndexes, "Index [{$name}] was not deleted.");
    }

    public function assertFlushed(): void
    {
        Assert::assertTrue($this->flushed, 'Expected flush to be called, but it was not.');
    }

    public function assertRecordCount(int $expected): void
    {
        Assert::assertCount($expected, $this->records, "Expected {$expected} records but found ".count($this->records).'.');
    }
}
