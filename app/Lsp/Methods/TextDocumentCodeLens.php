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

        $lenses = [];
        $providers = $this->features->references();

        foreach (explode("\n", $document->content) as $line => $text) {
            if (trim($text) === '') {
                continue;
            }

            $position = ['line' => $line, 'character' => 0];
            $usages = 0;

            foreach ($providers as $provider) {
                $usages += count($provider->get($document, $position));
            }

            if ($usages === 0) {
                continue;
            }

            $lenses[] = [
                'range' => [
                    'start' => ['line' => $line, 'character' => 0],
                    'end'   => ['line' => $line, 'character' => mb_strlen($text)],
                ],
                'command' => [
                    'title'     => $usages === 1 ? '1 usage' : "{$usages} usages",
                    'command'   => 'laravel.showUsages',
                    'arguments' => [$document->uri, $position],
                ],
            ];

            $request->cancelIfRequested();
        }

        return JsonRpcResponse::result($request->id(), $lenses);
    }
}
