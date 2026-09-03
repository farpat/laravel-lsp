<?php

function lspEventListener($listener): ?array
{
    if ($listener instanceof Closure) {
        $reflected = new ReflectionFunction($listener);

        return [
            'class'  => null,
            'method' => null,
            'queued' => false,
            'path'   => LspHelper::relativePath($reflected->getFileName()),
            'line'   => $reflected->getStartLine(),
        ];
    }

    if (is_array($listener)) {
        $class = is_object($listener[0]) ? get_class($listener[0]) : $listener[0];
        $method = $listener[1] ?? 'handle';
    } elseif (is_object($listener)) {
        $class = get_class($listener);
        $method = method_exists($listener, '__invoke') ? '__invoke' : 'handle';
    } else {
        [$class, $method] = Illuminate\Support\Str::parseCallback($listener);
        $method = $method ?? (method_exists($class, '__invoke') ? '__invoke' : 'handle');
    }

    if (!is_string($class) || !class_exists($class)) {
        return null;
    }

    $reflected = new ReflectionClass($class);
    $hasMethod = $reflected->hasMethod($method);

    $result = [
        'class'  => $class,
        'method' => $hasMethod ? $method : null,
        'queued' => in_array('Illuminate\Contracts\Queue\ShouldQueue', class_implements($class) ?: []),
        'path'   => null,
        'line'   => null,
    ];

    // A listener defined by an anonymous class, or by an extension, has no file to open.
    if ($reflected->getFileName() === false) {
        return $result;
    }

    return array_merge($result, [
        'path' => LspHelper::relativePath($reflected->getFileName()),
        'line' => $hasMethod ? $reflected->getMethod($method)->getStartLine() : $reflected->getStartLine(),
    ]);
}

echo collect(app('events')->getRawListeners())
    ->map(fn ($listeners) => collect($listeners)
        ->map(fn ($listener) => lspEventListener($listener))
        ->filter()
        ->values()
        ->all())
    ->filter(fn ($listeners) => count($listeners) > 0)
    ->toJson();
