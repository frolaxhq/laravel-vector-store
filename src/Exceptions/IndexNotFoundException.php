<?php

declare(strict_types=1);

namespace Frolax\VectorStore\Exceptions;

class IndexNotFoundException extends VectorStoreException
{
    /**
     * Create an exception for a missing index.
     */
    public static function make(string $index, string $driver): self
    {
        return new self(
            message: "Index [{$index}] was not found in the [{$driver}] vector store.",
        );
    }
}
