---
name: laravel-vector-store-development
description: "Comprehensive reference for implementing, debugging, and extending this package. Use for any change to drivers, compilers, query builder AST, manager/facade wiring, config/env contracts, commands, migration, or tests. This package only stores and queries raw float vectors; embedding generation is explicitly out of scope."
license: MIT
metadata:
  author: frolaxhq
---

# Laravel Vector Store Development

## Purpose And Scope

This package is a Laravel vector-store abstraction layer that mirrors Storage/Cache manager patterns:
- one default store
- optional named stores
- one unified API surface across multiple backends

Hard boundary:
- it stores, fetches, deletes, indexes, and queries vectors (`float[]`)
- it does not generate embeddings

## When To Apply This Skill

Use this reference for any changes in:
- `src/Drivers/*Driver.php`
- `src/Compilers/*FilterCompiler.php`
- `src/Contracts/*`
- `src/Query/VectorQueryBuilder.php`
- `src/VectorStoreManager.php`
- `src/Facades/VectorStore.php`
- `src/Commands/*`
- `config/vector-store.php`
- `database/migrations/create_vector_records_table.php.stub`
- `tests/**`

## Source-Of-Truth Map

- Public facade API: `src/Facades/VectorStore.php`
- Runtime resolution and driver wiring: `src/VectorStoreManager.php`
- Service registration and commands/migrations/config publishing: `src/VectorStoreServiceProvider.php`
- Driver contract: `src/Contracts/VectorStoreContract.php`
- Filter contract: `src/Contracts/FilterCompilerContract.php`
- Query builder AST and fluent methods: `src/Query/VectorQueryBuilder.php`
- Immutable DTOs: `src/ValueObjects/VectorRecord.php`, `src/ValueObjects/VectorResult.php`
- Driver implementations: `src/Drivers/*Driver.php`
- Filter compilers: `src/Compilers/*FilterCompiler.php`
- Package behavior overview: `README.md`

## Architecture And Data Flow

End-to-end flow:
1. App code calls `VectorStore` facade.
2. Facade resolves `VectorStoreManager`.
3. Manager resolves default/named driver.
4. Driver performs backend-native calls or SQL.
5. Query filtering is compiled from normalized AST through the matching compiler.
6. Driver returns `VectorRecord`/`VectorResult` objects.

### Query Pipeline Contract

`VectorQueryBuilder` always normalizes conditions to:
`['field' => string, 'op' => string, 'value' => mixed]`

Drivers must consume builder state only through accessors:
- `getVector()`
- `getTopK()`
- `getCollection()`
- `getMinScore()`
- `shouldIncludeMetadata()`
- `shouldIncludeVectors()`
- `getConditions()`
- `hasConditions()`

Do not bypass this by building ad-hoc filter objects in builder APIs.

## Non-Negotiable Project Rules

- Keep `declare(strict_types=1);` in `src/` and tests.
- Keep driver logging style:
  `Log::debug('VectorStore[{driver}]: {action}', [...])`
- Preserve full operator support in each compiler:
  `= != > >= < <= in not_in between null not_null`
- Preserve optional flush behavior in `vector:flush`:
  `method_exists($store, 'flush')`
- Preserve backend-specific payload key names.

## Public API Reference

From `VectorStoreContract`:
- `upsert(string $id, array $vector, array $metadata = []): void`
- `upsertBatch(array $records): void`
- `delete(string|array $ids): void`
- `fetch(string $id): ?VectorRecord`
- `fetchBatch(array $ids): Collection`
- `createIndex(string $name, int $dimensions, array $options = []): void`
- `deleteIndex(string $name): void`
- `listIndexes(): array`
- `query(array $vector): VectorQueryBuilder`
- `executeQuery(VectorQueryBuilder $builder): Collection`

From `VectorQueryBuilder`:
- filtering: `where`, `whereIn`, `whereNotIn`, `whereBetween`, `whereNull`, `whereNotNull`
- query controls: `topK`, `collection`, `minScore`, `includeMetadata`, `includeVectors`
- execution: `get`, `first`, `paginate`

## Value Objects

- `VectorRecord`: `id`, `vector`, `metadata`
- `VectorResult`: `id`, `score`, `metadata`, `vector|null`

Both are readonly and `JsonSerializable`.

## Driver Behavior Matrix

### Pinecone (`src/Drivers/PineconeDriver.php`)
- Transport: REST
- Auth: `Api-Key` header
- Index ops use control-plane endpoint: `https://api.pinecone.io/indexes`
- Data ops use configured `host`
- Query result score is backend score
- `minScore`: client-side filter
- Supports `flush()`

### Qdrant (`src/Drivers/QdrantDriver.php`)
- Transport: REST
- Auth: optional `api-key` header
- Query can push threshold server-side via `score_threshold`
- `minScore`: server-side when set
- No explicit `flush()` method

### Weaviate (`src/Drivers/WeaviateDriver.php`)
- Transport: REST + GraphQL query endpoint
- Auth: optional Bearer token
- Query generated as GraphQL with `_additional` fields
- Compiler output is converted to GraphQL object via `toGraphQLObject()`
- `minScore` mapped to `nearVector.certainty`
- No explicit `flush()` method

