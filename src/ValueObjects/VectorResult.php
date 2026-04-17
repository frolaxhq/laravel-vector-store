<?php

declare(strict_types=1);

namespace Frolax\VectorStore\ValueObjects;

use JsonSerializable;

final readonly class VectorResult implements JsonSerializable
{
    /**
     * @param  string  $id  Unique identifier
     * @param  float  $score  Similarity score
     * @param  array  $metadata  Key-value metadata pairs
     * @param  float[]|null  $vector  The vector embedding (null unless explicitly requested)
     */
    public function __construct(
        public string $id,
        public float $score,
        public array $metadata = [],
        public ?array $vector = null,
    ) {}

    /**
     * Create a VectorResult from an associative array.
     *
     * @param  array{id: string, score: float, metadata?: array, vector?: float[]|null}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'],
            score: (float) $data['score'],
            metadata: $data['metadata'] ?? [],
            vector: $data['vector'] ?? null,
        );
    }

    /**
     * Convert the result to an associative array.
     *
     * @return array{id: string, score: float, metadata: array, vector: float[]|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'score' => $this->score,
            'metadata' => $this->metadata,
            'vector' => $this->vector,
        ];
    }

    /**
     * @return array{id: string, score: float, metadata: array, vector: float[]|null}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
