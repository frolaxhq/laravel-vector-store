<?php

declare(strict_types=1);

namespace Frolax\VectorStore\Compilers;

use Frolax\VectorStore\Contracts\FilterCompilerContract;
use Frolax\VectorStore\Exceptions\FilterCompilationException;

/**
 * Compiles the normalised filter AST into Qdrant's filter format.
 *
 * @see https://qdrant.tech/documentation/concepts/filtering/
 */
class QdrantFilterCompiler implements FilterCompilerContract
{
    /**
     * Compile conditions into Qdrant's filter structure.
     *
     * Output example:
     *   [
     *     'must' => [
     *       ['key' => 'category', 'match' => ['value' => 'shoes']],
     *       ['key' => 'price', 'range' => ['gte' => 49.99]],
     *     ]
     *   ]
     *
     * @return array{must?: array, must_not?: array}
     */
    public function compile(array $conditions): array
    {
        if (empty($conditions)) {
            return [];
        }

        $must = [];
        $mustNot = [];

        foreach ($conditions as $condition) {
            $field = $condition['field'];
            $op = $condition['op'];
            $value = $condition['value'] ?? null;

            match ($op) {
                '=' => $must[] = [
                    'key' => $field,
                    'match' => ['value' => $value],
                ],
                '!=' => $mustNot[] = [
                    'key' => $field,
                    'match' => ['value' => $value],
                ],
                '>' => $must[] = [
                    'key' => $field,
                    'range' => ['gt' => $value],
                ],
                '>=' => $must[] = [
                    'key' => $field,
                    'range' => ['gte' => $value],
                ],
                '<' => $must[] = [
                    'key' => $field,
                    'range' => ['lt' => $value],
                ],
                '<=' => $must[] = [
                    'key' => $field,
                    'range' => ['lte' => $value],
                ],
                'in' => $must[] = [
                    'key' => $field,
                    'match' => ['any' => $value],
                ],
                'not_in' => $mustNot[] = [
                    'key' => $field,
                    'match' => ['any' => $value],
                ],
                'between' => $must[] = [
                    'key' => $field,
                    'range' => [
                        'gte' => $value[0],
                        'lte' => $value[1],
                    ],
                ],
                'null' => $must[] = [
                    'is_null' => ['key' => $field],
                ],
                'not_null' => $mustNot[] = [
                    'is_null' => ['key' => $field],
                ],
                default => throw FilterCompilationException::unsupportedOperator($op, 'Qdrant'),
            };
        }

        $filter = [];

        if (! empty($must)) {
            $filter['must'] = $must;
        }

        if (! empty($mustNot)) {
            $filter['must_not'] = $mustNot;
        }

        return $filter;
    }
}
