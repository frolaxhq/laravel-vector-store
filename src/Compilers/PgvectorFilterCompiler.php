<?php

declare(strict_types=1);

namespace Frolax\VectorStore\Compilers;

use Frolax\VectorStore\Contracts\FilterCompilerContract;
use Frolax\VectorStore\Exceptions\FilterCompilationException;

/**
 * Compiles the normalised filter AST into SQL WHERE clause fragments with bindings.
 *
 * Uses PostgreSQL JSONB operators to filter on the metadata column.
 */
class PgvectorFilterCompiler implements FilterCompilerContract
{
    /**
     * The name of the JSONB metadata column.
     */
    protected string $metadataColumn;

    public function __construct(string $metadataColumn = 'metadata')
    {
        $this->metadataColumn = $metadataColumn;
    }

    /**
     * Compile conditions into SQL WHERE fragments with parameter bindings.
     *
     * Output example:
     *   [
     *     'sql'      => "(metadata->>'category')::text = ? AND (metadata->>'price')::float >= ?",
     *     'bindings' => ['shoes', 49.99],
     *   ]
     *
     * @return array{sql: string, bindings: array}
     */
    public function compile(array $conditions): array
    {
        if (empty($conditions)) {
            return ['sql' => '', 'bindings' => []];
        }

        $fragments = [];
        $bindings = [];

        foreach ($conditions as $condition) {
            $field = $condition['field'];
            $op = $condition['op'];
            $value = $condition['value'] ?? null;

            $result = match ($op) {
                '=' => $this->compileComparison($field, '=', $value),
                '!=' => $this->compileComparison($field, '!=', $value),
                '>' => $this->compileComparison($field, '>', $value),
                '>=' => $this->compileComparison($field, '>=', $value),
                '<' => $this->compileComparison($field, '<', $value),
                '<=' => $this->compileComparison($field, '<=', $value),
                'in' => $this->compileIn($field, $value),
                'not_in' => $this->compileNotIn($field, $value),
                'between' => $this->compileBetween($field, $value),
                'null' => $this->compileNull($field),
                'not_null' => $this->compileNotNull($field),
                default => throw FilterCompilationException::unsupportedOperator($op, 'Pgvector'),
            };

            $fragments[] = $result['sql'];
            $bindings = array_merge($bindings, $result['bindings']);
        }

        return [
            'sql' => implode(' AND ', $fragments),
            'bindings' => $bindings,
        ];
    }

    /**
     * Compile a standard comparison (=, !=, >, >=, <, <=).
     *
     * @return array{sql: string, bindings: array}
     */
    protected function compileComparison(string $field, string $operator, mixed $value): array
    {
        $cast = $this->castForValue($value);
        $accessor = $this->jsonAccessor($field, $cast);

        return [
            'sql' => "{$accessor} {$operator} ?",
            'bindings' => [$value],
        ];
    }

    /**
     * Compile an IN condition.
     *
     * @return array{sql: string, bindings: array}
     */
    protected function compileIn(string $field, array $values): array
    {
        $cast = $this->castForValue($values[0] ?? '');
        $accessor = $this->jsonAccessor($field, $cast);
        $placeholders = implode(', ', array_fill(0, count($values), '?'));

        return [
            'sql' => "{$accessor} IN ({$placeholders})",
            'bindings' => $values,
        ];
    }

    /**
     * Compile a NOT IN condition.
     *
     * @return array{sql: string, bindings: array}
     */
    protected function compileNotIn(string $field, array $values): array
    {
        $cast = $this->castForValue($values[0] ?? '');
        $accessor = $this->jsonAccessor($field, $cast);
        $placeholders = implode(', ', array_fill(0, count($values), '?'));

        return [
            'sql' => "{$accessor} NOT IN ({$placeholders})",
            'bindings' => $values,
        ];
    }

    /**
     * Compile a BETWEEN condition.
     *
     * @return array{sql: string, bindings: array}
     */
    protected function compileBetween(string $field, array $range): array
    {
        $cast = $this->castForValue($range[0]);
        $accessor = $this->jsonAccessor($field, $cast);

        return [
            'sql' => "{$accessor} BETWEEN ? AND ?",
            'bindings' => [$range[0], $range[1]],
        ];
    }

    /**
     * Compile an IS NULL condition.
     *
     * @return array{sql: string, bindings: array}
     */
    protected function compileNull(string $field): array
    {
        return [
            'sql' => "({$this->metadataColumn}->>'{$field}') IS NULL",
            'bindings' => [],
        ];
    }

    /**
     * Compile an IS NOT NULL condition.
     *
     * @return array{sql: string, bindings: array}
     */
    protected function compileNotNull(string $field): array
    {
        return [
            'sql' => "({$this->metadataColumn}->>'{$field}') IS NOT NULL",
            'bindings' => [],
        ];
    }

    /**
     * Build a JSONB accessor expression with an optional SQL cast.
     */
    protected function jsonAccessor(string $field, string $cast): string
    {
        $accessor = "({$this->metadataColumn}->>'{$field}')";

        if ($cast !== '') {
            $accessor = "({$accessor})::{$cast}";
        }

        return $accessor;
    }

    /**
     * Determine the appropriate SQL cast type based on the PHP value type.
     */
    protected function castForValue(mixed $value): string
    {
        return match (true) {
            is_int($value) => 'integer',
            is_float($value) => 'float',
            is_bool($value) => 'boolean',
            default => 'text',
        };
    }
}
