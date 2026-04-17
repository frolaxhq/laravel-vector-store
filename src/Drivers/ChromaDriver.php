<?php

declare(strict_types=1);

namespace Frolax\VectorStore\Drivers;

use Frolax\VectorStore\Compilers\ChromaFilterCompiler;
use Frolax\VectorStore\Contracts\VectorStoreContract;
use Frolax\VectorStore\Query\VectorQueryBuilder;
use Frolax\VectorStore\ValueObjects\VectorRecord;
use Frolax\VectorStore\ValueObjects\VectorResult;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChromaDriver implements VectorStoreContract
{
    protected string $host;

    protected string $collectionName;

    protected array $auth;

    protected ChromaFilterCompiler $compiler;

    /** @var string|null Cached collection ID */
    protected ?string $collectionId = null;

    /**
     * @param  array{host?: string, collection?: string, auth?: array} $config
     */
    public function __construct(array $config, ChromaFilterCompiler $compiler)
    {
        $this->host = rtrim($config['host'] ?? 'http://localhost:8000', '/');
        $this->collectionName = $config['collection'] ?? 'default';
        $this->auth = $config['auth'] ?? ['type' => 'none'];
        $this->compiler = $compiler;
    }

    public function upsert(string $id, array $vector, array $metadata = []): void
    {
        Log::debug('VectorStore[chroma]: upsert', ['id' => $id]);

        $collectionId = $this->resolveCollectionId();

        $this->request()->post(
            "{$this->host}/api/v1/collections/{$collectionId}/upsert",
            [
                'ids' => [$id],
                'embeddings' => [$vector],
                'metadatas' => [(object) $metadata],
            ]
        )->throw();
    }

    public function upsertBatch(array $records): void
    {
        Log::debug('VectorStore[chroma]: upsertBatch', ['count' => count($records)]);

        $collectionId = $this->resolveCollectionId();

        $this->request()->post(
            "{$this->host}/api/v1/collections/{$collectionId}/upsert",
            [
                'ids' => array_column($records, 'id'),
                'embeddings' => array_column($records, 'vector'),
                'metadatas' => array_map(
                    fn ($r) => (object) ($r['metadata'] ?? []),
                    $records
                ),
            ]
        )->throw();
    }

    public function delete(string|array $ids): void
    {
        $ids = is_array($ids) ? $ids : [$ids];

        Log::debug('VectorStore[chroma]: delete', ['ids' => $ids]);

        $collectionId = $this->resolveCollectionId();

        $this->request()->post(
            "{$this->host}/api/v1/collections/{$collectionId}/delete",
            ['ids' => $ids]
        )->throw();
    }

    public function fetch(string $id): ?VectorRecord
    {
        Log::debug('VectorStore[chroma]: fetch', ['id' => $id]);

        $collectionId = $this->resolveCollectionId();

        $response = $this->request()->post(
            "{$this->host}/api/v1/collections/{$collectionId}/get",
            [
                'ids' => [$id],
                'include' => ['embeddings', 'metadatas'],
            ]
        )->throw();

        $data = $response->json();

        if (empty($data['ids'])) {
            return null;
        }

        return new VectorRecord(
            id: $data['ids'][0],
            vector: $data['embeddings'][0] ?? [],
            metadata: $data['metadatas'][0] ?? [],
        );
    }

    public function fetchBatch(array $ids): Collection
    {
        Log::debug('VectorStore[chroma]: fetchBatch', ['ids' => $ids]);

        $collectionId = $this->resolveCollectionId();

        $response = $this->request()->post(
            "{$this->host}/api/v1/collections/{$collectionId}/get",
            [
                'ids' => $ids,
                'include' => ['embeddings', 'metadatas'],
            ]
        )->throw();

        $data = $response->json();
        $results = collect();

        foreach ($data['ids'] ?? [] as $i => $id) {
            $results->push(new VectorRecord(
                id: $id,
                vector: $data['embeddings'][$i] ?? [],
                metadata: $data['metadatas'][$i] ?? [],
            ));
        }

        return $results;
    }

    public function createIndex(string $name, int $dimensions, array $options = []): void
    {
        Log::debug('VectorStore[chroma]: createIndex', [
            'name' => $name,
            'dimensions' => $dimensions,
        ]);

        $payload = ['name' => $name];

        if (! empty($options['metadata'])) {
            $payload['metadata'] = $options['metadata'];
        }

        $this->request()
            ->post("{$this->host}/api/v1/collections", $payload)
            ->throw();
    }

    public function deleteIndex(string $name): void
    {
        Log::debug('VectorStore[chroma]: deleteIndex', ['name' => $name]);

        $this->request()
            ->delete("{$this->host}/api/v1/collections/{$name}")
            ->throw();

        // Clear cached ID if we deleted the current collection
        if ($name === $this->collectionName) {
            $this->collectionId = null;
        }
    }

    public function listIndexes(): array
    {
        Log::debug('VectorStore[chroma]: listIndexes');

        $response = $this->request()
            ->get("{$this->host}/api/v1/collections")
            ->throw();

        return array_map(fn (array $c) => $c['name'], $response->json());
    }

    public function query(array $vector): VectorQueryBuilder
    {
        return new VectorQueryBuilder($vector, $this);
    }

    public function executeQuery(VectorQueryBuilder $builder): Collection
    {
        Log::debug('VectorStore[chroma]: executeQuery', [
            'topK' => $builder->getTopK(),
            'hasConditions' => $builder->hasConditions(),
        ]);

        $collectionId = $this->resolveCollectionId($builder->getCollection());

        $payload = [
            'query_embeddings' => [$builder->getVector()],
            'n_results' => $builder->getTopK(),
            'include' => ['distances', 'metadatas'],
        ];

        if ($builder->shouldIncludeVectors()) {
            $payload['include'][] = 'embeddings';
        }

        if ($builder->hasConditions()) {
            $payload['where'] = $this->compiler->compile($builder->getConditions());
        }

        $response = $this->request()
            ->post("{$this->host}/api/v1/collections/{$collectionId}/query", $payload)
            ->throw();

        $data = $response->json();

        $ids = $data['ids'][0] ?? [];
        $distances = $data['distances'][0] ?? [];
        $metadatas = $data['metadatas'][0] ?? [];
        $embeddings = $data['embeddings'][0] ?? [];

        $results = collect();

        foreach ($ids as $i => $id) {
            $score = 1.0 - ($distances[$i] ?? 0.0); // Chroma returns distances

            if ($builder->getMinScore() !== null && $score < $builder->getMinScore()) {
                continue;
            }

            $results->push(new VectorResult(
                id: $id,
                score: $score,
                metadata: $metadatas[$i] ?? [],
                vector: $builder->shouldIncludeVectors() ? ($embeddings[$i] ?? null) : null,
            ));
        }

        return $results;
    }

    /**
     * Resolve the collection ID from its name.
     */
    protected function resolveCollectionId(?string $name = null): string
    {
        $name = $name ?? $this->collectionName;

        if ($this->collectionId !== null && $name === $this->collectionName) {
            return $this->collectionId;
        }

        $response = $this->request()
            ->get("{$this->host}/api/v1/collections/{$name}")
            ->throw();

        $id = $response->json('id');

        if ($name === $this->collectionName) {
            $this->collectionId = $id;
        }

        return $id;
    }

    protected function request(): PendingRequest
    {
        $request = Http::acceptJson()->asJson();

        if (($this->auth['type'] ?? 'none') === 'token' && ! empty($this->auth['token'])) {
            $request = $request->withHeaders([
                'Authorization' => "Bearer {$this->auth['token']}",
            ]);
        }

        return $request->retry(3, 100, function (\Exception $e) {
            return $e instanceof \Illuminate\Http\Client\RequestException
                && in_array($e->response->status(), [429, 503]);
        }, false);
    }
}
