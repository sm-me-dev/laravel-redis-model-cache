# Known Limitations — v3.0.0

## 1. Read throughput is PHP CPU-bound

Large result sets still spend most of their time deserializing JSON payloads
and constructing Eloquent models. Use `pluck()` for list views when full
models are unnecessary.

## 2. Distributed SWR locking

Resolved in v3.0.0. `stale_while_revalidate.distributed_lock` is enabled by
default and uses a Redis value-based lock with CAS release. Set it to `false`
to use the legacy lock behavior.

## 3. Sorted-set TTL is per set

Redis expires a sorted set as a whole, not individual members. Choose a TTL
that matches the lifespan of the complete index.

## 4. Sorted pagination

Resolved in v3.0.0. Use `whereBetween()` limit/offset or
`paginateWhereBetween()` for bounded sorted-range reads.

## 5. No Laravel Scout driver

A Laravel Scout integration is planned for v3.1.0.

## 6. `all()` is disabled

`RedisModelService::all()` intentionally throws because full hash scans are
prohibited for memory safety. Use indexed queries or `customWhere()`.
