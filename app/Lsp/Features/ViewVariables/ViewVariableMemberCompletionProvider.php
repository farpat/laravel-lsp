<?php

declare(strict_types=1);

namespace App\Lsp\Features\ViewVariables;

use App\Lsp\Contracts\CompletionProvider;
use App\Lsp\Document;
use App\Lsp\Project;
use App\Lsp\Support\PassedVariable;
use App\Lsp\Support\PhpMembers;
use App\Lsp\Support\PhpType;
use App\Lsp\Support\ViewCallSites;

/**
 * What can be read on a variable the template renders.
 *
 * Nothing declares the variable in the template, so an editor offers nothing once the
 * arrow is typed. The call site says what the variable holds, and the class it holds
 * says what can be read on it.
 */
class ViewVariableMemberCompletionProvider implements CompletionProvider
{
    /**
     * Create a new view variable member completion provider instance.
     */
    public function __construct(
        protected Project $project,
        protected ViewCallSites $callSites,
        protected PhpType $types,
        protected PhpMembers $members,
    ) {}

    /**
     * Provide the members of the variable being read.
     *
     * @param  array<string, mixed>  $position
     * @return array<int, array<string, mixed>>
     */
    public function get(Document $document, array $position): array
    {
        if (!$this->project->boolean('viewVariableCompletion', true)) {
            return [];
        }

        $line = $position['line'] ?? null;
        $character = $position['character'] ?? null;

        if (!is_int($line) || !is_int($character) || !str_ends_with($document->uri, '.blade.php')) {
            return [];
        }

        $typed = substr($document->lines()[$line] ?? '', 0, $character);

        if (preg_match('/\$([A-Za-z_]\w*)\s*\??->\s*(\w*)$/', $typed, $reading) !== 1) {
            return [];
        }

        $variable = $this->spelledOut($this->callSites->passedTo($document), $reading[1]);

        if ($variable === null) {
            return [];
        }

        return array_map(
            fn (array $member): array => [
                'label'  => $member['name'],
                'kind'   => $member['kind'],
                'detail' => $member['detail'],
            ],
            $this->membersOf($variable),
        );
    }

    /**
     * Get what can be read on the given variable.
     *
     * A model is asked of the framework, since its columns live in the database; anything
     * else is read from the class the type points at.
     *
     * @return array<int, array{name: string, kind: int, detail: string}>
     */
    protected function membersOf(PassedVariable $variable): array
    {
        $type = $this->types->of($variable->value, $variable->path, $variable->valueOffset);

        if ($type === null) {
            return [];
        }

        $model = $this->types->model($type);

        if ($model !== null) {
            return $this->modelMembers($model);
        }

        $declaration = $this->members->findDeclaration($type);

        return $declaration === null ? [] : $this->members->publicMembersOf($declaration['source']);
    }

    /**
     * Get what an Eloquent model answers to, which its own file never says.
     *
     * The columns come from the database and the relations from methods a template never
     * calls by name.
     *
     * @param  array<string, mixed>  $model
     * @return array<int, array{name: string, kind: int, detail: string}>
     */
    protected function modelMembers(array $model): array
    {
        $attributes = collect($model['attributes'] ?? [])
            ->filter(fn (mixed $attribute): bool => is_array($attribute) && is_string($attribute['name'] ?? null))
            ->map(fn (array $attribute): array => [
                'name'   => (string) $attribute['name'],
                'kind'   => 10,
                'detail' => (string) ($attribute['cast'] ?? $attribute['type'] ?? ''),
            ]);

        $relations = collect($model['relations'] ?? [])
            ->filter(fn (mixed $relation): bool => is_array($relation) && is_string($relation['name'] ?? null))
            ->map(fn (array $relation): array => [
                'name'   => (string) $relation['name'],
                'kind'   => 18,
                'detail' => trim(((string) ($relation['type'] ?? '')) . ' ' . ((string) ($relation['related'] ?? ''))),
            ]);

        return $attributes->merge($relations)->values()->all();
    }

    /**
     * Get the first call site spelling out what the variable holds.
     *
     * @param  array<int, PassedVariable>  $passed
     */
    protected function spelledOut(array $passed, string $name): ?PassedVariable
    {
        foreach ($passed as $variable) {
            if ($variable->name === $name && $variable->isSpelledOut()) {
                return $variable;
            }
        }

        return null;
    }
}
