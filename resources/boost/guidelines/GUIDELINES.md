# Laravel Vector Store Guidelines

## Scope
- This package is a vector-store abstraction for raw float vectors.
- Do not add embedding generation logic.
- Preserve the Laravel manager and facade pattern for default and named stores.

## Non-Negotiable Rules
- Keep `declare(strict_types=1);` in all source and test files.
- Preserve method signatures defined by `VectorStoreContract`.
- Do not introduce breaking changes to existing public APIs without explicit versioning intent.
- Keep value objects immutable and readonly.

## Architecture Rules
- Keep query condition normalization in `VectorQueryBuilder`.
- Keep backend filter translation only in compiler classes.
- Keep backend score normalization in each driver before returning `VectorResult`.
- Keep command-level compatibility behavior stable unless explicitly redesigned.

## Driver Rules
- Implement the full `VectorStoreContract` surface.
- Log each public operation using `Log::debug('VectorStore[{driver}]: {action}', [...])`.
- Keep `upsertBatch()` payload keys provider-specific.
- Enforce `minScore` at backend level when supported, otherwise post-filter client-side.

## HTTP Driver Rules
- Use `Http::acceptJson()->asJson()->retry(3, 100, throw: false)`.
- Include auth headers only when configured.
- Keep control-plane and data-plane endpoints distinct when required by provider.

## Filter Compiler Rules
- Implement `FilterCompilerContract`.
- Support all operators:
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
- Compile from normalized conditions only (`field`, `op`, and optional `value`).

## Commands And Compatibility
- Keep `FlushCommand` compatibility behavior by checking `method_exists($store, 'flush')`.
- Do not force optional operations into required contracts unless design explicitly changes.

## Configuration Rules
- Add driver config in `config/vector-store.php`.
- Use env-driven values and sensible localhost defaults.
- Keep config naming consistent with existing drivers.

## Documentation Rules
- Keep examples aligned with real APIs in this package.
- Use package namespaces exactly as implemented (`Frolax\VectorStore\...`).
- Keep examples copy-paste safe and minimal.
- Prefer concrete examples over abstract pseudocode.

## Testing Rules
- Use `Http::fake()` and `Http::assertSent()` for HTTP driver tests.
- Mock `VectorStoreContract` for query-builder delegation tests.
- Use `VectorStore::fake()` for facade-level assertions.
- Keep architecture checks clean: no `dd`, `dump`, or `ray`.
- Add or update tests with every behavior change.
- Verify existing tests still pass before finalizing.

## Workflow
- Install dependencies with `composer install`.
- Run full tests with `composer test`.
- Run focused tests while iterating with `vendor/bin/pest` and a targeted path.
- Format with `composer format`.

## Common Anti-Patterns
- Mixing compiler concerns into query builder or drivers.
- Returning backend-native distances without score normalization.
- Building ad-hoc filter formats outside `VectorQueryBuilder`.
- Replacing backend-specific payload schemas with a forced generic schema.
- Writing docs that reference methods or namespaces that do not exist.