### Chroma (`src/Drivers/ChromaDriver.php`)
- Transport: REST
- Auth: optional token based on `auth.type`
- Uses collection name -> resolves collection ID
- Chroma returns distance; score is normalized to similarity by `1.0 - distance`
- `minScore`: client-side
- No explicit `flush()` method

### Milvus (`src/Drivers/MilvusDriver.php`)
- Transport: REST
- Auth: optional Bearer token
- Search maps backend `distance` into `VectorResult.score` directly
- `minScore`: client-side
- No explicit `flush()` method

### pgvector (`src/Drivers/PgvectorDriver.php`)
- Transport: Laravel DB builder / SQL
- Metric operators:
  - cosine: `<=>`
  - l2: `<->`
  - ip: `<#>`
- Converts distance <-> similarity via `distanceToScore()` and `scoreToDistance()`
- `minScore` translated to distance filter (`having`)
- Supports `flush()` (`truncate`)

## HTTP Conventions (All HTTP Drivers)

- Start request with `Http::acceptJson()->asJson()`
- Retry: `retry(3, 100, ..., false)` with transient statuses `429`, `503`
- Apply auth headers conditionally

## Filter Compiler Contract

Each compiler must implement:
- `compile(array $conditions): mixed`

Accepted condition shape:
- `array<int, array{field: string, op: string, value?: mixed}>`

Compiler outputs are backend-native:
- Pinecone/Qdrant/Chroma: JSON-like filter objects
- Weaviate: where tree consumed by GraphQL renderer
- Milvus: filter expression string
- pgvector: SQL fragment + bindings

## Config And Environment Reference

Config file: `config/vector-store.php`

Global:
- `VECTOR_STORE`

Pinecone:
- `PINECONE_API_KEY`
- `PINECONE_HOST`
- `PINECONE_NAMESPACE`

Qdrant:
- `QDRANT_HOST`
- `QDRANT_API_KEY`
- `QDRANT_COLLECTION`

Weaviate:
- `WEAVIATE_HOST`
- `WEAVIATE_API_KEY`
- `WEAVIATE_CLASS`

Chroma:
- `CHROMA_HOST`
- `CHROMA_COLLECTION`
- `CHROMA_AUTH_TYPE`
- `CHROMA_AUTH_TOKEN`

Milvus:
- `MILVUS_HOST`
- `MILVUS_TOKEN`
- `MILVUS_COLLECTION`

pgvector:
- `PGVECTOR_CONNECTION`
- `PGVECTOR_TABLE`
- `PGVECTOR_DIMENSIONS`
- `PGVECTOR_METRIC`

## Commands Reference

From `src/Commands/*`:

- `vector:list-indexes {--store=}`
- `vector:create-index {name} {dimensions} {--store=} {--metric=cosine}`
- `vector:delete-index {name} {--store=} {--force}`
- `vector:flush {--store=} {--force}`

`vector:flush` only works for drivers exposing `flush()`.

## Service Provider And Registration

`VectorStoreServiceProvider` (`src/VectorStoreServiceProvider.php`):
- package name: `vector-store`
- publishes config
- publishes migration `create_vector_records_table`
- registers command classes
- binds singleton `VectorStoreManager`
- aliases manager to `vector-store`

## Testing Strategy

Framework and tools:
- Pest
- Orchestra Testbench
- Laravel Pint

Primary commands:
- `composer test`
- `composer test-coverage`
- `composer format`

Patterns to follow:
- HTTP drivers: `Http::fake()` + `Http::assertSent()`
- Builder: verify delegation through `executeQuery()`
- Facade tests: `VectorStore::fake()` and assertions from `VectorStoreFake`
- Architecture guard: no `dd`, `dump`, `ray`

## Extension Playbooks

### Add A New Driver

1. Implement `VectorStoreContract` in `src/Drivers/{Name}Driver.php`.
2. Implement matching compiler `src/Compilers/{Name}FilterCompiler.php`.
3. Add factory in `VectorStoreManager`:
   `create{Name}Driver()`.
4. Add config block in `config/vector-store.php`.
5. Add tests:
   - CRUD + query behavior
   - auth + payload shape
   - filter compilation
   - score/minScore semantics

### Add Or Change Filters

1. Keep builder AST unchanged.
2. Update compiler implementation.
3. Ensure all operators remain supported.
4. Add compiler unit tests and one driver integration assertion.

## Common Failure Modes

- Injecting provider-native filters directly into builder layer.
- Forgetting manager wiring after adding driver/compiler.
- Returning backend distance directly when similarity normalization is expected.
- Breaking command signatures/options consumed by users.
- Changing config keys/env names without docs and tests.

## PR Readiness Checklist

- [ ] Strict types preserved.
- [ ] Contract signatures unchanged unless intentional and documented.
- [ ] Builder AST shape preserved.
- [ ] Compiler operator coverage complete.
- [ ] Driver score/minScore semantics verified.
- [ ] HTTP retries/auth and payload shapes verified.
- [ ] Command behavior and help text still correct.
- [ ] Tests added/updated and passing.
- [ ] README/config examples still accurate.

