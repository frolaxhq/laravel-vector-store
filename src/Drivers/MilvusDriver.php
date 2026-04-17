<?php

declare(strict_types=1);

namespace Frolax\VectorStore\Drivers;

use Frolax\VectorStore\Compilers\MilvusFilterCompiler;
use Frolax\VectorStore\Contracts\VectorStoreContract;
use Frolax\VectorStore\Query\VectorQueryBuilder;
use Frolax\VectorStore\ValueObjects\VectorRecord;
use Frolax\VectorStore\ValueObjects\VectorResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MilvusDriver implements VectorStoreContract
{
    protected string $host;

    protected string $token;

    protected string $collection;

    protected MilvusFilterCompiler $compiler;

    /**
     * @param  array{host?: string, token?: string, collection?: string}  $config
     */
    public function __construct(array $config, MilvusFilterCompiler $compiler)
    {
        $this->host = rtrim($config['host'] ?? 'http://localhost:19530', '/');
        $this->token = $config['token'] ?? '';
        $this->collection = $config['collection'] ?? 'default';
        $this->compiler = $compiler;
    }

    public function upsert(string $id, array $vector, array $metadata = []): void
    {
        Log::debug('VectorStore[milvus]: upsert', ['id' => $id]);

        $data = array_merge(['id' => $id, 'vector' => $vector], $metadata);

        $this->request()->post(
            "{$this->host}/v2/vectordb/entities/upsert",
            [
                'collectionName' => $this->collection,
                'data' => [$data],
            ]
        )->throw();
    }

    public function upsertBatch(array $records): void
    {
        Log::debug('VectorStore[milvus]: upsertBatch', ['count' => count($records)]);

        $data = array_map(function (array $r) {
            return array_merge(
                ['id' => $r['id'], 'vector' => $r['vector']],
                $r['metadata'] ?? []
            );
        }, $records);

        $this->request()->post(
            "{$this->host}/v2/vectordb/entities/upsert",
            [
                'collectionName' => $this->collection,
                'data' => $data,
            ]
        )->throw();
    }

    public function delete(string|array $ids): void
    {
        $ids = is_array($ids) ? $ids : [$ids];

        Log::debug('VectorStore[milvus]: delete', ['ids' => $ids]);

        $this->request()->post(
            "{$this->host}/v2/vectordb/entities/delete",
            [
                'collectionName' => $this->collection,
                'filter' => "id in ['".implode("','", $ids)."']",
            ]
        )->throw();
    }

    public function fetch(string $id): ?VectorRecord
    {
        Log::debug('VectorStore[milvus]: fetch', ['id' => $id]);

        $response = $this->request()->post(
            "{$this->host}/v2/vectordb/entities/get",
            [
                'collectionName' => $this->collection,
                'id' => [$id],
                'outputFields' => ['*'],
            ]
        )->throw();

        $data = $response->json('data') ?? [];

        if (empty($data)) {
            return null;
        }

        $entity = $data[0];

        return new VectorRecord(
            id: (string) $entity['id'],
            vector: $entity['vector'] ?? [],
            metadata: $this->extractMetadata($entity),
        );
    }

    public function fetchBatch(array $ids): Collection
    {
        Log::debug('VectorStore[milvus]: fetchBatch', ['ids' => $ids]);

        $response = $this->request()->post(
            "{$this->host}/v2/vectordb/entities/get",
            [
                'collectionName' => $this->collection,
                'id' => $ids,
                'outputFields' => ['*'],
            ]
        )->throw();

        $data = $response->json('data') ?? [];

        return collect($data)->map(fn (array $entity) => new VectorRecord(
            id: (string) $entity['id'],
            vector: $entity['vector'] ?? [],
            metadata: $this->extractMetadata($entity),
        ));
    }

    public function createIndex(string $name, int $dimensions, array $options = []): void
    {
        Log::debug('VectorStore[milvus]: createIndex', [
            'name' => $name,
            'dimensions' => $dimensions,
        ]);

        $metric = strtoupper($options['metric'] ?? 'COSINE');

        $this->request()->post(
            "{$this->host}/v2/vectordb/collections/create",
            [
                'collectionName' => $name,
                'dimension' => $dimensions,
                'metricType' => $metric,
            ]
        )->throw();
    }

    public function deleteIndex(string $name): void
    {
        Log::debug('VectorStore[milvus]: deleteIndex', ['name' => $name]);

        $this->request()->post(
            "{$this->host}/v2/vectordb/collections/drop",
            ['collectionName' => $name]
        )->throw();
    }

    public function listIndexes(): array
    {
        Log::debug('VectorStore[milvus]: listIndexes');

        $response = $this->request()
            ->post("{$this->host}/v2/vectordb/collections/list")
            ->throw();

        return $response->json('data') ?? [];
    }

    public function query(array $vector): VectorQueryBuilder
    {
        return new VectorQueryBuilder($vector, $this);
    }

    public function executeQuery(VectorQueryBuilder $builder): Collection
    {
        Log::debug('VectorStore[milvus]: executeQuery', [
            'topK' => $builder->getTopK(),
            'hasConditions' => $builder->hasConditions(),
        ]);

        $payload = [
            'collectionName' => $builder->getCollection() ?? $this->collection,
            'data' => [$builder->getVector()],
            'limit' => $builder->getTopK(),
            'outputFields' => ['*'],
        ];

        if ($builder->hasConditions()) {
            $payload['filter'] = $this->compiler->compile($builder->getConditions());
        }

        $response = $this->request()
            ->post("{$this->host}/v2/vectordb/entities/search", $payload)
            ->throw();

        $data = $response->json('data') ?? [];

        $results = collect($data)->map(fn (array $r) => new VectorResult(
            id: (string) $r['id'],
            score: (float) ($r['distance'] ?? 0.0),
            metadata: $this->extractMetadata($r),
            vector: $builder->shouldIncludeVectors() ? ($r['vector'] ?? null) : null,
        ));

        if ($builder->getMinScore() !== null) {
            $minScore = $builder->getMinScore();
            $results = $results->filter(fn (VectorResult $r) => $r->score >= $minScore)->values();
        }

        return $results;
    }

    /**
     * Extract metadata from an entity, excluding system fields.
     */
    protected function extractMetadata(array $entity): array
    {
        $systemFields = ['id', 'vector', 'distance'];

        return array_diff_key($entity, array_flip($systemFields));
    }

    protected function request(): PendingRequest
    {
        $request = Http::acceptJson()->asJson();

        if ($this->token !== '') {
            $request = $request->withHeaders([
                'Authorization' => "Bearer {$this->token}",
            ]);
        }

        return $request->retry(3, 100, function (\Exception $e) {
            return $e instanceof RequestException
                && in_array($e->response->status(), [429, 503]);
        }, false);
    }
}
