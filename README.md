<p align="center">
    <h1 align="center">Laravel Redis Model Cache</h1>
    <p align="center">Optimized, high-performance Redis model and hash-set caching service for Laravel Eloquent.</p>
</p>

<p align="center">
    <a href="https://packagist.org/packages/sm-me/laravel-redis-model-cache"><img src="https://img.shields.io/packagist/v/sm-me/laravel-redis-model-cache" alt="Latest Version"></a>
    <a href="https://packagist.org/packages/sm-me/laravel-redis-model-cache"><img src="https://img.shields.io/packagist/php-v/sm-me/laravel-redis-model-cache" alt="PHP Version"></a>
    <a href="https://packagist.org/packages/sm-me/laravel-redis-model-cache"><img src="https://img.shields.io/packagist/l/sm-me/laravel-redis-model-cache" alt="License"></a>
</p>

---

## 🌟 Overview

**Version 1.1.0** | Requires **PHP ^8.4** and **Laravel ^12.0**

The **Laravel Redis Model Cache** package seamlessly integrates a Redis caching layer natively tailored for your Laravel 12 application. This is not your typical `Cache::remember()` wrapper. Instead, it provides a highly optimized, index-aware caching structure for Eloquent models built on top of Redis Hash Sets and Sorted Sets, resulting in lightning-fast lookups without hitting the database for relational operations.

### Key Features:
- 🚀 **High Performance:** Uses Redis pipelining and native hashes (`HSET`, `HGETALL`) for bulk serialization and deserialization.
- 🔍 **Advanced Indexing:** Build and query dynamic custom indexes, regular indexes (`SADD`), and sorted score sets (`ZADD`).
- 🛠️ **Seamless Integration:** Zero-configuration dependency injection via `RedisConnectionResolver`.
- 🌐 **Pluggable Match Strategy:** Flexible string matching interface. Customize text normalizations natively (e.g. Arabic/Farsi translations).
- 🧑‍💻 **Dev-Friendly Console:** Built-in Artisan commands to monitor cache memory, keys, and TTL thresholds.
- 🔒 **Memory Safety:** Eliminates OOM risks by requiring indexed queries only.
- ⚡ **Atomic Writes:** All writes are fully transactional with pipeline batching.
- 🌐 **Eager-Relation Support:** Caches eager-loaded relationships to eliminate N+1 query problems.

## Memory Safety Architecture

### Key Requirements

**1. Index-Driven Queries**

All `where()` queries must use indexed fields. Unindexed queries are blocked to prevent full hash scans:

```php
// ✅ Works - field is indexed
$results = $cacheService->where(['role_id' => 1]);

// ❌ Throws InvalidArgumentException - field not indexed
$results = $cacheService->where(['email' => 'test@example.com']); // Error!
```

**2. No Global Unindexed Access**

The `all()` method now throws `BadMethodCallException` to prevent full hash scans:

```php
// ❌ Throws BadMethodCallException
$allModels = $cacheService->all(); // No longer returns all cached records

// ✅ Works - indexed query only
$activeUsers = $cacheService->where(['active' => true]);
```

**3. remember() Restriction**

The `remember()` method only performs index lookups:

```php
// ✅ Works - indexed field
$user = $cacheService->remember(fn() => [], findBy: 'id', findValue: 42);

// ❌ Throws InvalidArgumentException - field not indexed
$user = $cacheService->remember(fn() => [], findBy: 'email', findValue: 'test@example.com');
```

### Migration Guide

#### From: Full Hash Scans

```php
// Before: Loaded ALL records into memory, then filtered in PHP
$records = $cacheService->all()->filter(fn($r) => $r->name === 'Alice');
```

#### To: Indexed Queries

```php
// After: Requires indexed field, uses SINTER for fast intersection
$records = $cacheService->where(['name' => 'Alice']);
```

#### From: Any Field Lookup

```php
// Before: Any field could be used for lookups
$records = $cacheService->remember(fn() => [], findBy: 'email', findValue: 'test@example.com');
```

#### To: Index-Required Lookups

```php
// After: Only indexed fields allowed
$records = $cacheService->remember(fn() => [], findBy: 'email', findValue: 'test@example.com');
// Throws: Field 'email' is not indexed. Define it in \$indexes constructor arg.
```

#### From: Global Cache Access

```php
// Before: Could retrieve all models from cache
$allModels = $cacheService->all();
```

#### To: Query-Specific Access

```php
// After: Only specific indexed queries work
$activeUsers = $cacheService->where(['active' => true]);
```

