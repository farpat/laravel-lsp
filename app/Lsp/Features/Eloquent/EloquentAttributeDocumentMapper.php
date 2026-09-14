<?php

declare(strict_types=1);

namespace App\Lsp\Features\Eloquent;

use App\Lsp\Detection\DetectedArgument;
use App\Lsp\Detection\DetectedArguments;
use App\Lsp\Detection\Pattern;
use App\Lsp\Document;
use App\Lsp\Features\Support\DocumentMapper;
use App\Lsp\Project;
use Illuminate\Support\Collection;

class EloquentAttributeDocumentMapper extends DocumentMapper
{
    /**
     * Methods writing attributes, named by the keys of their array arguments.
     *
     * @var array<int, string>
     */
    protected const FILLING_METHODS = [
        'create', 'createOrFirst', 'fill', 'firstOrCreate', 'firstOrNew', 'forceCreate',
        'forceFill', 'make', 'update', 'updateOrCreate', 'updateQuietly',
    ];

    /**
     * Methods reading a column named by their first argument.
     *
     * @var array<int, string>
     */
    protected const COLUMN_METHODS = [
        'avg', 'decrement', 'firstWhere', 'groupBy', 'increment', 'latest', 'max', 'min',
        'oldest', 'orWhere', 'orderBy', 'orderByDesc', 'select', 'sum', 'value', 'where',
        'whereBetween', 'whereDate', 'whereIn', 'whereNot', 'whereNotIn', 'whereNotNull',
        'whereNull',
    ];

    /**
     * Methods reading a column from each of their two first arguments.
     *
     * @var array<int, string>
     */
    protected const TWO_COLUMN_METHODS = ['pluck', 'whereColumn'];

    /**
     * Create a new Eloquent attribute document mapper instance.
     */
    public function __construct(
        protected Project $project,
        protected AttributeDeclarations $declarations,
    ) {}

    /**
     * Get Eloquent attribute detection patterns.
     *
     * @return array<int, Pattern>
     */
    protected function patterns(): array
    {
        $classes = collect($this->models())
            ->flatMap(fn (array $model): array => [$model['class'] ?? null, $model['builder'] ?? null])
            ->filter(fn (mixed $class): bool => is_string($class))
            ->values()
            ->all();

        // Without a class to match, a pattern matches the calls written without one.
        if ($classes === []) {
            return [];
        }

        return [
            Pattern::method(method: self::FILLING_METHODS, class: $classes, argument: [0, 1]),
            Pattern::method(method: self::COLUMN_METHODS, class: $classes, argument: 0),
            Pattern::method(method: self::TWO_COLUMN_METHODS, class: $classes, argument: [0, 1]),
        ];
    }

    /**
     * Get matched string and array arguments from the document.
     *
     * @return Collection<int, DetectedArgument>
     */
    public function arguments(Document $document): Collection
    {
        return DetectedArguments::in($document)
            ->matching($this->patterns())
            ->stringsAndArrays();
    }

    /**
     * Convert the given argument to document links.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function toLinks(DetectedArgument $argument): array
    {
        $item = $argument->item();
        $model = (new QueriedModel($this->models()))->of($item['className'] ?? null, $item['chain'] ?? []);

        if ($model === null) {
            return [];
        }

        return collect($this->namesIn($argument))
            ->map(fn (array $name): ?array => $this->linkTo($name, $model))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Get the link from the given string to the attribute it names, if it names one.
     *
     * @param  array{value: string, range: array<string, array<string, int>>}  $name
     * @param  array<string, mixed>  $model
     * @return array<string, mixed>|null
     */
    protected function linkTo(array $name, array $model): ?array
    {
        [$owner, $attribute] = $this->attributeNamed($name['value'], $model);

        if ($owner === null || !in_array($attribute, collect($owner['attributes'] ?? [])->pluck('name')->all(), true)) {
            return null;
        }

        $declaration = $this->declarations->find($owner, $attribute);

        if ($declaration === null) {
            return null;
        }

        return $this->project->link($name['range'], $declaration['path'], $declaration['line']);
    }

    /**
     * Split a column name into the model owning it and the attribute.
     *
     * A bare name belongs to the model queried; `table.column` belongs to whichever model
     * the table is mapped to, a join reaching beyond the model queried.
     *
     * @param  array<string, mixed>  $model
     * @return array{0: array<string, mixed>|null, 1: string}
     */
    protected function attributeNamed(string $value, array $model): array
    {
        if (!str_contains($value, '.')) {
            return [$model, $value];
        }

        [$table, $column] = explode('.', $value, 2);

        $owner = collect($this->models())->first(fn (array $candidate): bool => ($candidate['table'] ?? null) === $table);

        return [$owner, $column];
    }

    /**
     * Get the strings naming an attribute in the given argument.
     *
     * @return array<int, array{value: string, range: array<string, array<string, int>>}>
     */
    protected function namesIn(DetectedArgument $argument): array
    {
        if (in_array($argument->item()['methodName'] ?? null, self::FILLING_METHODS, true)) {
            return $argument->stringKeys();
        }

        if ($argument->stringValue() !== null) {
            return $argument->literalStringValues();
        }

        return [...$argument->stringKeys(), ...$argument->listStringValues()];
    }

    /**
     * Get the models the application declares, keyed by class.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function models(): array
    {
        return $this->project->index->models();
    }
}
