<p align="center">
    <h1 align="center">Laravel Redis Model Cache</h1>
    <p align="center">Optimized, high-performance Redis model and hash-set caching service for Laravel Eloquent.</p>
</p>

---

## 🌟 Overview

The **Laravel Redis Model Cache** package seamlessly integrates a Redis caching layer natively tailored for your Laravel 12 application. This is not your typical `Cache::remember()` wrapper. Instead, it provides a highly optimized, index-aware caching structure for Eloquent models built on top of Redis Hash Sets and Sorted Sets, resulting in lightning-fast lookups without hitting the database for relational operations.

### Key Features:
- 🚀 **High Performance:** Uses Redis pipelining and native hashes (`HSET`, `HGETALL`) for bulk serialization and deserialization.
- 🔍 **Advanced Indexing:** Build and query dynamic custom indices, regular indices (`SADD`), and sorted score sets (`ZADD`).
- 🛠️ **Seamless Integration:** Zero-configuration dependency injection via `RedisConnectionResolver`.
- 🌐 **Pluggable Match Strategy:** Flexible string matching interface. Customize text normalizations natively (e.g. Arabic/Farsi translations).
- 🧑‍💻 **Dev-Friendly Console:** Built-in Artisan commands to monitor cache memory, keys, and TTL thresholds.

---

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
- Deletion scan strategies (`keys` vs `scan`).

---

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

---

## 🏗 Pluggable String Matching (ModelMatchStrategy)

By default, the package relies on `DefaultModelMatchStrategy` which uses simple `strtolower()` logic for finding records within memory (`remember()`). 

You can define and bind your own complex normalization rules in your app's `AppServiceProvider`:

```php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Sm_mE\RedisModelCache\Contracts\ModelMatchStrategy;

class AppServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->bind(ModelMatchStrategy::class, FarsiModelMatchStrategy::class);
    }
}
```

---

## 📊 Artisan Cache Monitor

Manage and monitor your application's Redis footprint directly from the CLI:

```bash
# View cache usage statistics and memory layout
php artisan redis:monitor-cache info

# Scan specific keys by pattern (supports --detailed)
php artisan redis:monitor-cache keys --pattern="users:hash:*"

# Detect orphaned keys missing TTL
php artisan redis:monitor-cache ttl

# Safely flush specific cache buckets
php artisan redis:monitor-cache clear --pattern="users:*"
```

---

## 📜 License

The **Laravel Redis Model Cache** is open-sourced software licensed under the [MIT license](LICENSE).
