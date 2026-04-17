# AGENTS.md

## Purpose and scope
- This package is a Laravel vector-store abstraction layer (not an embedding generator). Keep logic focused on storing/querying raw `float[]` vectors (`README.md`, `src/Contracts/VectorStoreContract.php`).
- API shape intentionally mirrors Laravel manager/facade patterns (`Storage`-style): default store + named stores (`src/VectorStoreManager.php`, `src/Facades/VectorStore.php`).

## Big-picture architecture
- Service registration: `VectorStoreServiceProvider` binds a singleton `VectorStoreManager` and publishes config/migration/commands (`src/VectorStoreServiceProvider.php`).
- Runtime boundary: app code calls `VectorStore` facade -> manager resolves driver -> driver executes provider-native API/SQL (`src/Facades/VectorStore.php`, `src/VectorStoreManager.php`, `src/Drivers/*Driver.php`).
- Query boundary: `VectorQueryBuilder` builds a normalized condition AST; drivers compile that AST to native filter syntax via compiler classes (`src/Query/VectorQueryBuilder.php`, `src/Contracts/FilterCompilerContract.php`, `src/Compilers/*FilterCompiler.php`).
- Data objects are immutable readonly value objects (`VectorRecord`, `VectorResult`) used across all drivers (`src/ValueObjects/*.php`).

## Data flow you should preserve
- Query conditions are always normalized to `['field','op','value']` entries and passed to compiler only from `executeQuery()`; do not build ad-hoc filter formats in builder (`src/Query/VectorQueryBuilder.php`).
- Score semantics differ by backend; drivers normalize before returning `VectorResult` (e.g., pgvector distance -> similarity in `distanceToScore()`, Chroma `1 - distance`) (`src/Drivers/PgvectorDriver.php`, `src/Drivers/ChromaDriver.php`).
- `minScore` is enforced driver-side when possible (Qdrant), otherwise post-filtered client-side (Pinecone/Chroma/Milvus) (`src/Drivers/*Driver.php`).

## Driver and compiler extension pattern
- Add a driver class implementing `VectorStoreContract` with the full CRUD/index/query surface; follow method names/signatures exactly (`src/Contracts/VectorStoreContract.php`).
- Add a matching compiler implementing `FilterCompilerContract`, including all operators: `= != > >= < <= in not_in between null not_null`.
- Wire both in `VectorStoreManager` via `create{Name}Driver()` + `getStoreConfig('{name}')` lookup.
- Add config under `config/vector-store.php` with env-driven keys and sensible localhost defaults.
- Keep HTTP drivers on `Http::acceptJson()->asJson()->retry(3, 100, ... [429,503])` and include auth headers conditionally (see Pinecone/Qdrant/Weaviate/Chroma/Milvus drivers).

## Project-specific conventions
- `declare(strict_types=1);` is used everywhere in `src/` and tests; keep strict typing and explicit return types.
- Logging convention is `Log::debug('VectorStore[{driver}]: {action}', [...])` at each public operation in drivers.
- `upsertBatch()` is explicit per-driver payload mapping (no shared base class); preserve provider-specific payload keys (`values`, `payload`, `properties`, etc.).
- `FlushCommand` depends on optional `flush()` method detection (`method_exists`) rather than interface contract; keep this compatibility behavior (`src/Commands/FlushCommand.php`).

## Developer workflow
- Install deps: `composer install`.
- Run test suite: `composer test` (Pest + Testbench, configured by `tests/Pest.php` and `tests/TestCase.php`).
- Run targeted tests while iterating: `vendor/bin/pest tests/Unit/Query/VectorQueryBuilderTest.php`.
- Format: `composer format` (`laravel/pint`).
- Coverage: `composer test-coverage`; JUnit output goes to `build/report.junit.xml` (`phpunit.xml.dist`).

## Testing patterns to follow
- HTTP drivers are tested with `Http::fake()` + `Http::assertSent()` to lock request URL, method, auth headers, and payload shape (`tests/Feature/Drivers/*DriverTest.php`).
- Query builder behavior is verified via mocked `VectorStoreContract` delegation (`tests/Unit/Query/VectorQueryBuilderTest.php`).
- Facade-level app tests should use `VectorStore::fake()` and assertion helpers (`assertUpserted`, `assertQueried`, etc.) (`src/Testing/VectorStoreFake.php`).
- Keep architecture lint clean: no `dd`, `dump`, or `ray` usage (`tests/ArchTest.php`).

## Integration notes
- `pgvector` requires published migration; migration calls `Schema::ensureVectorExtensionExists()` and uses configured dimensions/table (`database/migrations/create_vector_records_table.php.stub`).
- Some backends use separate control-plane endpoints for index operations (e.g., Pinecone `https://api.pinecone.io/indexes`) while data operations use configured host (`src/Drivers/PineconeDriver.php`).
- Weaviate query path is GraphQL string generation; filters are compiled to array then converted via `toGraphQLObject()` (`src/Drivers/WeaviateDriver.php`).

