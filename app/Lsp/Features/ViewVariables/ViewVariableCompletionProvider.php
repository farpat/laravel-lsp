<?php

declare(strict_types=1);

namespace App\Lsp\Features\ViewVariables;

use App\Lsp\Contracts\CompletionProvider;
use App\Lsp\Document;
use App\Lsp\Project;
use App\Lsp\Support\PassedVariable;
use App\Lsp\Support\PhpType;
use App\Lsp\Support\ViewCallSites;

/**
 * The variables a template renders.
 *
 * A template never declares its data, so an editor has nothing to complete from. The
 * call sites say what the controller promised to pass.
 */
class ViewVariableCompletionProvider implements CompletionProvider
{
    /**
     * Shown for a variable no call site spells out, as `compact()` never does.
     */
    protected const UNKNOWN = 'Passed to this view';

    /**
     * Create a new view variable completion provider instance.
     */
    public function __construct(
        protected Project $project,
        protected ViewCallSites $callSites,
        protected PhpType $types,
    ) {}

    /**
     * Provide the variables the given template receives.
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

        // The dollar sign is part of what the completion replaces, so a half typed
        // `$pric` does not end up as `$$pricing`.
        if (preg_match('/\$[A-Za-z_]\w*$|\$$/', $typed, $found) !== 1) {
            return [];
        }

        $range = [
            'start' => ['line' => $line, 'character' => $character - strlen($found[0])],
            'end'   => ['line' => $line, 'character' => $character],
        ];

        $passed = $this->callSites->passedTo($document);
        $items = [];

        foreach (array_unique(array_map(fn (PassedVariable $variable): string => $variable->name, $passed)) as $name) {
            $items[] = [
                'label'    => '$' . $name,
                'kind'     => 6,
                'detail'   => $this->detailOf($passed, $name),
                'textEdit' => [
                    'range'   => $range,
                    'newText' => '$' . $name,
                ],
            ];
        }

        return $items;
    }

    /**
     * Get what to show beside the name: the type of the value, when the project says it.
     *
     * @param  array<int, PassedVariable>  $passed
     */
    protected function detailOf(array $passed, string $name): string
    {
        foreach ($passed as $variable) {
            if ($variable->name !== $name || !$variable->isSpelledOut()) {
                continue;
            }

            $type = $this->types->of($variable->value, $variable->path, $variable->valueOffset);

            if ($type !== null) {
                return $this->shortName($type);
            }
        }

        return self::UNKNOWN;
    }

    /**
     * Get the last part of a class name, which is how a one-line detail reads best.
     */
    protected function shortName(string $type): string
    {
        $parts = array_filter(explode('\\', $type));

        return end($parts) ?: $type;
    }
}
