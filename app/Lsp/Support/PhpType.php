<?php

declare(strict_types=1);

namespace App\Lsp\Support;

use App\Lsp\Project;

/**
 * What a PHP expression is worth, read from the project alone.
 *
 * A literal says it itself, a typed parameter says it in the file handing it over, and a
 * relation says it through the model. Anything else is left unanswered rather than
 * guessed: a wrong type offers members that do not exist.
 */
class PhpType
{
    /**
     * Types written as they are, whatever the file around them says.
     *
     * @var array<int, string>
     */
    protected const SCALARS = ['int', 'float', 'string', 'bool', 'array', 'mixed', 'object', 'callable', 'iterable'];

    /**
     * Relations standing for one record, so reading further on them makes sense.
     *
     * @var array<int, string>
     */
    protected const TO_ONE = ['BelongsTo', 'HasOne', 'HasOneThrough', 'MorphOne', 'MorphTo'];

    /**
     * Expressions worth a type on their own.
     *
     * @var array<string, string>
     */
    protected const LITERALS = [
        '/^-?\d+$/'           => 'int',
        '/^-?\d*\.\d+$/'      => 'float',
        '/^[\'"]/'            => 'string',
        '/^(?:true|false)$/i' => 'bool',
        '/^null$/i'           => 'null',
        '/^\[/'               => 'array',
        '/^array\s*\(/'       => 'array',
    ];

    /**
     * Create a new PHP type instance.
     */
    public function __construct(
        protected Project $project,
        protected WorkspaceIndex $index,
    ) {}

    /**
     * Get the type of the given expression, as the project spells it out.
     *
     * @param  string  $path  the file the expression is written in
     * @param  int  $offset  where the expression starts in that file
     */
    public function of(string $expression, string $path, int $offset): ?string
    {
        $literal = $this->literalType($expression);

        if ($literal !== null) {
            return $literal;
        }

        // `$order` or `$order->customer`: a variable and what is read on it.
        if (preg_match('/^\$(\w+)((?:\s*\??->\s*\w+)*)$/', $expression, $chain) !== 1) {
            return null;
        }

        $source = $this->index->files()[$path] ?? null;

        if ($source === null) {
            return null;
        }

        return $this->followRelations($source, $chain, $offset);
    }

    /**
     * Get the name the expression ends on, which is the function to look into.
     */
    public function lastMemberOf(string $expression): ?string
    {
        if (preg_match_all('/(?:->|\?->|::)(\w+)\s*(?:\(|$)/', $expression, $members) === 0) {
            return null;
        }

        return end($members[1]) ?: null;
    }

    /**
     * Get the model the project knows under the given class.
     *
     * @return array<string, mixed>|null
     */
    public function model(string $type): ?array
    {
        $wanted = ltrim($type, '\\');

        $model = collect($this->project->index->models())
            ->first(fn (mixed $candidate): bool => is_array($candidate)
                && ltrim((string) ($candidate['class'] ?? ''), '\\') === $wanted);

        return is_array($model) ? $model : null;
    }

    /**
     * Get what the expression is worth on its own, without reading anything else.
     */
    protected function literalType(string $expression): ?string
    {
        if (preg_match('/^new\s+(\\\\?[\w\\\\]+)/', $expression, $instance) === 1) {
            return str_starts_with($instance[1], '\\') ? $instance[1] : '\\' . $instance[1];
        }

        foreach (self::LITERALS as $pattern => $type) {
            if (preg_match($pattern, $expression) === 1) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Follow each step of `$order->customer` through the relations the models declare.
     *
     * @param  array<int, string>  $chain
     */
    protected function followRelations(string $source, array $chain, int $offset): ?string
    {
        $type = $this->declaredTypeOf($source, $chain[1], $offset);

        if ($type === null) {
            return null;
        }

        if (preg_match_all('/\??->\s*(\w+)/', $chain[2], $steps) === 0) {
            return $type;
        }

        foreach ($steps[1] as $step) {
            $related = $this->relatedModel($type, $step);

            if ($related === null) {
                return null;
            }

            $type = $related;
        }

        return $type;
    }

    /**
     * Get the model a relation of the given type leads to, when it leads to a single one.
     */
    protected function relatedModel(string $type, string $name): ?string
    {
        $model = $this->model($type);

        $relation = collect($model['relations'] ?? [])
            ->first(fn (mixed $candidate): bool => is_array($candidate) && ($candidate['name'] ?? null) === $name);

        if (!is_array($relation) || !in_array((string) ($relation['type'] ?? ''), self::TO_ONE, true)) {
            return null;
        }

        return '\\' . ltrim((string) $relation['related'], '\\');
    }

    /**
     * Get the type the file already writes down for the variable.
     *
     * A typed parameter, a property, or a `@var` line: reading it costs one file and
     * answers without analysing anything.
     */
    protected function declaredTypeOf(string $source, string $name, int $before): ?string
    {
        $declaration = '/(?:[(,]|\bpublic\b|\bprotected\b|\bprivate\b|\breadonly\b|\bstatic\b)\s*'
            . '(?:(?:public|protected|private|readonly|static)\s+)*'
            . '(\??[\w\\\\]+)\s+\$' . preg_quote($name, '/') . '\b/';

        $annotated = '/@var\s+(\\\\?[\w\\\\]+)\s+\$' . preg_quote($name, '/') . '\b/';

        foreach ([$declaration, $annotated] as $pattern) {
            $found = null;

            if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === 0) {
                continue;
            }

            foreach ($matches as $match) {
                if ($match[0][1] > $before) {
                    break;
                }

                $found = (string) $match[1][0];
            }

            if ($found !== null) {
                return $this->qualify($source, $found);
            }
        }

        return null;
    }

    /**
     * Get the full name of a type, as the imports of the file spell it.
     *
     * A short name means whatever the file's `use` statements say it means.
     */
    protected function qualify(string $source, string $name): string
    {
        $bare = ltrim($name, '?');

        if (str_starts_with($bare, '\\')) {
            return $bare;
        }

        if (in_array(strtolower($bare), self::SCALARS, true)) {
            return strtolower($bare);
        }

        $short = explode('\\', $bare)[0];

        if (preg_match('/^\s*use\s+([\w\\\\]*\\\\' . preg_quote($short, '/') . ')\s*;/m', $source, $imported) === 1) {
            return '\\' . $imported[1] . substr($bare, strlen($short));
        }

        if (preg_match('/^\s*namespace\s+([\w\\\\]+)\s*;/m', $source, $namespace) === 1) {
            return '\\' . $namespace[1] . '\\' . $bare;
        }

        return '\\' . $bare;
    }
}
