<?php

declare(strict_types=1);

namespace Frolax\VectorStore\Drivers;

use Frolax\VectorStore\Compilers\WeaviateFilterCompiler;
use Frolax\VectorStore\Contracts\VectorStoreContract;
use Frolax\VectorStore\Query\VectorQueryBuilder;
use Frolax\VectorStore\ValueObjects\VectorRecord;
use Frolax\VectorStore\ValueObjects\VectorResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WeaviateDriver implements VectorStoreContract
{
    protected string $host;

    protected string $apiKey;

    protected string $class;

    protected WeaviateFilterCompiler $compiler;

    /**
     * @param  array{host?: string, api_key?: string, class?: string}  $config
     */
    public function __construct(array $config, WeaviateFilterCompiler $compiler)
    {
        $this->host = rtrim($config['host'] ?? 'http://localhost:8080', '/');
        $this->apiKey = $config['api_key'] ?? '';
        $this->class = $config['class'] ?? 'Document';
        $this->compiler = $compiler;
    }

    public function upsert(string $id, array $vector, array $metadata = []): void
    {
        Log::debug('VectorStore[weaviate]: upsert', ['id' => $id]);

        $payload = [
            'id' => $id,
            'class' => $this->class,
            'properties' => (object) $metadata,
            'vector' => $vector,
        ];

        // Try update first, create if not found
        $response = $this->request()
            ->put("{$this->host}/v1/objects/{$this->class}/{$id}", $payload);

        if ($response->status() === 404) {
            $this->request()
                ->post("{$this->host}/v1/objects", $payload)
                ->throw();

            return;
        }

        $response->throw();
    }

    public function upsertBatch(array $records): void
    {
        Log::debug('VectorStore[weaviate]: upsertBatch', ['count' => count($records)]);

        $objects = array_map(fn (array $r) => [
            'id' => $r['id'],
            'class' => $this->class,
            'properties' => (object) ($r['metadata'] ?? []),
            'vector' => $r['vector'],
        ], $records);

        $this->request()
            ->post("{$this->host}/v1/batch/objects", ['objects' => $objects])
            ->throw();
    }

    public function delete(string|array $ids): void
    {
        $ids = is_array($ids) ? $ids : [$ids];

        Log::debug('VectorStore[weaviate]: delete', ['ids' => $ids]);

        foreach ($ids as $id) {
            $this->request()
                ->delete("{$this->host}/v1/objects/{$this->class}/{$id}")
                ->throw();
        }
    }

    public function fetch(string $id): ?VectorRecord
    {
        Log::debug('VectorStore[weaviate]: fetch', ['id' => $id]);

        $response = $this->request()
            ->get("{$this->host}/v1/objects/{$this->class}/{$id}", [
                'include' => 'vector',
            ]);

        if ($response->status() === 404) {
            return null;
        }

        $response->throw();
        $data = $response->json();

        return new VectorRecord(
            id: $data['id'],
            vector: $data['vector'] ?? [],
            metadata: (array) ($data['properties'] ?? []),
        );
    }

    public function fetchBatch(array $ids): Collection
    {
        Log::debug('VectorStore[weaviate]: fetchBatch', ['ids' => $ids]);

        return collect($ids)
            ->map(fn (string $id) => $this->fetch($id))
            ->filter()
            ->values();
    }

    public function createIndex(string $name, int $dimensions, array $options = []): void
    {
        Log::debug('VectorStore[weaviate]: createIndex', [
            'name' => $name,
            'dimensions' => $dimensions,
        ]);

        $payload = [
            'class' => $name,
            'vectorizer' => 'none',
        ];

        if (! empty($options['properties'])) {
            $payload['properties'] = $options['properties'];
        }

        $this->request()
            ->post("{$this->host}/v1/schema", $payload)
            ->throw();
    }

    public function deleteIndex(string $name): void
    {
        Log::debug('VectorStore[weaviate]: deleteIndex', ['name' => $name]);

        $this->request()
            ->delete("{$this->host}/v1/schema/{$name}")
            ->throw();
    }

    public function listIndexes(): array
    {
        Log::debug('VectorStore[weaviate]: listIndexes');

        $response = $this->request()
            ->get("{$this->host}/v1/schema")
            ->throw();

        $data = $response->json();
        $classes = $data['classes'] ?? [];

        return array_map(fn (array $c) => $c['class'], $classes);
    }

    public function query(array $vector): VectorQueryBuilder
    {
        return new VectorQueryBuilder($vector, $this);
    }

    public function executeQuery(VectorQueryBuilder $builder): Collection
    {
        Log::debug('VectorStore[weaviate]: executeQuery', [
            'topK' => $builder->getTopK(),
            'hasConditions' => $builder->hasConditions(),
        ]);

        $className = $builder->getCollection() ?? $this->class;

        // Build GraphQL query
        $nearVector = json_encode([
            'vector' => $builder->getVector(),
            'certainty' => $builder->getMinScore(),
        ]);

        $gqlParts = [
            "nearVector: {$nearVector}",
            "limit: {$builder->getTopK()}",
        ];

        if ($builder->hasConditions()) {
            $where = $this->compiler->compile($builder->getConditions());
            $gqlParts[] = 'where: '.$this->toGraphQLObject($where);
        }

        $additionalFields = ['id'];
        if ($builder->shouldIncludeVectors()) {
            $additionalFields[] = 'vector';
        }
        $additional = implode(' ', $additionalFields);

        $graphql = sprintf(
            '{ Get { %s(%s) { _additional { %s certainty } } } }',
            $className,
            implode(', ', $gqlParts),
            $additional,
        );

        $response = $this->request()
            ->post("{$this->host}/v1/graphql", ['query' => $graphql])
            ->throw();

        $data = $response->json();
        $objects = $data['data']['Get'][$className] ?? [];

        return collect($objects)->map(function (array $obj) use ($builder) {
            $additional = $obj['_additional'] ?? [];
            $properties = array_diff_key($obj, ['_additional' => true]);

            return new VectorResult(
                id: $additional['id'] ?? '',
                score: (float) ($additional['certainty'] ?? 0.0),
                metadata: $builder->shouldIncludeMetadata() ? $properties : [],
                vector: $builder->shouldIncludeVectors() ? ($additional['vector'] ?? null) : null,
            );
        });
    }

    /**
     * Convert a PHP array to a GraphQL object literal string (unquoted keys).
     */
    protected function toGraphQLObject(mixed $value): string
    {
        if (is_array($value)) {
            // Check if sequential (list) vs associative (object)
            if (array_is_list($value)) {
                $items = array_map(fn ($v) => $this->toGraphQLObject($v), $value);

                return '['.implode(', ', $items).']';
            }

            $parts = [];
            foreach ($value as $k => $v) {
                $parts[] = "{$k}: {$this->toGraphQLObject($v)}";
            }

            return '{ '.implode(', ', $parts).' }';
        }

        if (is_string($value)) {
            return '"'.addslashes($value).'"';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_null($value)) {
            return 'null';
        }

        return (string) $value;
    }

    protected function request(): PendingRequest
    {
        $request = Http::acceptJson()->asJson();

        if ($this->apiKey !== '') {
            $request = $request->withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
            ]);
        }

        return $request->retry(3, 100, function (\Exception $e) {
            return $e instanceof RequestException
                && in_array($e->response->status(), [429, 503]);
        }, false);
    }
}
