<?php

declare(strict_types=1);

namespace Frolax\VectorStore\Exceptions;

use RuntimeException;

class VectorStoreException extends RuntimeException
{
    /**
     * Create an exception for a failed API request.
     */
    public static function requestFailed(string $driver, string $message, int $statusCode = 0): self
    {
        return new self(
            message: "Vector store [{$driver}] request failed: {$message}",
            code: $statusCode,
        );
    }

    /**
     * Create an exception for an unsupported operation.
     */
    public static function unsupportedOperation(string $driver, string $operation): self
    {
        return new self(
            message: "Vector store [{$driver}] does not support the [{$operation}] operation.",
        );
    }

    /**
     * Create an exception for invalid configuration.
     */
    public static function invalidConfiguration(string $driver, string $message): self
    {
        return new self(
            message: "Vector store [{$driver}] configuration is invalid: {$message}",
        );
    }
}
