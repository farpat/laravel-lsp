<?php

declare(strict_types=1);

namespace App\Lsp\Methods;

use App\Lsp\Contracts\Method;
use App\Lsp\DocumentManager;
use App\Lsp\FeatureRegistry;
use App\Lsp\Transport\JsonRpcRequest;
use App\Lsp\Transport\JsonRpcResponse;

class TextDocumentReferences implements Method
{
    /**
     * Instantiate a new class instance.
     */
    public function __construct(
        protected DocumentManager $documents,
        protected FeatureRegistry $features,
    ) {}

    /**
     * Handle the textDocument/references request.
     */
    public function handle(JsonRpcRequest $request): JsonRpcResponse
    {
        $document = $this->documents->get((string) $request->get('textDocument.uri'));
        $position = $request->get('position', []);

        if ($document === null || !is_array($position)) {
            return JsonRpcResponse::result($request->id(), []);
        }

        if (!is_int($position['line'] ?? null) || !is_int($position['character'] ?? null)) {
            return JsonRpcResponse::result($request->id(), []);
        }

        $locations = [];

        foreach ($this->features->references() as $provider) {
            array_push($locations, ...$provider->get($document, $position));

            $request->cancelIfRequested();
        }

        return JsonRpcResponse::result($request->id(), $locations);
    }
}
