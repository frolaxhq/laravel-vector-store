<?php

declare(strict_types=1);

namespace Frolax\VectorStore\Drivers;

use Frolax\VectorStore\Compilers\QdrantFilterCompiler;
use Frolax\VectorStore\Contracts\VectorStoreContract;
use Frolax\VectorStore\Query\VectorQueryBuilder;
use Frolax\VectorStore\ValueObjects\VectorRecord;
use Frolax\VectorStore\ValueObjects\VectorResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class QdrantDriver implements VectorStoreContract
{
    protected string $host;

    protected string $apiKey;

    protected string $collection;

    protected QdrantFilterCompiler $compiler;

    /**
     * @param  array{host?: string, api_key?: string, collection?: string} $config
     */
    public function __construct(array $config, QdrantFilterCompiler $compiler)
    {
        $this->host = rtrim($config['host'] ?? 'http://localhost:6333', '/');
        $this->apiKey = $config['api_key'] ?? '';
        $this->collection = $config['collection'] ?? 'default';
        $this->compiler = $compiler;
    }

    public function upsert(string $id, array $vector, array $metadata = []): void
    {
        Log::debug('VectorStore[qdrant]: upsert', ['id' => $id]);

        $this->request()->put(
            "{$this->host}/collections/{$this->collection}/points",
            [
                'points' => [
                    [
                        'id' => $id,
                        'vector' => $vector,
                        'payload' => (object) $metadata,
                    ],
                ],
            ]
        )->throw();
    }

    public function upsertBatch(array $records): void
    {
        Log::debug('VectorStore[qdrant]: upsertBatch', ['count' => count($records)]);

        $points = array_map(fn (array $r) => [
            'id' => $r['id'],
            'vector' => $r['vector'],
            'payload' => (object) ($r['metadata'] ?? []),
        ], $records);

        $this->request()->put(
            "{$this->host}/collections/{$this->collection}/points",
            ['points' => $points]
        )->throw();
    }

    public function delete(string|array $ids): void
    {
        $ids = is_array($ids) ? $ids : [$ids];

        Log::debug('VectorStore[qdrant]: delete', ['ids' => $ids]);

        $this->request()->post(
            "{$this->host}/collections/{$this->collection}/points/delete",
            ['points' => $ids]
        )->throw();
    }

    public function fetch(string $id): ?VectorRecord
    {
        Log::debug('VectorStore[qdrant]: fetch', ['id' => $id]);

        $response = $this->request()
            ->get("{$this->host}/collections/{$this->collection}/points/{$id}");

        if ($response->status() === 404) {
            return null;
        }

        $response->throw();

        $data = $response->json();
        $point = $data['result'] ?? null;

        if (is_null($point)) {
            return null;
        }

        return new VectorRecord(
            id: (string) $point['id'],
            vector: $point['vector'] ?? [],
            metadata: $point['payload'] ?? [],
        );
    }

    public function fetchBatch(array $ids): Collection
    {
        Log::debug('VectorStore[qdrant]: fetchBatch', ['ids' => $ids]);

        $response = $this->request()->post(
            "{$this->host}/collections/{$this->collection}/points",
            [
                'ids' => $ids,
                'with_vector' => true,
                'with_payload' => true,
            ]
        )->throw();

        $data = $response->json();
        $points = $data['result'] ?? [];

        return collect($points)->map(fn (array $point) => new VectorRecord(
            id: (string) $point['id'],
            vector: $point['vector'] ?? [],
            metadata: $point['payload'] ?? [],
        ));
    }

    public function createIndex(string $name, int $dimensions, array $options = []): void
    {
        Log::debug('VectorStore[qdrant]: createIndex', [
            'name' => $name,
            'dimensions' => $dimensions,
        ]);

        $metric = $options['metric'] ?? 'Cosine';
        $metricMap = [
            'cosine' => 'Cosine',
            'l2' => 'Euclid',
            'ip' => 'Dot',
        ];
        $distance = $metricMap[strtolower($metric)] ?? $metric;

        $this->request()->put(
            "{$this->host}/collections/{$name}",
            [
                'vectors' => [
                    'size' => $dimensions,
                    'distance' => $distance,
                ],
            ]
        )->throw();
    }

    public function deleteIndex(string $name): void
    {
        Log::debug('VectorStore[qdrant]: deleteIndex', ['name' => $name]);

        $this->request()
            ->delete("{$this->host}/collections/{$name}")
            ->throw();
    }

    public function listIndexes(): array
    {
        Log::debug('VectorStore[qdrant]: listIndexes');

        $response = $this->request()
            ->get("{$this->host}/collections")
            ->throw();

        $data = $response->json();
        $collections = $data['result']['collections'] ?? [];

        return array_map(fn (array $c) => $c['name'], $collections);
    }

    public function query(array $vector): VectorQueryBuilder
    {
        return new VectorQueryBuilder($vector, $this);
    }

    public function executeQuery(VectorQueryBuilder $builder): Collection
    {
        Log::debug('VectorStore[qdrant]: executeQuery', [
            'topK' => $builder->getTopK(),
            'hasConditions' => $builder->hasConditions(),
        ]);

        $payload = [
            'vector' => $builder->getVector(),
            'limit' => $builder->getTopK(),
            'with_payload' => $builder->shouldIncludeMetadata(),
            'with_vector' => $builder->shouldIncludeVectors(),
        ];

        if ($builder->getMinScore() !== null) {
            $payload['score_threshold'] = $builder->getMinScore();
        }

        if ($builder->hasConditions()) {
            $payload['filter'] = $this->compiler->compile($builder->getConditions());
        }

        $collection = $builder->getCollection() ?? $this->collection;

        $response = $this->request()
            ->post("{$this->host}/collections/{$collection}/points/search", $payload)
            ->throw();

        $data = $response->json();
        $results = $data['result'] ?? [];

        return collect($results)->map(fn (array $r) => new VectorResult(
            id: (string) $r['id'],
            score: (float) ($r['score'] ?? 0.0),
            metadata: $r['payload'] ?? [],
            vector: $r['vector'] ?? null,
        ));
    }

    protected function request(): PendingRequest
    {
        $request = Http::acceptJson()->asJson();

        if ($this->apiKey !== '') {
            $request = $request->withHeaders(['api-key' => $this->apiKey]);
        }

        return $request->retry(3, 100, function (\Exception $e) {
            return $e instanceof \Illuminate\Http\Client\RequestException
                && in_array($e->response->status(), [429, 503]);
        }, false);
    }
}
