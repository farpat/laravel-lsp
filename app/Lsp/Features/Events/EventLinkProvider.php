<?php

declare(strict_types=1);

namespace App\Lsp\Features\Events;

use App\Lsp\Contracts\LinkProvider;
use App\Lsp\Document;
use App\Lsp\Project;

/**
 * Opens the class of an event from where it is dispatched.
 *
 * The hover already says what an event triggers; the link answers the other question a
 * dispatch raises, which is what the event carries.
 */
class EventLinkProvider implements LinkProvider
{
    /**
     * Create a new event link provider instance.
     */
    public function __construct(
        protected Project $project,
    ) {}

    /**
     * Provide links to the events dispatched in the given document.
     *
     * @return array<int, array<string, mixed>>
     */
    public function get(Document $document): array
    {
        if (!$this->project->boolean('eventLink', true)) {
            return [];
        }

        $events = $this->project->index->events();
        $links = [];

        foreach ((new DispatchedEvents($this->project))->in($document) as $site) {
            $event = $events->get($site['event'], []);

            if (!is_array($event) || !is_string($event['path'] ?? null)) {
                continue;
            }

            $links[] = $this->project->link(
                $site['range'],
                $event['path'],
                is_numeric($event['line'] ?? null) ? (int) $event['line'] : null,
            );
        }

        return $links;
    }
}
