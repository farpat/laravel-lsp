<?php

declare(strict_types=1);

namespace App\Lsp\Data;

use App\Lsp\Contracts\DataProvider;
use App\Lsp\Project;
use Illuminate\Support\Collection;

/**
 * Events that are listened to, keyed by the class they are dispatched as, each with the
 * file declaring it and its listeners.
 *
 * The listeners are read from the application itself, which is the only place they are
 * all together: the ones registered by a provider, the auto-discovered ones, the
 * subscribers and the closures.
 */
class Events implements DataProvider
{
    /**
     * Instantiate a new class instance.
     */
    public function __construct(protected Project $project)
    {
        //
    }

    /**
     * Get the listened events, keyed by the class they are dispatched as.
     */
    public function get(): Collection
    {
        $data = $this->project->scripts->json(file_get_contents(__DIR__ . '/Templates/events.php') ?: '');

        return collect(is_array($data) ? $data : []);
    }

    /**
     * Patterns that reevaluate the data.
     *
     * @return array<int, string>
     */
    public function patterns(): array
    {
        return [
            'app/Events/{*.php,**/*.php}',
            'app/Listeners/{*.php,**/*.php}',
            'app/Providers/{*.php,**/*.php}',
            'bootstrap/app.php',
        ];
    }
}
