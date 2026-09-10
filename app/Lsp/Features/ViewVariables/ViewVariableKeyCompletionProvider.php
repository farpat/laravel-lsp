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
 * The keys of an array a template renders.
 *
 * `$pricing['…']` reads a key nobody declared in the template. The call site spells the
 * array out, or calls the function building it, and those keys are the only honest
 * completion for it.
 */
class ViewVariableKeyCompletionProvider implements CompletionProvider
{
    /**
     * Create a new view variable key completion provider instance.
     */
    public function __construct(
        protected Project $project,
        protected ViewCallSites $callSites,
        protected PhpType $types,
        protected PhpMembers $members,
    ) {}

    /**
     * Provide the keys of the array being read.
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

        if (preg_match('/\$([A-Za-z_]\w*)\s*\[\s*[\'"](\w*)$/', $typed, $reading) !== 1) {
            return [];
        }

        $variable = $this->spelledOut($this->callSites->passedTo($document), $reading[1]);

        if ($variable === null) {
            return [];
        }

        $literal = $this->literalFor($variable);

        if ($literal === null) {
            return [];
        }

        return array_map(
            fn (string $key): array => [
                'label' => $key,
                'kind'  => 5,
            ],
            $this->members->arrayKeysIn($literal),
        );
    }

    /**
     * Get the array the call site hands over.
     *
     * Spelled out on the spot, or handed back by the function it calls: either way the
     * keys are written down somewhere.
     */
    protected function literalFor(PassedVariable $variable): ?string
    {
        if (str_starts_with($variable->value, '[')) {
            return substr($variable->value, 1, -1);
        }

        $member = $this->types->lastMemberOf($variable->value);

        if ($member === null) {
            return null;
        }

        $function = $this->members->findFunction($member);

        return $function === null
            ? null
            : $this->members->returnedArrayIn($function['source'], $function['offset']);
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
