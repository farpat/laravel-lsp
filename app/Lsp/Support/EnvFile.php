<?php

declare(strict_types=1);

namespace App\Lsp\Support;

use Illuminate\Support\Collection;

/**
 * The variables an env file declares, keyed by name.
 */
class EnvFile
{
    /**
     * Read the given env file.
     *
     * @return Collection<string, array{value: string, lineNumber: int}>
     */
    public static function read(string $path): Collection
    {
        if (!is_file($path)) {
            return collect();
        }

        return collect(explode("\n", (string) file_get_contents($path)))
            ->map(fn (string $line, int $index): array => [
                'line'       => trim($line),
                'lineNumber' => $index + 1,
            ])
            ->reject(fn (array $item): bool => $item['line'] === '' || str_starts_with($item['line'], '#'))
            ->mapWithKeys(function (array $item): array {
                [$key, $value] = array_pad(explode('=', $item['line'], 2), 2, '');

                return [trim($key) => [
                    'value'      => trim($value),
                    'lineNumber' => $item['lineNumber'],
                ]];
            });
    }
}
