# v3.0.0 Roadmap

This roadmap tracks the v3.0.0 package improvements.

## Completed phases

1. Namespace migration to `SMDev\RedisModelCache` and Packagist slug cleanup.
2. Removal of the `all()` interface contract, unsafe blind lock release, and
   unconditional global helper loading.
3. Extraction of model hydration, serialization, and pipeline execution
   responsibilities from `RedisModelService`.
4. In-memory `RedisModelCacheFake` for tests without Redis.
5. PHP 8 attributes for cache indexes, sorted fields, TTL, and eager-loaded
   relations.
6. Native sorted-range limit/offset support and `paginateWhereBetween()`.
7. Distributed SWR locks with value-based CAS release and stampede protection
   enabled by default.
8. Optional first-party Laravel Pulse metrics recorder and dashboard card.
9. Release preparation: configuration version 3.0.0, documentation, CI, and
   static-analysis cleanup.

## Follow-up work

- Laravel Scout integration is planned for v3.1.0.
- Continue reducing the orchestration surface of `RedisModelService` while
  preserving its public API.
