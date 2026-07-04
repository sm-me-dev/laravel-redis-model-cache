# Query API

## Basic Queries

### where()

Filter by indexed fields. Uses Redis `SINTER` for intersections:

```php
$results = $cacheService->where(['role_id' => 1, 'status' => 'active']);
```

### whereIn() (OR logic)

Multiple values for the same field via `SUNION`:

```php
$admins = $cacheService->whereIn('role_id', [1, 2, 3]);
```

### whereBetween() (Range Queries)

Sorted-field range queries via `ZRANGEBYSCORE`:

```php
$recentPosts = $cacheService->whereBetween(
    'created_at',
    now()->subDays(7)->timestamp,
    now()->timestamp
);
```

Requires the field to be in `$sorted`.

### orWhere() (Cross-field OR)

Merge conditions from different fields:

```php
$baseIds = $cacheService->where(['role_id' => 1])->pluck('id')->toArray();
$users = $cacheService->orWhere(['status' => 'active'], $baseIds);
```

## Partial Hydration (pluck)

Fetch only specific attributes as lightweight arrays (60-80% less memory):

```php
$users = $cacheService->pluck(
    ['id', 'email', 'name'],
    ['status' => 'active']
);
// Collection of arrays, not Model instances
```

## Sorted Sets

### sorted()

```php
$latest = $cacheService->sorted('created_at', 0, 9); // top 10
```

### paginateSorted()

```php
$page2 = $cacheService->paginateSorted('created_at', page: 2, perPage: 10);
```

## Custom Indexes

Pre-defined compound filters:

```php
$config = [
    'custom_indexes' => ['active_admins' => ['role_id' => 1, 'status' => 'active']],
];

$admins = $cacheService->custom('active_admins');
$admins = $cacheService->rememberCustom(
    'active_admins',
    fn() => User::where('role_id', 1)->where('status', 'active')->get(),
    sortBy: 'created_at'  // optional
);
```

## Query Method Comparison

| Method | Best For | Avoid When |
|--------|----------|------------|
| `where()` | Single value per field | Multiple values (use `whereIn`) |
| `whereIn()` | Multiple values, same field | Different fields (use `orWhere`) |
| `whereBetween()` | Date/numeric ranges | Field not in `$sorted` |
| `orWhere()` | Different fields OR logic | Same field (use `whereIn`) |
| `pluck()` | API responses, exports | Need model methods/relations |
| `sorted()` | Latest/greatest lists | Non-sorted fields |
