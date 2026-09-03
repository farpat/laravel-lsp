<?php

declare(strict_types=1);

namespace App\Lsp\Features\Events;

use App\Lsp\Contracts\HoverProvider;
use App\Lsp\Document;
use App\Lsp\Project;

/**
 * Reading a dispatch, what matters is what it triggers, so it is written right there.
 *
 * An event says nothing about who reacts to it, the wiring lives in a provider, in an
 * attribute or nowhere at all when it is auto-discovered, so the answer comes from the
 * application itself.
 */
class EventHoverProvider implements HoverProvider
{
    /**
     * Create a new event hover provider instance.
     */
    public function __construct(
        protected Project $project,
    ) {}

    /**
     * Provide the listeners of the event dispatched at the given position.
     *
     * @param  array<string, mixed>  $position
     * @return array<string, mixed>|null
     */
    public function get(Document $document, array $position): ?array
    {
        if (!$this->project->boolean('eventListenerHover', true)) {
            return null;
        }

        $site = (new DispatchedEvents($this->project))->at($document, $position);

        if ($site === null) {
            return null;
        }

        $listeners = collect($this->project->index->events()->get($site['event'], []));

        if ($listeners->isEmpty()) {
            return null;
        }

        $lines = $listeners
            ->map(fn (array $listener): string => $this->describe($listener))
            ->prepend('**' . $listeners->count() . ' listener(s)**');

        return [
            'range'    => $site['range'],
            'contents' => [
                'kind'  => 'markdown',
                'value' => $lines->implode("\n"),
            ],
        ];
    }

    /**
     * Get the markdown line describing the given listener.
     *
     * @param  array<string, mixed>  $listener
     */
    protected function describe(array $listener): string
    {
        $name = $this->name($listener);
        $queued = ($listener['queued'] ?? false) === true ? ' _(queued)_' : '';

        if (!is_string($listener['path'] ?? null)) {
            return "- {$name}{$queued}";
        }

        $line = is_numeric($listener['line'] ?? null) ? (int) $listener['line'] : null;

        return "- [{$name}]({$this->project->target($listener['path'], $line)}){$queued}";
    }

    /**
     * Get the name of the given listener.
     *
     * @param  array<string, mixed>  $listener
     */
    protected function name(array $listener): string
    {
        if (!is_string($listener['class'] ?? null)) {
            return 'Closure';
        }

        $class = (string) collect(explode('\\', $listener['class']))->last();
        $method = $listener['method'] ?? null;

        return is_string($method) && $method !== 'handle' ? "{$class}::{$method}" : $class;
    }
}
