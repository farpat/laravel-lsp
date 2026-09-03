<?php

declare(strict_types=1);

namespace App\Lsp\Features\Events;

use App\Lsp\Document;
use App\Lsp\Project;

/**
 * The events dispatched on a line of a document.
 *
 * Dispatches are read from the text rather than from the parser: the parser gives a range
 * to strings only, and an event travels as an object.
 */
class DispatchedEvents
{
    /**
     * The ways an event reaches the dispatcher. Each one names the class it carries, which
     * is all that is read here: whether that class is an event is answered by the
     * application, not guessed from the call.
     *
     * @var array<int, string>
     */
    protected const DISPATCHES = [
        '/\b(?:event|broadcast|dispatch)\s*\(\s*new\s+\\\\?([\w\\\\]+)/',
        '/\b(?:Event|Bus)::dispatch\w*\s*\(\s*new\s+\\\\?([\w\\\\]+)/',
        '/\b\\\\?([\w\\\\]+)::dispatch\w*\s*\(/',
    ];

    /**
     * The event each written name resolves to, keyed by document and name.
     *
     * @var array<string, string|null>
     */
    protected array $resolved = [];

    /**
     * Create a new dispatched events instance.
     */
    public function __construct(
        protected Project $project,
    ) {}

    /**
     * Get every event dispatched in the document.
     *
     * @return array<int, array{event: string, range: array<string, array<string, int>>}>
     */
    public function in(Document $document): array
    {
        $sites = [];

        foreach (array_keys($document->lines()) as $line) {
            array_push($sites, ...$this->on($document, $line));
        }

        return $sites;
    }

    /**
     * Get the event dispatched at the given position, with the range of its name.
     *
     * @param  array<string, mixed>  $position
     * @return array{event: string, range: array<string, array<string, int>>}|null
     */
    public function at(Document $document, array $position): ?array
    {
        $line = $position['line'] ?? -1;
        $character = $position['character'] ?? -1;

        foreach ($this->on($document, is_int($line) ? $line : -1) as $site) {
            if ($character >= $site['range']['start']['character'] && $character <= $site['range']['end']['character']) {
                return $site;
            }
        }

        return null;
    }

    /**
     * Get every event dispatched on the given line.
     *
     * @return array<int, array{event: string, range: array<string, array<string, int>>}>
     */
    public function on(Document $document, int $line): array
    {
        $text = $document->lines()[$line] ?? null;

        if (!is_string($text)) {
            return [];
        }

        $sites = [];

        foreach (self::DISPATCHES as $pattern) {
            if (preg_match_all($pattern, $text, $found, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            foreach ($found[1] ?? [] as [$written, $offset]) {
                $event = $this->resolve($document, (string) $written);

                if ($event === null) {
                    continue;
                }

                $start = mb_strlen(substr($text, 0, $offset));

                $sites[] = [
                    'event' => $event,
                    'range' => [
                        'start' => ['line' => $line, 'character' => $start],
                        'end'   => ['line' => $line, 'character' => $start + mb_strlen((string) $written)],
                    ],
                ];
            }
        }

        return $sites;
    }

    /**
     * Get the event a name written in the document refers to.
     *
     * Resolved the way PHP resolves it: an import, then the current namespace. Null when
     * the application knows no such event, which is also how a job or a plain class
     * dispatched nearby is left alone.
     */
    protected function resolve(Document $document, string $written): ?string
    {
        return $this->resolved[$document->uri . ' ' . $written] ??= $this->resolveOnce($document, $written);
    }

    /**
     * Read the imports and the namespace of the document to resolve the given name.
     */
    protected function resolveOnce(Document $document, string $written): ?string
    {
        $events = $this->project->index->events();

        if ($events->has($written)) {
            return $written;
        }

        $short = (string) collect(explode('\\', $written))->last();
        $imported = $this->matched($document, '/^\s*use\s+([\w\\\\]+\\\\' . preg_quote($short, '/') . ')\s*;/m');

        if ($imported !== null && $events->has($imported)) {
            return $imported;
        }

        $namespace = $this->matched($document, '/^\s*namespace\s+([\w\\\\]+)\s*;/m');

        if ($namespace !== null && $events->has($namespace . '\\' . $short)) {
            return $namespace . '\\' . $short;
        }

        // Written without an import, from a namespace that is not the event's own: only
        // worth answering when a single known event bears that name.
        $known = $events->keys()->filter(fn (string $event): bool => str_ends_with($event, '\\' . $short));

        return $known->count() === 1 ? (string) $known->first() : null;
    }

    /**
     * Get the first capture of the given pattern in the document.
     */
    protected function matched(Document $document, string $pattern): ?string
    {
        return preg_match($pattern, $document->content, $found) === 1 ? $found[1] : null;
    }
}
