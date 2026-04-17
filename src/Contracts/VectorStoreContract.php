<?php

declare(strict_types=1);

namespace Frolax\VectorStore\Contracts;

use Frolax\VectorStore\Query\VectorQueryBuilder;
use Frolax\VectorStore\ValueObjects\VectorRecord;
use Illuminate\Support\Collection;

interface VectorStoreContract
{
    /**
     * Insert or update a single vector record.
     *
     * @param  string  $id       Unique identifier for the vector
     * @param  float[] $vector   The vector embedding
     * @param  array   $metadata Key-value metadata pairs
     */
    public function upsert(string $id, array $vector, array $metadata = []): void;

    /**
     * Insert or update multiple vector records in a single batch.
     *
     * @param  array<int, array{id: string, vector: float[], metadata?: array}> $records
     */
    public function upsertBatch(array $records): void;

    /**
     * Delete one or more vectors by their IDs.
     *
     * @param  string|string[] $ids
     */
    public function delete(string|array $ids): void;

    /**
     * Fetch a single vector record by its ID.
     *
     * @return VectorRecord|null Returns null if the record does not exist.
     */
    public function fetch(string $id): ?VectorRecord;

    /**
     * Fetch multiple vector records by their IDs.
     *
     * @param  string[] $ids
     * @return Collection<int, VectorRecord>
     */
    public function fetchBatch(array $ids): Collection;

    /**
     * Create a new index (collection/namespace) in the vector store.
     *
     * @param  string $name       The index name
     * @param  int    $dimensions The dimensionality of vectors in this index
     * @param  array  $options    Driver-specific options (e.g., metric type)
     */
    public function createIndex(string $name, int $dimensions, array $options = []): void;

    /**
     * Delete an existing index.
     */
    public function deleteIndex(string $name): void;

    /**
     * List all available indexes.
     *
     * @return string[]
     */
    public function listIndexes(): array;

    /**
     * Begin a fluent similarity query against the store.
     *
     * @param  float[] $vector The query vector
     */
    public function query(array $vector): VectorQueryBuilder;

    /**
     * Execute a query built by the VectorQueryBuilder.
     *
     * This method is called internally by the builder's get()/first()/paginate()
     * methods. Driver implementations compile the builder's AST into native
     * filters and perform the similarity search.
     *
     * @return Collection<int, \Frolax\VectorStore\ValueObjects\VectorResult>
     */
    public function executeQuery(VectorQueryBuilder $builder): Collection;
}
