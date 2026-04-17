<?php

declare(strict_types=1);

namespace Frolax\VectorStore\Drivers;

use Frolax\VectorStore\Compilers\PineconeFilterCompiler;
use Frolax\VectorStore\Contracts\VectorStoreContract;
use Frolax\VectorStore\Exceptions\IndexNotFoundException;
use Frolax\VectorStore\Exceptions\VectorStoreException;
use Frolax\VectorStore\Query\VectorQueryBuilder;
use Frolax\VectorStore\ValueObjects\VectorRecord;
use Frolax\VectorStore\ValueObjects\VectorResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PineconeDriver implements VectorStoreContract
{
    protected string $apiKey;

    protected string $host;

    protected string $namespace;

    protected PineconeFilterCompiler $compiler;

    /**
     * @param  array{api_key: string, host: string, namespace?: string} $config
     */
    public function __construct(array $config, PineconeFilterCompiler $compiler)
    {
        $this->apiKey = $config['api_key'] ?? '';
        $this->host = rtrim($config['host'] ?? '', '/');
        $this->namespace = $config['namespace'] ?? '';
        $this->compiler = $compiler;
    }

    public function upsert(string $id, array $vector, array $metadata = []): void
    {
        Log::debug('VectorStore[pinecone]: upsert', ['id' => $id]);

        $this->request()->post("{$this->host}/vectors/upsert", [
            'vectors' => [
                [
                    'id' => $id,
                    'values' => $vector,
                    'metadata' => (object) $metadata,
                ],
            ],
            'namespace' => $this->namespace,
        ])->throw();
    }

    public function upsertBatch(array $records): void
    {
        Log::debug('VectorStore[pinecone]: upsertBatch', ['count' => count($records)]);

        $vectors = array_map(fn (array $record) => [
            'id' => $record['id'],
            'values' => $record['vector'],
            'metadata' => (object) ($record['metadata'] ?? []),
        ], $records);

        $this->request()->post("{$this->host}/vectors/upsert", [
            'vectors' => $vectors,
            'namespace' => $this->namespace,
        ])->throw();
    }

    public function delete(string|array $ids): void
    {
        $ids = is_array($ids) ? $ids : [$ids];

        Log::debug('VectorStore[pinecone]: delete', ['ids' => $ids]);

        $this->request()->post("{$this->host}/vectors/delete", [
            'ids' => $ids,
            'namespace' => $this->namespace,
        ])->throw();
    }

    public function fetch(string $id): ?VectorRecord
    {
        Log::debug('VectorStore[pinecone]: fetch', ['id' => $id]);

        $response = $this->request()
            ->get("{$this->host}/vectors/fetch", [
                'ids' => [$id],
                'namespace' => $this->namespace,
            ])->throw();

        $data = $response->json();
        $vectors = $data['vectors'] ?? [];

        if (! isset($vectors[$id])) {
            return null;
        }

        $v = $vectors[$id];

        return new VectorRecord(
            id: $v['id'],
            vector: $v['values'] ?? [],
            metadata: $v['metadata'] ?? [],
        );
    }

    public function fetchBatch(array $ids): Collection
    {
        Log::debug('VectorStore[pinecone]: fetchBatch', ['ids' => $ids]);

        $response = $this->request()
            ->get("{$this->host}/vectors/fetch", [
                'ids' => $ids,
                'namespace' => $this->namespace,
            ])->throw();

        $data = $response->json();
        $vectors = $data['vectors'] ?? [];

        return collect($vectors)->map(fn (array $v) => new VectorRecord(
            id: $v['id'],
            vector: $v['values'] ?? [],
            metadata: $v['metadata'] ?? [],
        ))->values();
    }

    public function createIndex(string $name, int $dimensions, array $options = []): void
    {
        Log::debug('VectorStore[pinecone]: createIndex', [
            'name' => $name,
            'dimensions' => $dimensions,
        ]);

        $metric = $options['metric'] ?? 'cosine';

        // Pinecone index creation uses the control plane API
        $this->request()->post('https://api.pinecone.io/indexes', [
            'name' => $name,
            'dimension' => $dimensions,
            'metric' => $metric,
            'spec' => $options['spec'] ?? [
                'serverless' => [
                    'cloud' => $options['cloud'] ?? 'aws',
                    'region' => $options['region'] ?? 'us-east-1',
                ],
            ],
        ])->throw();
    }

    public function deleteIndex(string $name): void
    {
        Log::debug('VectorStore[pinecone]: deleteIndex', ['name' => $name]);

        $this->request()
            ->delete("https://api.pinecone.io/indexes/{$name}")
            ->throw();
    }

    public function listIndexes(): array
    {
        Log::debug('VectorStore[pinecone]: listIndexes');

        $response = $this->request()
            ->get('https://api.pinecone.io/indexes')
            ->throw();

        $data = $response->json();

        return array_map(
            fn (array $index) => $index['name'],
            $data['indexes'] ?? [],
        );
    }

    public function query(array $vector): VectorQueryBuilder
    {
        return new VectorQueryBuilder($vector, $this);
    }

    public function executeQuery(VectorQueryBuilder $builder): Collection
    {
        Log::debug('VectorStore[pinecone]: executeQuery', [
            'topK' => $builder->getTopK(),
            'hasConditions' => $builder->hasConditions(),
        ]);

        $payload = [
            'vector' => $builder->getVector(),
            'topK' => $builder->getTopK(),
            'namespace' => $builder->getCollection() ?? $this->namespace,
            'includeMetadata' => $builder->shouldIncludeMetadata(),
            'includeValues' => $builder->shouldIncludeVectors(),
        ];

        if ($builder->hasConditions()) {
            $payload['filter'] = $this->compiler->compile($builder->getConditions());
        }

        $response = $this->request()
            ->post("{$this->host}/query", $payload)
            ->throw();

        $data = $response->json();
        $matches = $data['matches'] ?? [];

        $results = collect($matches)->map(fn (array $match) => new VectorResult(
            id: $match['id'],
            score: (float) ($match['score'] ?? 0.0),
            metadata: $match['metadata'] ?? [],
            vector: $match['values'] ?? null,
        ));

        // Apply client-side minScore filtering
        if ($builder->getMinScore() !== null) {
            $minScore = $builder->getMinScore();
            $results = $results->filter(fn (VectorResult $r) => $r->score >= $minScore)->values();
        }

        return $results;
    }

    public function flush(): void
    {
        Log::debug('VectorStore[pinecone]: flush');

        $this->request()->post("{$this->host}/vectors/delete", [
            'deleteAll' => true,
            'namespace' => $this->namespace,
        ])->throw();
    }

    /**
     * Create a configured HTTP client with retries and auth.
     */
    protected function request(): PendingRequest
    {
        return Http::withHeaders([
            'Api-Key' => $this->apiKey,
        ])
            ->acceptJson()
            ->asJson()
            ->retry(3, 100, function (\Exception $e, PendingRequest $request) {
                return $e instanceof \Illuminate\Http\Client\RequestException
                    && in_array($e->response->status(), [429, 503]);
            }, false);
    }
}
