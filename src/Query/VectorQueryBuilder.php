<?php

declare(strict_types=1);

namespace Frolax\VectorStore\Query;

use Frolax\VectorStore\Contracts\VectorStoreContract;
use Frolax\VectorStore\ValueObjects\VectorResult;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class VectorQueryBuilder
{
    /** @var float[] The query vector */
    protected array $vector;

    /** @var VectorStoreContract The driver instance that will execute the query */
    protected VectorStoreContract $store;

    /** @var int Number of top results to return */
    protected int $topK = 10;

    /** @var string|null Target collection/namespace/index */
    protected ?string $collection = null;

    /** @var float|null Minimum similarity score threshold */
    protected ?float $minScore = null;

    /** @var bool Whether to include metadata in results */
    protected bool $includeMetadata = true;

    /** @var bool Whether to include vectors in results */
    protected bool $includeVectors = false;

    /**
     * Normalised AST of filter conditions.
     *
     * Each entry: ['field' => string, 'op' => string, 'value' => mixed]
     *
     * @var array<int, array{field: string, op: string, value?: mixed}>
     */
    protected array $conditions = [];

    /**
     * @param  float[]  $vector  The query vector
     * @param  VectorStoreContract  $store  The driver that will execute this query
     */
    public function __construct(array $vector, VectorStoreContract $store)
    {
        $this->vector = $vector;
        $this->store = $store;
    }

    /**
     * Set the number of top results to return.
     */
    public function topK(int $k): self
    {
        $this->topK = $k;

        return $this;
    }

    /**
     * Set the target collection/namespace/index for the query.
     */
    public function collection(string $collection): self
    {
        $this->collection = $collection;

        return $this;
    }

    /**
     * Set the minimum similarity score threshold.
     */
    public function minScore(float $score): self
    {
        $this->minScore = $score;

        return $this;
    }

    /**
     * Whether to include metadata in query results.
     */
    public function includeMetadata(bool $include = true): self
    {
        $this->includeMetadata = $include;

        return $this;
    }

    /**
     * Whether to include the vector data in query results.
     */
    public function includeVectors(bool $include = true): self
    {
        $this->includeVectors = $include;

        return $this;
    }

    /**
     * Add a where condition.
     *
     * Supports two signatures:
     *   ->where('field', 'value')            — equality (op = '=')
     *   ->where('field', '>=', value)        — explicit operator
     *
     * @param  string  $field  The metadata field name
     * @param  mixed  $operator  The operator or value (if two-argument form)
     * @param  mixed  $value  The value (only for three-argument form)
     */
    public function where(string $field, mixed $operator, mixed $value = null): self
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $this->validateOperator($operator);

        $this->conditions[] = [
            'field' => $field,
            'op' => $operator,
            'value' => $value,
        ];

        return $this;
    }

    /**
     * Add a "where in" condition.
     *
     * @param  string  $field  The metadata field name
     * @param  array  $values  The list of acceptable values
     */
    public function whereIn(string $field, array $values): self
    {
        $this->conditions[] = [
            'field' => $field,
            'op' => 'in',
            'value' => array_values($values),
        ];

        return $this;
    }

    /**
     * Add a "where not in" condition.
     *
     * @param  string  $field  The metadata field name
     * @param  array  $values  The list of excluded values
     */
    public function whereNotIn(string $field, array $values): self
    {
        $this->conditions[] = [
            'field' => $field,
            'op' => 'not_in',
            'value' => array_values($values),
        ];

        return $this;
    }

    /**
     * Add a "where between" condition.
     *
     * @param  string  $field  The metadata field name
     * @param  array  $range  A two-element array [min, max]
     */
    public function whereBetween(string $field, array $range): self
    {
        if (count($range) !== 2) {
            throw new InvalidArgumentException(
                'The whereBetween range must be a two-element array [min, max].'
            );
        }

        $this->conditions[] = [
            'field' => $field,
            'op' => 'between',
            'value' => array_values($range),
        ];

        return $this;
    }

    /**
     * Add a "where null" condition.
     */
    public function whereNull(string $field): self
    {
        $this->conditions[] = [
            'field' => $field,
            'op' => 'null',
        ];

        return $this;
    }

    /**
     * Add a "where not null" condition.
     */
    public function whereNotNull(string $field): self
    {
        $this->conditions[] = [
            'field' => $field,
            'op' => 'not_null',
        ];

        return $this;
    }

    /**
     * Execute the query and return a collection of results.
     *
     * @return Collection<int, VectorResult>
     */
    public function get(): Collection
    {
        return $this->store->executeQuery($this);
    }

    /**
     * Execute the query and return the first result, or null.
     */
    public function first(): ?VectorResult
    {
        $original = $this->topK;
        $this->topK = 1;

        $result = $this->store->executeQuery($this)->first();

        $this->topK = $original;

        return $result;
    }

    /**
     * Execute the query with pagination.
     *
     * @return array{data: Collection<int, VectorResult>, page: int, per_page: int, has_more: bool}
     */
    public function paginate(int $perPage = 20, int $page = 1): array
    {
        $original = $this->topK;

        // Fetch one extra to determine if more pages exist
        $this->topK = ($perPage * $page) + 1;

        $allResults = $this->store->executeQuery($this);

        $this->topK = $original;

        $offset = ($page - 1) * $perPage;
        $pageResults = $allResults->slice($offset, $perPage)->values();

        return [
            'data' => $pageResults,
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => $allResults->count() > ($offset + $perPage),
        ];
    }

    // -------------------------------------------------------------------------
    //  Accessors — used by drivers to read query state
    // -------------------------------------------------------------------------

    /**
     * Get the query vector.
     *
     * @return float[]
     */
    public function getVector(): array
    {
        return $this->vector;
    }

    /**
     * Get the top-K value.
     */
    public function getTopK(): int
    {
        return $this->topK;
    }

    /**
     * Get the target collection/namespace, or null if not set.
     */
    public function getCollection(): ?string
    {
        return $this->collection;
    }

    /**
     * Get the minimum score threshold, or null if not set.
     */
    public function getMinScore(): ?float
    {
        return $this->minScore;
    }

    /**
     * Whether metadata should be included in results.
     */
    public function shouldIncludeMetadata(): bool
    {
        return $this->includeMetadata;
    }

    /**
     * Whether vectors should be included in results.
     */
    public function shouldIncludeVectors(): bool
    {
        return $this->includeVectors;
    }

    /**
     * Get the normalised filter conditions AST.
     *
     * @return array<int, array{field: string, op: string, value?: mixed}>
     */
    public function getConditions(): array
    {
        return $this->conditions;
    }

    /**
     * Determine if the builder has any filter conditions.
     */
    public function hasConditions(): bool
    {
        return count($this->conditions) > 0;
    }

    /**
     * Validate that the given operator is supported.
     *
     * @throws InvalidArgumentException
     */
    protected function validateOperator(string $operator): void
    {
        $valid = ['=', '!=', '>', '>=', '<', '<='];

        if (! in_array($operator, $valid, true)) {
            throw new InvalidArgumentException(
                "Invalid operator [{$operator}]. Supported operators: ".implode(', ', $valid)
            );
        }
    }
}
