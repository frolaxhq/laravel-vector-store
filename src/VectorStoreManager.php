<?php

declare(strict_types=1);

namespace Frolax\VectorStore;

use Frolax\VectorStore\Compilers\ChromaFilterCompiler;
use Frolax\VectorStore\Compilers\MilvusFilterCompiler;
use Frolax\VectorStore\Compilers\PgvectorFilterCompiler;
use Frolax\VectorStore\Compilers\PineconeFilterCompiler;
use Frolax\VectorStore\Compilers\QdrantFilterCompiler;
use Frolax\VectorStore\Compilers\WeaviateFilterCompiler;
use Frolax\VectorStore\Contracts\VectorStoreContract;
use Frolax\VectorStore\Drivers\ChromaDriver;
use Frolax\VectorStore\Drivers\MilvusDriver;
use Frolax\VectorStore\Drivers\PgvectorDriver;
use Frolax\VectorStore\Drivers\PineconeDriver;
use Frolax\VectorStore\Drivers\QdrantDriver;
use Frolax\VectorStore\Drivers\WeaviateDriver;
use Frolax\VectorStore\Exceptions\VectorStoreException;
use Illuminate\Support\Manager;

/**
 * Manages named vector store instances, mirroring Laravel's Filesystem Manager pattern.
 *
 * Usage:
 *   VectorStore::store('pinecone')->upsert(...);
 *   VectorStore::upsert(...); // uses default store
 */
class VectorStoreManager extends Manager
{
    /**
     * Get a vector store instance by name.
     */
    public function store(?string $name = null): VectorStoreContract
    {
        return $this->driver($name);
    }

    /**
     * Get the default driver name.
     */
    public function getDefaultDriver(): string
    {
        return $this->config->get('vector-store.default', 'pinecone');
    }

    /**
     * Create a Pinecone driver instance.
     */
    protected function createPineconeDriver(): VectorStoreContract
    {
        $config = $this->getStoreConfig('pinecone');

        return new PineconeDriver($config, new PineconeFilterCompiler);
    }

    /**
     * Create a Qdrant driver instance.
     */
    protected function createQdrantDriver(): VectorStoreContract
    {
        $config = $this->getStoreConfig('qdrant');

        return new QdrantDriver($config, new QdrantFilterCompiler);
    }

    /**
     * Create a Weaviate driver instance.
     */
    protected function createWeaviateDriver(): VectorStoreContract
    {
        $config = $this->getStoreConfig('weaviate');

        return new WeaviateDriver($config, new WeaviateFilterCompiler);
    }

    /**
     * Create a Chroma driver instance.
     */
    protected function createChromaDriver(): VectorStoreContract
    {
        $config = $this->getStoreConfig('chroma');

        return new ChromaDriver($config, new ChromaFilterCompiler);
    }

    /**
     * Create a Milvus driver instance.
     */
    protected function createMilvusDriver(): VectorStoreContract
    {
        $config = $this->getStoreConfig('milvus');

        return new MilvusDriver($config, new MilvusFilterCompiler);
    }

    /**
     * Create a pgvector driver instance.
     */
    protected function createPgvectorDriver(): VectorStoreContract
    {
        $config = $this->getStoreConfig('pgvector');

        return new PgvectorDriver($config, new PgvectorFilterCompiler);
    }

    /**
     * Get the configuration for a specific store.
     *
     * @return array<string, mixed>
     *
     * @throws VectorStoreException
     */
    protected function getStoreConfig(string $name): array
    {
        $config = $this->config->get("vector-store.stores.{$name}");

        if (is_null($config)) {
            throw VectorStoreException::invalidConfiguration(
                $name,
                "Store [{$name}] is not configured in config/vector-store.php."
            );
        }

        return $config;
    }
}
