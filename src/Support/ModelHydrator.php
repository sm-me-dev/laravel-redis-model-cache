<?php

declare(strict_types=1);

namespace SMDev\RedisModelCache\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Handles hydration of Eloquent models from Redis hash payloads.
 *
 * Reconstructs models from serialized data, including nested relations.
 * Batches HMGET operations to minimize round trips while respecting
 * configured batch size limits.
 */
final class ModelHydrator
{
    /**
     * @param  class-string<Model>  $modelClass
     * @param  \Closure(string): array<string, mixed>  $deserializer
     */
    public function __construct(
        private readonly string $modelClass,
        private readonly Configuration $configuration,
        private readonly \Closure $deserializer,
        private readonly mixed $redis,
        private readonly string $hashKey,
    ) {}

    /**
     * Hydrate multiple models from their IDs.
     *
     * @param  array<int, int|string>  $ids
     * @return Collection<int, Model>
     */
    public function hydrateIds(array $ids, bool $hydrate = true): Collection
    {
        if ($ids === []) {
            /** @var Collection<int, Model> $empty */
            $empty = collect();

            return $empty;
        }

        if (! $hydrate) {
            /** @var Collection<int, Model> $notHydrated */
            $notHydrated = collect($ids);

            return $notHydrated;
        }

        $maxBatch = max(1, $this->configuration->hydrateBatchSize);

        /** @var array<int, string|false> $results */
        $results = [];

        if (count($ids) <= $maxBatch) {
            /** @var array<int|string, string|false> $raw */
            $raw = $this->redis->hmget($this->hashKey, $ids);
            foreach ($ids as $id) {
                $results[] = $raw[$id] ?? false;
            }
        } else {
            foreach (array_chunk($ids, $maxBatch) as $chunk) {
                /** @var array<int|string, string|false> $raw */
                $raw = $this->redis->hmget($this->hashKey, $chunk);
                foreach ($chunk as $id) {
                    $results[] = $raw[$id] ?? false;
                }
            }
        }

        /** @var Collection<int, Model> $models */
        $models = collect($results)
            ->filter()
            ->map(function (mixed $payload): ?Model {
                if (! is_string($payload)) {
                    return null;
                }
                try {
                    /** @var array{attributes: array<string, mixed>, relations: array<string, mixed>} $data */
                    $data = ($this->deserializer)($payload);

                    return $this->hydrateModelFromPayload($data);
                } catch (\JsonException $e) {
                    return null;
                }
            })
            ->filter()
            ->values();

        return $models;
    }

    /**
     * Reconstructs a Model from stored payload including eager-loaded relations.
     *
     * @param  array<string, mixed>  $payload
     */
    public function hydrateModelFromPayload(array $payload): Model
    {
        if (! isset($payload['attributes']) || ! is_array($payload['attributes'])) {
            return (new $this->modelClass)->newFromBuilder($payload);
        }

        /** @var array<string, mixed> $attributes */
        $attributes = $payload['attributes'];
        $model = (new $this->modelClass)->newFromBuilder($attributes);

        if (isset($payload['relations']) && is_array($payload['relations']) && $payload['relations'] !== []) {
            /** @var array<string, mixed> $relations */
            $relations = $payload['relations'];
            $this->restoreRelations($model, $relations);
        }

        return $model;
    }

    /**
     * Restore relations onto a hydrated model.
     *
     * @param  array<string, mixed>  $relations
     */
    public function restoreRelations(Model $model, array $relations): void
    {
        foreach ($relations as $name => $relationData) {
            if ($relationData === null) {
                $model->setRelation($name, null);

                continue;
            }

            if (array_is_list($relationData)) {
                // Collection relation (HasMany, MorphMany, BelongsToMany) — including empty collections
                $collection = collect($relationData)->map(function (mixed $item): Model {
                    /** @var array{class: string, attributes: array<string, mixed>, relations: array<string, mixed>} $item */
                    return $this->hydrateRelatedModel($item);
                });
                $model->setRelation($name, $collection);

            } else {
                // Single model relation (BelongsTo, HasOne, MorphOne, MorphTo)
                /** @var array{class: string, attributes: array<string, mixed>, relations: array<string, mixed>} $relationData */
                $model->setRelation($name, $this->hydrateRelatedModel($relationData));
            }
        }
    }

    /**
     * Hydrate a related model from its serialized data.
     *
     * @param  array{class: string, attributes: array<string, mixed>, relations: array<string, mixed>}  $data
     */
    public function hydrateRelatedModel(array $data): Model
    {
        if (! isset($data['class'])) {
            return $this->hydrateModelFromPayload($data);
        }

        /** @var class-string<Model> $class */
        $class = $data['class'];
        $model = new $class;
        $model->setRawAttributes($data['attributes'], true);

        if (! empty($data['relations'])) {
            $this->restoreRelations($model, $data['relations']);
        }

        return $model;
    }
}
