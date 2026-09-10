<?php

declare(strict_types=1);

namespace App\Lsp\Features\Commands;

use App\Lsp\Detection\AutocompleteArgument;
use App\Lsp\Detection\DetectedArgument;
use App\Lsp\Detection\DetectedArguments;
use App\Lsp\Detection\Pattern;
use App\Lsp\Document;
use App\Lsp\Features\Support\DocumentMapper;
use App\Lsp\Support\Position;
use App\Lsp\Project;
use Illuminate\Support\Collection;

class CommandDocumentMapper extends DocumentMapper
{
    /**
     * Create a new command document mapper instance.
     */
    public function __construct(
        protected Project $project,
    ) {}

    /**
     * Get the patterns where the first argument can only be a command name.
     *
     * @return array<int, Pattern>
     */
    protected function strictPatterns(): array
    {
        return [
            Pattern::method(method: ['call', 'queue'], class: Pattern::facade('Artisan'), argument: 0),
            Pattern::method(
                method: 'command',
                class: [...Pattern::facade('Schedule'), 'Illuminate\\Console\\Scheduling\\Schedule'],
                argument: 0,
            ),
        ];
    }

    /**
     * Get command detection patterns.
     *
     * @return array<int, Pattern>
     */
    protected function patterns(): array
    {
        return [
            ...$this->strictPatterns(),
            // Scheduling reads `$schedule->command(...)`, whose receiver is usually an
            // unresolved variable. The bare call is matched and the index decides: a
            // `->command()` that names no known command is simply left alone.
            Pattern::method(method: 'command', argument: 0),
        ];
    }

    /**
     * Get matched command arguments from the document.
     *
     * @return Collection<int, DetectedArgument>
     */
    public function arguments(Document $document): Collection
    {
        return DetectedArguments::in($document)
            ->matching($this->patterns())
            ->strings()
            ->filter(fn (DetectedArgument $argument): bool => $this->shouldAccept($argument))
            ->values();
    }

    /**
     * Get matched command arguments that may be reported as unknown.
     *
     * @return Collection<int, DetectedArgument>
     */
    protected function strictArguments(Document $document): Collection
    {
        return DetectedArguments::in($document)
            ->matching($this->strictPatterns())
            ->strings()
            ->filter(fn (DetectedArgument $argument): bool => $this->shouldAccept($argument))
            ->values();
    }

    /**
     * Convert the given argument to document links.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function toLinks(DetectedArgument $argument): array
    {
        return collect($argument->stringValues())
            ->map(function (array $value): ?array {
                $command = $this->find($this->name($value['value']));

                return is_array($command) && is_string($command['path'] ?? null)
                    ? $this->project->link($value['range'], $command['path'], is_numeric($command['line'] ?? null) ? (int) $command['line'] : null)
                    : null;
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Convert the given argument to hover.
     *
     * @param  array<string, mixed>  $position
     * @return array<string, mixed>|null
     */
    protected function toHover(DetectedArgument $argument, array $position): ?array
    {
        foreach ($argument->stringValues() as $value) {
            if (!Position::inRange($value['range'], $position)) {
                continue;
            }

            $command = $this->find($this->name($value['value']));

            if (!is_array($command)) {
                continue;
            }

            $lines = array_filter([
                (string) ($command['description'] ?? ''),
                (string) ($command['class'] ?? ''),
                is_string($command['path'] ?? null)
                    ? "[{$command['path']}]({$this->project->target($command['path'], is_numeric($command['line'] ?? null) ? (int) $command['line'] : null)})"
                    : '',
            ]);

            if ($lines === []) {
                continue;
            }

            return [
                'range'    => $value['range'],
                'contents' => [
                    'kind'  => 'markdown',
                    'value' => implode("\n\n", $lines),
                ],
            ];
        }

        return null;
    }

    /**
     * Convert the given document to diagnostics.
     *
     * @return array<int, array<string, mixed>>
     */
    public function diagnostics(Document $document): array
    {
        return $this->strictArguments($document)
            ->flatMap(fn (DetectedArgument $argument): array => $this->toDiagnostics($argument))
            ->values()
            ->all();
    }

    /**
     * Convert the given argument to diagnostics.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function toDiagnostics(DetectedArgument $argument): array
    {
        return collect($argument->stringValues())
            ->reject(fn (array $value): bool => $this->find($this->name($value['value'])) !== null)
            ->map(fn (array $value): array => [
                'range'    => $value['range'],
                'severity' => 2,
                'source'   => 'Laravel Extension',
                'code'     => 'command',
                'message'  => "Command [{$this->name($value['value'])}] not found.",
            ])
            ->values()
            ->all();
    }

    /**
     * Convert the given argument to completion items.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function toCompletions(AutocompleteArgument $argument): array
    {
        return $this->commands()
            ->filter(fn (array $command): bool => is_string($command['name'] ?? null) && $command['name'] !== '')
            ->map(fn (array $command): array => [
                'label'    => $command['name'],
                'kind'     => 13,
                'detail'   => (string) ($command['description'] ?? ''),
                'textEdit' => [
                    'range'   => $argument->replacementRange(),
                    'newText' => $command['name'],
                ],
            ])
            ->values()
            ->all();
    }

    /**
     * Get the command name without its arguments and options.
     */
    protected function name(string $value): string
    {
        return explode(' ', trim($value))[0];
    }

    /**
     * Find the command entry for the given name.
     *
     * @return array<string, mixed>|null
     */
    protected function find(string $name): ?array
    {
        $command = $this->commands()->firstWhere('name', $name);

        return is_array($command) ? $command : null;
    }

    /**
     * Get the available commands.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function commands(): Collection
    {
        return $this->project->index->commands();
    }
}
