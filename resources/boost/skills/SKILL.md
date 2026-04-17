---
name: Laravel Vector Store Driver Expert
description: Implement and use frolaxhq/laravel-vector-store correctly, including driver extensions, filter compilers, query behavior, and testing conventions.
compatible_agents:
  - GitHub Copilot
  - Claude Code
  - Cursor
tags:
  - laravel
  - php
  - vector-store
  - pgvector
  - qdrant
  - pinecone
  - weaviate
  - chroma
  - milvus
---

# Laravel Vector Store Driver Expert

## Context
You are working inside a Laravel package that abstracts multiple vector backends behind one contract.

This package does not create embeddings. It only stores and queries raw float vectors.

System shape:
- Application code uses the `VectorStore` facade.
- `VectorStoreManager` resolves default and named stores.
- Driver classes implement provider-specific APIs and SQL.
- `VectorQueryBuilder` stores normalized query intent.
- Compiler classes convert normalized conditions to backend-native filters.

Boundaries that must be preserved:
- Keep condition normalization in `VectorQueryBuilder`.
- Keep backend translation in compiler classes.
- Keep score normalization in driver classes before returning `VectorResult`.

## Rules
- Use `declare(strict_types=1);` in source and tests.
- Keep all driver method signatures aligned with `VectorStoreContract`.
- Preserve Laravel manager pattern behavior: default store and named stores.
- Add debug logs for public driver operations in the format `VectorStore[{driver}]: {action}`.
- For HTTP drivers, use `Http::acceptJson()->asJson()->retry(3, 100, throw: false)`.
- Add auth headers only when credentials are configured.
- Keep `upsertBatch()` mapping provider-native and explicit.
- Enforce `minScore` server-side when backend supports it; otherwise post-filter mapped results client-side.
- Preserve optional flush compatibility using `method_exists($store, 'flush')`.

## Query And Filter Conventions
- Build conditions in normalized array form with keys `field`, `op`, and optional `value`.
- Supported operators in every compiler:
  - `=`
  - `!=`
  - `>`
  - `>=`
  - `<`
  - `<=`
  - `in`
  - `not_in`
  - `between`
  - `null`
  - `not_null`
- Never bypass compilers with ad-hoc filter payloads in drivers.

## Driver Extension Workflow
1. Add a driver implementing `VectorStoreContract`.
2. Add a matching compiler implementing `FilterCompilerContract`.
3. Implement all operators in the compiler.
4. Register `create{Name}Driver()` in `VectorStoreManager`.
5. Add config keys in `config/vector-store.php` using env-driven values.
6. Add tests that lock outbound request URL, method, headers, and payload shape.

## Testing Rules
- HTTP drivers: use `Http::fake()` and `Http::assertSent()`.
- Query builder: mock `VectorStoreContract` and verify delegation.
- Facade tests: use `VectorStore::fake()` and built-in assertions.
- Architecture safety: do not introduce `dd`, `dump`, or `ray`.
- Run `composer test` for final verification.

## Anti-Patterns
- Adding embedding generation logic to this package.
- Compiling filters directly inside drivers.
- Returning backend-native distances as-is without normalization.
- Forcing one shared payload schema across all backends.
- Ignoring HTTP retry and conditional auth header conventions.

## Task Intake Checklist
Before implementing any change, confirm:
- Which backend(s) are impacted.
- Whether behavior is contract-level, compiler-level, or driver-level.
- Whether score semantics need normalization changes.
- Which tests must be added or updated.
- Whether public API compatibility is affected.

## Examples

### Application Usage (Facade + Query Builder)
```php
use Frolax\VectorStore\Facades\VectorStore;

$results = VectorStore::store('qdrant')
    ->query($embedding)
    ->collection('docs')
    ->where('tenant_id', '=', 'acme')
    ->where('published', '=', true)
    ->whereBetween('created_at_ts', [1704067200, 1735689599])
    ->minScore(0.75)
    ->topK(10)
    ->get();
```

### Add a New Manager Driver Resolver
```php
use Frolax\VectorStore\Compilers\AcmeFilterCompiler;
use Frolax\VectorStore\Contracts\VectorStoreContract;
use Frolax\VectorStore\Drivers\AcmeDriver;

protected function createAcmeDriver(array $config): VectorStoreContract
{
    return new AcmeDriver(
        config: $config,
        compiler: new AcmeFilterCompiler()
    );
}
```

### Post-Filter minScore When Backend Cannot Enforce It
```php
use Frolax\VectorStore\ValueObjects\VectorResult;

$results = collect($mappedResults)
    ->filter(fn (VectorResult $result): bool => $result->score >= $minScore)
    ->values()
    ->all();
```

### HTTP Driver Client Pattern
```php
use Illuminate\Support\Facades\Http;

$request = Http::acceptJson()
  ->asJson()
  ->retry(3, 100, throw: false);

if ($apiKey !== null && $apiKey !== '') {
  $request = $request->withToken($apiKey);
}
```

## References
- Laravel manager pattern and facades: https://laravel.com/docs
- pgvector extension: https://github.com/pgvector/pgvector
- Qdrant filtering: https://qdrant.tech/documentation
- Pinecone filtering: https://docs.pinecone.io
- Weaviate filters: https://weaviate.io/developers/weaviate
- Milvus filtering: https://milvus.io/docs
- Chroma metadata filters: https://docs.trychroma.com
