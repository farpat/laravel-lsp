<?php

declare(strict_types=1);

namespace App\Lsp\Features\ViewVariables;

use App\Lsp\Contracts\DefinitionProvider;
use App\Lsp\Document;
use App\Lsp\Project;
use App\Lsp\Support\FileUri;
use App\Lsp\Support\PassedVariable;
use App\Lsp\Support\Position;
use App\Lsp\Support\ViewCallSites;
use App\Lsp\Support\WorkspaceIndex;

/**
 * Where a variable of a template comes from.
 *
 * The template only reads the variable, so there is no declaration to jump to. The keys
 * of the render calls are the closest thing to one.
 */
class ViewVariableDefinitionProvider implements DefinitionProvider
{
    /**
     * Create a new view variable definition provider instance.
     */
    public function __construct(
        protected Project $project,
        protected ViewCallSites $callSites,
        protected WorkspaceIndex $index,
    ) {}

    /**
     * Provide the call sites naming the variable under the cursor.
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

        $read = $this->variableAt($document->lines()[$line] ?? '', $character);

        if ($read === null) {
            return [];
        }

        $origin = [
            'start' => ['line' => $line, 'character' => $read['start']],
            'end'   => ['line' => $line, 'character' => $read['start'] + strlen($read['name']) + 1],
        ];

        $links = [];

        foreach ($this->callSites->passedTo($document) as $variable) {
            if ($variable->name !== $read['name']) {
                continue;
            }

            $link = $this->link($variable, $origin);

            if ($link !== null) {
                $links[] = $link;
            }
        }

        return $links;
    }

    /**
     * Get the variable the given line writes at that character, dollar sign included.
     *
     * @return array{name: string, start: int}|null
     */
    protected function variableAt(string $line, int $character): ?array
    {
        if (preg_match_all('/\$([A-Za-z_]\w*)/', $line, $found, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === 0) {
            return null;
        }

        foreach ($found as [$whole, $name]) {
            $start = $whole[1];

            if ($character >= $start && $character <= $start + strlen((string) $whole[0])) {
                return ['name' => (string) $name[0], 'start' => $start];
            }
        }

        return null;
    }

    /**
     * Build the link to where a call site names the variable.
     *
     * @param  array<string, array<string, int>>  $origin
     * @return array<string, mixed>|null
     */
    protected function link(PassedVariable $variable, array $origin): ?array
    {
        $content = $this->index->files()[$variable->path] ?? null;

        if ($content === null) {
            return null;
        }

        $start = Position::fromOffset($content, $variable->offset);
        $end = ['line' => $start['line'], 'character' => $start['character'] + mb_strlen($variable->name)];

        return [
            'originSelectionRange' => $origin,
            'targetUri'            => (string) FileUri::fromPath($variable->path),
            'targetRange'          => ['start' => $start, 'end' => $end],
            'targetSelectionRange' => ['start' => $start, 'end' => $end],
        ];
    }
}
