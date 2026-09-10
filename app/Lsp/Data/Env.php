<?php

declare(strict_types=1);

namespace App\Lsp\Data;

use App\Lsp\Contracts\DataProvider;
use App\Lsp\Project;
use App\Lsp\Support\EnvFile;
use Illuminate\Support\Collection;

class Env implements DataProvider
{
    /**
     * Create a new env provider instance.
     */
    public function __construct(protected Project $project)
    {
        //
    }

    /**
     * Get env-related watcher patterns.
     *
     * @return array<int, string>
     */
    public function patterns(): array
    {
        return [
            '.env',
        ];
    }

    /**
     * Get env variables keyed by name.
     */
    public function get(): Collection
    {
        return EnvFile::read($this->project->path('.env'));
    }
}
