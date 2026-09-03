<?php

declare(strict_types=1);

namespace App\Lsp\Methods;

use App\Lsp\Contracts\Method;
use App\Lsp\DocumentManager;
use App\Lsp\FeatureRegistry;
use App\Lsp\Project;
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
            foreach ($provider->counts($document) as $line => $count) {
                $usages[$line] = ($usages[$line] ?? 0) + $count;
            }

            $request->cancelIfRequested();
        }

        ksort($usages);

        $lines = $document->lines();
        $lenses = [];

        foreach ($usages as $line => $count) {
            $lenses[] = [
                'range' => [
                    'start' => ['line' => $line, 'character' => 0],
                    'end'   => ['line' => $line, 'character' => mb_strlen($lines[$line] ?? '')],
                ],
                'command' => [
                    'title'     => $count === 1 ? '1 usage' : "{$count} usages",
                    'command'   => 'laravel.showUsages',
                    'arguments' => [$document->uri, ['line' => $line, 'character' => 0]],
                ],
            ];
        }

        return JsonRpcResponse::result($request->id(), $lenses);
    }
}
