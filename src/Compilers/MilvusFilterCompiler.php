<?php

declare(strict_types=1);

namespace Frolax\VectorStore\Compilers;

use Frolax\VectorStore\Contracts\FilterCompilerContract;
use Frolax\VectorStore\Exceptions\FilterCompilationException;

/**
 * Compiles the normalised filter AST into Milvus boolean expression strings.
 *
 * @see https://milvus.io/docs/boolean.md
 */
class MilvusFilterCompiler implements FilterCompilerContract
{
    /**
     * Compile conditions into a Milvus boolean expression string.
     *
     * Output example:
     *   "category == 'shoes' and price >= 49.99"
     */
    public function compile(array $conditions): string
    {
        if (empty($conditions)) {
            return '';
        }

        $expressions = [];

        foreach ($conditions as $condition) {
            $field = $condition['field'];
            $op = $condition['op'];
            $value = $condition['value'] ?? null;

            $expressions[] = match ($op) {
                '=' => "{$field} == {$this->quote($value)}",
                '!=' => "{$field} != {$this->quote($value)}",
                '>' => "{$field} > {$this->quote($value)}",
                '>=' => "{$field} >= {$this->quote($value)}",
                '<' => "{$field} < {$this->quote($value)}",
                '<=' => "{$field} <= {$this->quote($value)}",
                'in' => "{$field} in {$this->quoteArray($value)}",
                'not_in' => "{$field} not in {$this->quoteArray($value)}",
                'between' => "({$field} >= {$this->quote($value[0])} and {$field} <= {$this->quote($value[1])})",
                'null' => "{$field} == null",
                'not_null' => "{$field} != null",
                default => throw FilterCompilationException::unsupportedOperator($op, 'Milvus'),
            };
        }

        return implode(' and ', $expressions);
    }

    /**
     * Quote a scalar value for the Milvus expression.
     */
    protected function quote(mixed $value): string
    {
        if (is_null($value)) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        // Escape single quotes within strings
        $escaped = str_replace("'", "\\'", (string) $value);

        return "'{$escaped}'";
    }

    /**
     * Quote an array of values for the Milvus `in` operator.
     */
    protected function quoteArray(array $values): string
    {
        $quoted = array_map(fn ($v) => $this->quote($v), $values);

        return '['.implode(', ', $quoted).']';
    }
}
