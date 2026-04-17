<?php

declare(strict_types=1);

namespace Frolax\VectorStore\Facades;

use Frolax\VectorStore\Contracts\VectorStoreContract;
use Frolax\VectorStore\Query\VectorQueryBuilder;
use Frolax\VectorStore\Testing\VectorStoreFake;
use Frolax\VectorStore\ValueObjects\VectorRecord;
use Frolax\VectorStore\VectorStoreManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;

/**
 * @method static VectorStoreContract store(?string $name = null)
 * @method static void upsert(string $id, array $vector, array $metadata = [])
 * @method static void upsertBatch(array $records)
 * @method static void delete(string|array $ids)
 * @method static VectorRecord|null fetch(string $id)
 * @method static Collection fetchBatch(array $ids)
 * @method static void createIndex(string $name, int $dimensions, array $options = [])
 * @method static void deleteIndex(string $name)
 * @method static array listIndexes()
 * @method static VectorQueryBuilder query(array $vector)
 *
 * @see \Frolax\VectorStore\VectorStoreManager
 */
class VectorStore extends Facade
{
    /**
     * Replace the bound instance with a fake for testing.
     */
    public static function fake(): VectorStoreFake
    {
        $fake = new VectorStoreFake;

        static::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return VectorStoreManager::class;
    }
}
