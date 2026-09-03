<?php

declare(strict_types=1);

namespace App\Lsp\Features\Events;

use App\Lsp\CodeActions\CodeActionContext;
use App\Lsp\Contracts\CodeActionProvider;
use App\Lsp\Document;
use App\Lsp\Project;
use App\Lsp\Support\FileUri;

/**
 * Offers one entry per listener of the event dispatched on the current line.
 *
 * The hover already lists them, but a hover is read, not acted on: from a dispatch, the
 * next thing wanted is usually to open what it triggers.
 */
class EventCodeActionProvider implements CodeActionProvider
{
    /**
     * Create a new event code action provider instance.
     */
    public function __construct(
        protected Project $project,
    ) {}

    /**
     * Provide the listeners of the event dispatched at the given range.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get(Document $document, CodeActionContext $context): array
    {
        if (!$this->project->boolean('eventListenerCodeActions', true) || !$context->accepts('navigate')) {
            return [];
        }

        $line = $context->range['start']['line'] ?? null;

        if (!is_int($line)) {
            return [];
        }

        $site = (new DispatchedEvents($this->project))->on($document, $line)[0] ?? null;

        if ($site === null) {
            return [];
        }

        $event = $this->project->index->events()->get($site['event'], []);

        return collect(is_array($event['listeners'] ?? null) ? $event['listeners'] : [])
            ->filter(fn (array $listener): bool => is_string($listener['path'] ?? null))
            ->map(fn (array $listener): array => $this->openAction($listener))
            ->values()
            ->all();
    }

    /**
     * Create the action opening the given listener.
     *
     * @param  array<string, mixed>  $listener
     * @return array<string, mixed>
     */
    protected function openAction(array $listener): array
    {
        $name = is_string($listener['class'] ?? null)
            ? (string) collect(explode('\\', $listener['class']))->last()
            : 'Closure';
        $title = "Go to listener {$name}";
        $line = max((is_numeric($listener['line'] ?? null) ? (int) $listener['line'] : 1) - 1, 0);
        $position = ['line' => $line, 'character' => 0];

        return [
            'title' => $title,
            'kind'  => 'navigate',
            // `vscode.open` is the editor's own command: the server carries no client, so
            // an action of its own would need one written on the other side.
            'command' => [
                'title'     => $title,
                'command'   => 'vscode.open',
                'arguments' => [
                    (string) FileUri::fromPath($this->project->path((string) $listener['path'])),
                    ['selection' => ['start' => $position, 'end' => $position]],
                ],
            ],
        ];
    }
}
