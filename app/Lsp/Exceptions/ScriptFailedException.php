<?php

declare(strict_types=1);

namespace App\Lsp\Exceptions;

use Exception;
use Symfony\Component\Process\Process;

class ScriptFailedException extends Exception
{
    /**
     * Create a new exception for a script that could not be written into the project.
     */
    public static function forUnwritableScript(string $path): self
    {
        return new self("Unable to write the project script at [{$path}].");
    }

    /**
     * Create a new exception for a script whose process did not succeed.
     */
    public static function forProcess(Process $process): self
    {
        // PHP prints a fatal error on stdout in CLI, so stderr alone can be empty.
        $output = trim($process->getErrorOutput()) ?: trim($process->getOutput());

        return new self(
            "The project script exited with code [{$process->getExitCode()}] running [{$process->getCommandLine()}]: {$output}"
        );
    }

    /**
     * Create a new exception for a script that printed something other than JSON.
     */
    public static function forInvalidJson(string $output): self
    {
        return new self('The project script did not print JSON: ' . mb_substr(trim($output), 0, 500));
    }
}
