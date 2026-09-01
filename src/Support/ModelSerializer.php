<?php

declare(strict_types=1);

namespace SMDev\RedisModelCache\Support;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Handles serialization of Eloquent models for Redis storage.
 *
 * Recursively serializes models with their eager-loaded relations,
 * preserving relationship structures (collections vs single models).
 * Extracts sortable scores from timestamp and numeric fields.
 */
final class ModelSerializer
{
    private readonly \Closure $serializer;

    /**
     * @param  \Closure(mixed): string  $serializer
     */
    public function __construct(\Closure $serializer)
    {
        $this->serializer = $serializer;
    }

    /**
     * Serializes a single model (attributes + nested relations).
     *
     * @return array{class: string, attributes: array<string, mixed>, relations: array<string, mixed>}
     */
    public function serializeModel(Model $model): array
    {
        return [
            'class' => get_class($model),
            'attributes' => $model->getAttributes(),
            'relations' => $this->extractRelations($model),  // Recursive
        ];
    }

    public function serializePayload(mixed $payload): string
    {
        return ($this->serializer)($payload);
    }

    /**
     * Extract eager-loaded relations from a model.
     *
     * @return array<string, mixed>
     */
    public function extractRelations(Model $model): array
    {
        /** @var array<string, mixed> $relations */
        $relations = [];

        foreach ($model->getRelations() as $name => $relation) {
            $name = (string) $name;

            if ($relation instanceof Collection) {
                // HasMany, MorphMany, BelongsToMany
                /** @var array<int, mixed> $serialized */
                $serialized = $relation->map(function (Model $related): array {
                    return $this->serializeModel($related);
                })->toArray();
                $relations[$name] = $serialized;

            } elseif ($relation instanceof Model) {
                // HasOne, BelongsTo, MorphOne, MorphTo
                $relations[$name] = $this->serializeModel($relation);

            } elseif ($relation === null) {
                // Explicitly loaded null relation (e.g., BelongsTo with no parent)
                $relations[$name] = null;
            }
            // Unloaded relations are NOT in getRelations() — correctly omitted
        }

        return $relations;
    }

    /**
     * Convert a field value into a numeric score for sorted set storage.
     */
    public function extractScore(mixed $value): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        return (float) (strtotime((string) $value) ?: 0);
    }
}
