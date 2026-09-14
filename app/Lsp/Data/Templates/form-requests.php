<?php

function lspFormRequestRules($rules): string
{
    if (is_string($rules)) {
        return $rules;
    }

    if (!is_array($rules)) {
        return '';
    }

    return collect($rules)
        ->map(function ($rule) {
            if (is_string($rule)) {
                return $rule;
            }

            if (is_object($rule)) {
                return method_exists($rule, '__toString') ? (string) $rule : class_basename($rule);
            }

            return '';
        })
        ->filter()
        ->implode('|');
}

/**
 * Reflection knows where the method is, not where each of its keys is, so the key is
 * looked up in the source lines of the method itself. The line is 1-based, as a link
 * target reads it.
 */
function lspFormRequestLine(array $source, int $start, int $end, string $key): int
{
    for ($line = $start; $line < $end; $line++) {
        if (preg_match('/([\'"])' . preg_quote($key, '/') . '\1\s*=>/', $source[$line] ?? '')) {
            return $line + 1;
        }
    }

    return $start + 1;
}

function lspFormRequest(string $class): ?array
{
    $reflected = new ReflectionClass($class);

    if (!$reflected->isInstantiable() || !$reflected->hasMethod('rules')) {
        return null;
    }

    $method = $reflected->getMethod('rules');

    try {
        $rules = $method->invoke($reflected->newInstance());
    } catch (\Throwable $e) {
        return null;
    }

    if (!is_array($rules)) {
        return null;
    }

    $source = file($reflected->getFileName()) ?: [];
    $start = $method->getStartLine() - 1;
    $end = $method->getEndLine();

    return [
        'path' => LspHelper::relativePath($reflected->getFileName()),
        'keys' => collect($rules)
            ->map(fn($rules, $key) => [
                'name' => (string) $key,
                'rules' => lspFormRequestRules($rules),
                'line' => lspFormRequestLine($source, $start, $end, (string) $key),
            ])
            ->values()
            ->all(),
    ];
}

if (\Illuminate\Support\Facades\File::isDirectory(app_path())) {
    collect(\Illuminate\Support\Facades\File::allFiles(app_path()))
        ->filter(fn(\Symfony\Component\Finder\SplFileInfo $file) => str_ends_with($file->getFilename(), 'Request.php'))
        ->each(function (\Symfony\Component\Finder\SplFileInfo $file) {
            try {
                include_once $file->getRealPath();
            } catch (\Throwable $e) {
                // A request we cannot load is a request we cannot describe.
            }
        });
}

echo collect(get_declared_classes())
    ->filter(fn($class) => is_subclass_of($class, \Illuminate\Foundation\Http\FormRequest::class))
    ->mapWithKeys(fn($class) => [$class => lspFormRequest($class)])
    ->filter()
    ->toJson();
