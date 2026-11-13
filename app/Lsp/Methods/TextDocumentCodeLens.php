<?php

declare(strict_types=1);

namespace App\Lsp\Methods;

use App\Lsp\Contracts\ExceptionHandler;
use App\Lsp\Contracts\Method;
use App\Lsp\DocumentManager;
use App\Lsp\Exceptions\ScriptFailedException;
use App\Lsp\FeatureRegistry;
use App\Lsp\Project;
use App\Lsp\Support\Position;
use App\Lsp\Transport\JsonRpcRequest;
use App\Lsp\Transport\JsonRpcResponse;

class TextDocumentCodeLens implements Method
{
    /**
     * Instantiate a new class instance.
     */
    public function __construct(
        protected DocumentManager $documents,
        protected FeatureRegistry $features,
        protected Project $project,
        protected ExceptionHandler $exceptions,
    ) {}

    /**
     * Handle the textDocument/codeLens request.
     *
     * A declaration says nothing about who reads it. Counting the usages above the line
     * turns "is this route still called anywhere" into something the file answers by
     * itself.
     */
    public function handle(JsonRpcRequest $request): JsonRpcResponse
    {
        $document = $this->documents->get((string) $request->get('textDocument.uri'));

        if ($document === null || !$this->project->boolean('usagesCodeLens', true)) {
            return JsonRpcResponse::result($request->id(), []);
        }

        $usages = [];

        foreach ($this->features->references() as $provider) {
            try {
                foreach ($provider->counts($document) as $found) {
                    $key = $found['line'] . ':' . $found['character'];
                    $usages[$key] = [
                        'line'      => $found['line'],
                        'character' => $found['character'],
                        'count'     => ($usages[$key]['count'] ?? 0) + $found['count'],
                    ];
                }
            } catch (ScriptFailedException $e) {
                // A project that cannot be read loses this feature, not the others.
                $this->exceptions->report($e);
            }

            $request->cancelIfRequested();
        }

        // A line can declare more than one name: each one keeps its own lens, in the
        // order they are written.
        usort($usages, fn (array $left, array $right): int => [$left['line'], $left['character']] <=> [$right['line'], $right['character']]);

        $lines = $document->lines();
        $lenses = [];

        foreach ($usages as ['line' => $line, 'character' => $character, 'count' => $count]) {
            $lenses[] = [
                'range' => [
                    'start' => ['line' => $line, 'character' => $character],
                    'end'   => ['line' => $line, 'character' => Position::countCharacters($lines[$line] ?? '')],
                ],
                'command' => [
                    'title'     => $count === 1 ? '1 usage' : "{$count} usages",
                    'command'   => 'laravel.showUsages',
                    'arguments' => [$document->uri, ['line' => $line, 'character' => $character]],
                ],
            ];
        }

        return JsonRpcResponse::result($request->id(), $lenses);
    }
}
