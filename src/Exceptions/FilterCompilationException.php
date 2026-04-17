<?php

declare(strict_types=1);

namespace Frolax\VectorStore\Exceptions;

class FilterCompilationException extends VectorStoreException
{
    /**
     * Create an exception for an unsupported filter operator.
     */
    public static function unsupportedOperator(string $operator, string $compiler): self
    {
        return new self(
            message: "The [{$operator}] operator is not supported by the [{$compiler}] filter compiler.",
        );
    }

    /**
     * Create an exception for a malformed filter condition.
     */
    public static function malformedCondition(string $message, string $compiler): self
    {
        return new self(
            message: "Malformed filter condition in [{$compiler}] compiler: {$message}",
        );
    }
}
