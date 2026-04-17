<?php

declare(strict_types=1);

namespace Frolax\VectorStore\Compilers;

use Frolax\VectorStore\Contracts\FilterCompilerContract;
use Frolax\VectorStore\Exceptions\FilterCompilationException;

/**
 * Compiles the normalised filter AST into Pinecone's MongoDB-style filter format.
 *
 * @see https://docs.pinecone.io/guides/data/filter-with-metadata
 */
class PineconeFilterCompiler implements FilterCompilerContract
{
    /**
     * Compile conditions into Pinecone's MongoDB-style filter.
     *
     * Output example:
     *   ['category' => ['$eq' => 'shoes'], 'price' => ['$gte' => 49.99]]
     *
     * When multiple conditions target the same field, they are merged under
     * a top-level `$and` array.
     *
     * @return array<string, mixed>
     */
    public function compile(array $conditions): array
    {
        if (empty($conditions)) {
            return [];
        }

        $filters = [];

        foreach ($conditions as $condition) {
            $field = $condition['field'];
            $op = $condition['op'];
            $value = $condition['value'] ?? null;

            $compiled = match ($op) {
                '=' => [$field => ['$eq' => $value]],
                '!=' => [$field => ['$ne' => $value]],
                '>' => [$field => ['$gt' => $value]],
                '>=' => [$field => ['$gte' => $value]],
                '<' => [$field => ['$lt' => $value]],
                '<=' => [$field => ['$lte' => $value]],
                'in' => [$field => ['$in' => $value]],
                'not_in' => [$field => ['$nin' => $value]],
                'between' => [
                    '$and' => [
                        [$field => ['$gte' => $value[0]]],
                        [$field => ['$lte' => $value[1]]],
                    ],
                ],
                'null' => [$field => ['$eq' => null]],
                'not_null' => [$field => ['$ne' => null]],
                default => throw FilterCompilationException::unsupportedOperator($op, 'Pinecone'),
            };

            $filters[] = $compiled;
        }

        // Single condition — return it directly
        if (count($filters) === 1) {
            return $filters[0];
        }

        // Multiple conditions — wrap in $and
        return ['$and' => $filters];
    }
}
