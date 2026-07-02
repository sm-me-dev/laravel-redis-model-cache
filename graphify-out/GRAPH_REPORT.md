# Graph Report - laravel-redis-model-cache  (2026-07-02)

## Corpus Check
- 16 files · ~3,541 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 160 nodes · 271 edges · 15 communities (13 shown, 2 thin omitted)
- Extraction: 100% EXTRACTED · 0% INFERRED · 0% AMBIGUOUS
- Token cost: 0 input · 0 output

## Graph Freshness
- Built from commit: `80e052be`
- Run `git rev-parse HEAD` and compare to check if the graph is stale.
- Run `graphify update .` after code changes (no API cost).

## Community Hubs (Navigation)
- [[_COMMUNITY_Community 0|Community 0]]
- [[_COMMUNITY_Community 1|Community 1]]
- [[_COMMUNITY_Community 2|Community 2]]
- [[_COMMUNITY_Community 3|Community 3]]
- [[_COMMUNITY_Community 4|Community 4]]
- [[_COMMUNITY_Community 5|Community 5]]
- [[_COMMUNITY_Community 6|Community 6]]
- [[_COMMUNITY_Community 7|Community 7]]
- [[_COMMUNITY_Community 8|Community 8]]

## God Nodes (most connected - your core abstractions)
1. `RedisModelService` - 39 edges
2. `Collection` - 13 edges
3. `MonitorCacheCommand` - 12 edges
4. `Model` - 9 edges
5. `RedisBaseService` - 8 edges
6. `require` - 6 edges
7. `Collection` - 6 edges
8. `RedisHelperService` - 6 edges
9. `ModelMatchStrategy` - 6 edges
10. `DefaultConnectionResolver` - 5 edges

## Surprising Connections (you probably didn't know these)
- `RedisHelperService` --inherits--> `RedisBaseService`  [EXTRACTED]
  src/RedisHelperService.php → src/RedisBaseService.php
- `RedisModelService` --inherits--> `RedisBaseService`  [EXTRACTED]
  src/RedisModelService.php → src/RedisBaseService.php
- `RedisModelService` --implements--> `ModelCacheService`  [EXTRACTED]
  src/RedisModelService.php → src/Support/helpers.php
- `RedisHelperService` --implements--> `HashCacheService`  [EXTRACTED]
  src/RedisHelperService.php → src/Support/helpers.php
- `DefaultModelMatchStrategy` --implements--> `ModelMatchStrategy`  [EXTRACTED]
  src/Support/DefaultModelMatchStrategy.php → src/RedisModelService.php

## Import Cycles
- None detected.

## Communities (15 total, 2 thin omitted)

### Community 0 - "Community 0"
Cohesion: 0.06
Nodes (30): pestphp/pest-plugin, authors, autoload, autoload-dev, psr-4, files, psr-4, config (+22 more)

### Community 1 - "Community 1"
Cohesion: 0.13
Nodes (10): ServiceProvider, RedisConnectionResolver, RedisHelperService, RedisModelCacheServiceProvider, ModelMatchStrategy, HashCacheService, ModelCacheService, DefaultModelMatchStrategy (+2 more)

### Community 2 - "Community 2"
Cohesion: 0.14
Nodes (5): Facade, RedisModelCache, RedisConnectionResolver, RedisBaseService, DefaultConnectionResolver

### Community 3 - "Community 3"
Cohesion: 0.30
Nodes (3): Command, MonitorCacheCommand, RedisConnectionResolver

### Community 4 - "Community 4"
Cohesion: 0.24
Nodes (9): all(), remember(), rememberAll(), rememberCustom(), rememberIndex(), where(), Collection, Expression (+1 more)

### Community 5 - "Community 5"
Cohesion: 0.15
Nodes (12): Advanced Querying (Where), 📊 Artisan Cache Monitor, Basic Dependency Injection, ⚙️ Configuration, Global Helper Functions, 📦 Installation, Key Features:, 📜 License (+4 more)

### Community 7 - "Community 7"
Cohesion: 0.42
Nodes (3): Expression, Model, RedisConnectionResolver

## Knowledge Gaps
- **30 isolated node(s):** `name`, `description`, `license`, `authors`, `keywords` (+25 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **2 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `RedisModelService` connect `Community 6` to `Community 1`, `Community 2`, `Community 7`, `Community 8`, `Community 9`, `Community 13`?**
  _High betweenness centrality (0.157) - this node is a cross-community bridge._
- **Why does `RedisBaseService` connect `Community 2` to `Community 1`, `Community 6`?**
  _High betweenness centrality (0.066) - this node is a cross-community bridge._
- **Why does `ModelMatchStrategy` connect `Community 1` to `Community 6`, `Community 7`?**
  _High betweenness centrality (0.046) - this node is a cross-community bridge._
- **What connects `name`, `description`, `license` to the rest of the system?**
  _30 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `Community 0` be split into smaller, more focused modules?**
  _Cohesion score 0.06451612903225806 - nodes in this community are weakly interconnected._
- **Should `Community 1` be split into smaller, more focused modules?**
  _Cohesion score 0.13333333333333333 - nodes in this community are weakly interconnected._
- **Should `Community 2` be split into smaller, more focused modules?**
  _Cohesion score 0.13970588235294118 - nodes in this community are weakly interconnected._