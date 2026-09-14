<?php

declare(strict_types=1);

namespace App\Lsp\Features\Eloquent;

/**
 * The model a chain of calls ends up acting on.
 *
 * The parser resolves the class of the receiver, `Customer` for
 * `$customer->accountDeletions()->create([...])`, and records the calls made on the way.
 * A call named like a relation moves to the related model; any other call stays on the
 * current one, which is what a query builder or a scope hands back.
 */
class QueriedModel
{
    /**
     * Create a new queried model instance.
     *
     * @param  array<string, array<string, mixed>>  $models
     */
    public function __construct(
        protected array $models,
    ) {}

    /**
     * Get the model reached by calling the given chain on the given class.
     *
     * @param  array<int, string>  $chain
     * @return array<string, mixed>|null
     */
    public function of(?string $className, array $chain): ?array
    {
        $model = $this->ofClass($className);

        foreach ($chain as $method) {
            if ($model === null) {
                return null;
            }

            $relation = collect($model['relations'] ?? [])
                ->first(fn (array $relation): bool => ($relation['name'] ?? null) === $method);

            if ($relation !== null) {
                $model = $this->models[(string) ($relation['related'] ?? '')] ?? null;
            }
        }

        return $model;
    }

    /**
     * Get the model of the given class: the model itself, or the one its builder queries.
     *
     * @return array<string, mixed>|null
     */
    public function ofClass(?string $className): ?array
    {
        if ($className === null) {
            return null;
        }

        return $this->models[$className]
            ?? collect($this->models)->first(fn (array $model): bool => ($model['builder'] ?? null) === $className);
    }
}
