<?php

declare(strict_types=1);

namespace Frolax\VectorStore\Compilers;

use Frolax\VectorStore\Contracts\FilterCompilerContract;
use Frolax\VectorStore\Exceptions\FilterCompilationException;

/**
 * Compiles the normalised filter AST into Weaviate's GraphQL where clause format.
 *
 * @see https://weaviate.io/developers/weaviate/search/filters
 */
class WeaviateFilterCompiler implements FilterCompilerContract
{
    /**
     * Compile conditions into Weaviate's where clause structure.
     *
     * Output example (single):
     *   ['path' => ['category'], 'operator' => 'Equal', 'valueText' => 'shoes']
     *
     * Output example (multiple):
     *   [
     *     'operator' => 'And',
     *     'operands' => [
     *       ['path' => ['category'], 'operator' => 'Equal', 'valueText' => 'shoes'],
     *       ['path' => ['price'], 'operator' => 'GreaterThanEqual', 'valueNumber' => 49.99],
     *     ]
     *   ]
     *
     * @return array<string, mixed>
     */
    public function compile(array $conditions): array
    {
        if (empty($conditions)) {
            return [];
        }

        $clauses = [];

        foreach ($conditions as $condition) {
            $field = $condition['field'];
            $op = $condition['op'];
            $value = $condition['value'] ?? null;

            $clauses = array_merge($clauses, $this->compileCondition($field, $op, $value));
        }

        if (count($clauses) === 1) {
            return $clauses[0];
        }

        return [
            'operator' => 'And',
            'operands' => $clauses,
        ];
    }

    /**
     * Compile a single condition into one or more Weaviate where clauses.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function compileCondition(string $field, string $op, mixed $value): array
    {
        return match ($op) {
            '=' => [$this->buildClause($field, 'Equal', $value)],
            '!=' => [$this->buildClause($field, 'NotEqual', $value)],
            '>' => [$this->buildClause($field, 'GreaterThan', $value)],
            '>=' => [$this->buildClause($field, 'GreaterThanEqual', $value)],
            '<' => [$this->buildClause($field, 'LessThan', $value)],
            '<=' => [$this->buildClause($field, 'LessThanEqual', $value)],
            'in' => [
                [
                    'operator' => 'Or',
                    'operands' => array_map(
                        fn ($v) => $this->buildClause($field, 'Equal', $v),
                        $value,
                    ),
                ],
            ],
            'not_in' => array_map(
                fn ($v) => $this->buildClause($field, 'NotEqual', $v),
                $value,
            ),
            'between' => [
                $this->buildClause($field, 'GreaterThanEqual', $value[0]),
                $this->buildClause($field, 'LessThanEqual', $value[1]),
            ],
            'null' => [$this->buildClause($field, 'IsNull', true)],
            'not_null' => [$this->buildClause($field, 'IsNull', false)],
            default => throw FilterCompilationException::unsupportedOperator($op, 'Weaviate'),
        };
    }

    /**
     * Build a single where clause with the appropriate value type key.
     *
     * @return array<string, mixed>
     */
    protected function buildClause(string $field, string $operator, mixed $value): array
    {
        $clause = [
            'path' => [$field],
            'operator' => $operator,
        ];

        $clause[$this->resolveValueKey($value, $operator)] = $value;

        return $clause;
    }

    /**
     * Determine the Weaviate value type key based on the PHP value type.
     */
    protected function resolveValueKey(mixed $value, string $operator): string
    {
        if ($operator === 'IsNull') {
            return 'valueBoolean';
        }

        return match (true) {
            is_int($value) => 'valueInt',
            is_float($value) => 'valueNumber',
            is_bool($value) => 'valueBoolean',
            default => 'valueText',
        };
    }
}
