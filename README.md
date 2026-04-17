# Laravel Vector Store

[![Latest Version on Packagist](https://img.shields.io/packagist/v/frolaxhq/laravel-vector-store.svg?style=flat-square)](https://packagist.org/packages/frolaxhq/laravel-vector-store)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/frolaxhq/laravel-vector-store/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/frolaxhq/laravel-vector-store/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/frolaxhq/laravel-vector-store.svg?style=flat-square)](https://packagist.org/packages/frolaxhq/laravel-vector-store)

A unified, eloquent-like interface for managing vectors across multiple databases in Laravel. It perfectly mirrors Laravel's native `Filesystem` and `Cache` manager patterns. 

**This package intentionally does *not* handle embedding creation.** It is designed strictly to store, manage, and query your raw vectors (`float[]`), allowing you to remain completely independent of any specific AI model or HTTP client. 

## Supported Drivers
- **Pinecone** (REST API)
- **Qdrant** (REST API)
- **Chroma** (REST API)
- **Milvus** (REST API)
- **Weaviate** (GraphQL / REST API)
- **pgvector** (PostgreSQL / DB Builder)

## Requirements
- PHP 8.3+
- Laravel 11.x, 12.x, or 13.x

## Installation

You can install the package via composer:

```bash
composer require frolaxhq/laravel-vector-store
```

Publish the configuration file:

```bash
php artisan vendor:publish --tag="vector-store-config"
```

If you plan on using the `pgvector` driver, you should also publish and run the migrations:

```bash
php artisan vendor:publish --tag="vector-store-migrations"
php artisan migrate
```

## Basic Usage

The `VectorStore` facade operates identically to Laravel's `Storage` facade. It relies on the default driver defined in `config/vector-store.php`, but allows you to switch drivers on the fly.

### Upserting Vectors

```php
use Frolax\VectorStore\Facades\VectorStore;

// Insert a single vector
VectorStore::upsert(
    id: 'doc-1',
    vector: [0.15, -0.05, 0.99, ...],
    metadata: ['category' => 'article', 'author_id' => 5]
);

// Switch drivers explicitly
VectorStore::store('qdrant')->upsert('doc-2', [0.1, 0.2], ['title' => 'Hello']);

// Batch insert
VectorStore::upsertBatch([
    ['id' => '1', 'vector' => [0.1, 0.2], 'metadata' => ['tags' => ['a', 'b']]],
    ['id' => '2', 'vector' => [0.3, 0.4], 'metadata' => ['tags' => ['c']]],
]);
```

### Fetching & Deleting By ID

```php
// Retrieve a VectorRecord object
$record = VectorStore::fetch('doc-1');
echo $record->id;
print_r($record->metadata);

$records = VectorStore::fetchBatch(['doc-1', 'doc-2']);

// Delete vectors
VectorStore::delete('doc-1');
VectorStore::delete(['doc-1', 'doc-2']);
```

## Querying

The package provides a fluent, predictable query builder to search for similar vectors while filtering against metadata. The builder automatically translates your `where` clauses into the driver's native syntax (e.g., Pinecone's MongoDB-like syntax, pgvector SQL, or Weaviate's GraphQL).

```php
$results = VectorStore::query($queryVector)
    ->topK(10)
    ->minScore(0.75) // Only return results with a similarity score >= 0.75
    ->where('category', 'article')
    ->where('author_id', 5)
    ->whereIn('status', ['published', 'draft'])
    ->get(); // Returns a Collection of VectorResult objects

foreach ($results as $result) {
    echo $result->id . ' - Score: ' . $result->score;
    print_r($result->metadata);
}
```

### Supported Filter Methods
- `where('field', 'value')`
- `where('field', '>=', 10)`
- `whereIn('field', ['a', 'b'])`
- `whereNotIn('field', ['a', 'b'])`
- `whereBetween('field', [10, 50])`
- `whereNull('field')`
- `whereNotNull('field')`

### Pagination
If you need to display results in a UI, you can use the built-in pagination:

```php
$paginator = VectorStore::query($queryVector)
    ->where('public', true)
    ->paginate(perPage: 15, page: 2);

// Returns an array: ['data' => Collection, 'page' => 2, 'per_page' => 15, 'has_more' => true]
```

## Index / Collection Management

Manage your indexes identically across providers:

```php
// List available indexes
$indexes = VectorStore::listIndexes();

// Create a new index (1536 dimensions, cosine metric)
VectorStore::createIndex('my_new_index', 1536, ['metric' => 'cosine']);

// Delete an index
VectorStore::deleteIndex('my_new_index');
```

## Artisan Commands

The package includes CLI commands to manage your stores directly from your terminal:

```bash
# List all indexes on the default store
php artisan vector:list-indexes

# Create a new index on Qdrant
php artisan vector:create-index my_docs 1536 --store=qdrant --metric=cosine

# Delete an index
php artisan vector:delete-index my_docs --force

# Delete all vectors but keep the index intact
php artisan vector:flush --store=pinecone
```

## Testing

For testing your own application logic without making real API calls, you can swap the facade with an in-memory Fake.

```php
use Frolax\VectorStore\Facades\VectorStore;

public function test_document_is_vectorised()
{
    VectorStore::fake();

    // Trigger your application logic...
    $action->handle($document);

    // Assert a specific ID was upserted
    VectorStore::assertUpserted('doc-1');

    // Assert with a closure constraint
    VectorStore::assertUpserted('doc-1', function ($data) {
        return $data['metadata']['author'] === 'John';
    });

    // Assert a query was run
    VectorStore::assertQueried();
}
```

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