## 📌 Breaking Changes Notice

This version introduces **critical memory-safety changes** that will break existing code. Please update your applications:

### 1. `all()` Method - No Longer Available

**Before:**
```php
$allModels = $cacheService->all(); // Returns all cached records
```

**After:**
```php
$allModels = $cacheService->all(); // Throws BadMethodCallException
```

**Solution:** Use indexed queries:
```php
$activeUsers = $cacheService->where(['active' => true]);
```

### 2. `where()` Method - Requires Indexed Fields

**Before:**
```php
// Any field could be used - performed full hash scans
$records = $cacheService->where(['email' => 'test@example.com']);
```

**After:**
```php
// Error: Field 'email' is not indexed
$records = $cacheService
    ->where(['email' => 'test@example.com'])
    ->catch(InvalidArgumentException, function ($e) {
        // Fallback to database query or use indexed field instead
    });
```

**Solution:** Add fields to the `$indexes` array in your service constructor:
```php
$cacheService = app(RedisModelService::class, [
    'model_class' => User::class,
    'indexes' => ['email', 'role_id'],  // Add 'email' here
    'sorted' => ['created_at'],
    'ttl' => 3600,
]);
```

### 3. `remember()` Method - Index-Required Lookups

**Before:**
```php
// Any field could be used
$user = $cacheService->remember(fn() => [], findBy: 'email', findValue: 'test@example.com');
```

**/`After:**
```php
$user = $cacheService->remember(fn() => [], findBy: 'email', findValue: 'test@example.com');
// Throws: Field 'email' is not indexed. Define it in \$indexes.
```

**Solution:** Use indexed fields for lookups:
```php
$user = $cacheService->remember(fn() => [], findBy: 'id', findValue: 42);
```

### Migration Tools

The package provides these utilities to help with migration:

1. **Memory Usage Statistics:** Monitor hash set sizes to identify safe replacements for `all()`
2. **Index Auditor:** Script to analyze existing code and identify fields used in `where()` and `remember()` that need to be indexed
3. **Backward Compatibility Wrapper:** Optional compatibility layer for gradual migration

## 📦 Installation

This package is a standalone Packagist-ready module. Install it into your Laravel application using Composer:

```bash
composer require sm-me/laravel-redis-model-cache
```

The package supports **Laravel Auto-Discovery**, so the `RedisModelCacheServiceProvider` will be registered automatically.

## ⚙️ Configuration

Publish the configuration file using Artisan:

```bash
php artisan vendor:publish --tag=redis-model-cache-config
```

This will create a `config/redis-model-cache.php` file where you can define:
- Your target Redis connection (defined in `config/database.php`).
- Global Time-to-Live (TTL) behaviors.
- Deletion scan strategies (`scan` only - no `keys` fallback).

## 🛠️ Usage

### Basic Dependency Injection

You can inject the core `RedisModelService` directly or construct it on the fly:

```php
use Sm_mE\RedisModelCache\RedisModelService;
use App\Models\User;

$cacheService = app(RedisModelService::class, [
    'model_class' => User::class,
    'indexes' => ['role_id'],
    'sorted' => ['created_at'],
    'ttl' => 3600 // 1 hour TTL
]);

// Fetch and Cache your query
$users = $cacheService->rememberAll(function () {
    return User::where('active', true)->get();
});
```

### Advanced Querying (Where)

When data exists in the hash set, the package simulates Eloquent filtering entirely within Redis memory:

```php
// Queries Redis memory directly if cache is warm
$activeAdmins = $cacheService->where(['role_id' => 1, 'active' => true]);
```

### Sorted Sets Pagination

Need to paginate cached data natively via Redis ZSets? Easy:

```php
// Retrieve page 1 (first 10 records) sorted by 'created_at' index.
$latestUsers = $cacheService->paginateSorted('created_at', page: 1, perPage: 10);
```

### Global Helper Functions

For quick operations anywhere in your app, use the built-in global helper methods:

```php
// Resolves RedisHelperService for generic hash sets
$data = redisHelper(ttl: 300)->rememberSet('app:settings', 'theme', fn () => 'dark');

// Resolves RedisModelService for Eloquent operations
$service = redisModelHelper(User::class, indexes: ['department_id']);
```

## 📜 License

The **Laravel Redis Model Cache** is open-sourced software licensed under the [MIT license](LICENSE). This package provides a memory-safe Redis caching architecture optimized for Laravel Eloquent models, with strict indexing requirements to prevent OOM risks and ensure atomic writes.