<?php

declare(strict_types=1);

namespace Frolax\VectorStore\Exceptions;

class RecordNotFoundException extends VectorStoreException
{
    /**
     * Create an exception for a missing record.
     */
    public static function make(string $id, string $driver): self
    {
        return new self(
            message: "Record [{$id}] was not found in the [{$driver}] vector store.",
        );
    }
}
