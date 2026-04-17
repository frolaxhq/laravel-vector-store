<?php

declare(strict_types=1);

namespace Frolax\VectorStore\ValueObjects;

use JsonSerializable;

final readonly class VectorRecord implements JsonSerializable
{
    /**
     * @param  string  $id  Unique identifier
     * @param  float[]  $vector  The vector embedding
     * @param  array  $metadata  Key-value metadata pairs
     */
    public function __construct(
        public string $id,
        public array $vector,
        public array $metadata = [],
    ) {}

    /**
     * Create a VectorRecord from an associative array.
     *
     * @param  array{id: string, vector: float[], metadata?: array}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            vector: $data['vector'],
            metadata: $data['metadata'] ?? [],
        );
    }

    /**
     * Convert the record to an associative array.
     *
     * @return array{id: string, vector: float[], metadata: array}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'vector' => $this->vector,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * @return array{id: string, vector: float[], metadata: array}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
