<?php

echo collect(app("Illuminate\Contracts\Console\Kernel")->all())
    ->map(function ($command, $name) {
        $result = [
            'name'        => $name,
            'class'       => get_class($command),
            'description' => $command->getDescription(),
            'path'        => null,
            'line'        => null,
        ];

        // A closure command declared with `Artisan::command` has no class of its own to
        // navigate to. It stays in the index so it is not reported as unknown.
        if ($command instanceof \Illuminate\Foundation\Console\ClosureCommand) {
            return $result;
        }

        $reflected = new ReflectionClass($command);

        if ($reflected->getFileName() === false) {
            return $result;
        }

        return array_merge($result, [
            'path' => LspHelper::relativePath($reflected->getFileName()),
            'line' => $reflected->getStartLine(),
        ]);
    })
    ->values()
    ->toJson();
