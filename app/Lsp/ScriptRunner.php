<?php

declare(strict_types=1);

namespace App\Lsp;

use App\Lsp\Exceptions\ScriptFailedException;
use Symfony\Component\Process\Process;

class ScriptRunner
{
    /**
     * PHP error types to suppress while running project scripts.
     */
    protected const SUPPRESSED_ERROR_TYPES = 'E_WARNING | E_CORE_WARNING | E_COMPILE_WARNING | E_USER_WARNING | E_DEPRECATED | E_USER_DEPRECATED';

    /**
     * Whether the project can be bootstrapped without artisan.
     */
    protected ?bool $bootable = null;

    /**
     * Create a new PHP runner instance.
     *
     * @param  array<int, string>  $command
     */
    public function __construct(
        protected string $path,
        protected array $command,
    ) {}

    /**
     * Get the PHP command used to run scripts.
     *
     * @return array<int, string>
     */
    public function command(): array
    {
        return $this->command;
    }

    /**
     * Run PHP code in the user's Laravel application via artisan tinker.
     *
     * @throws ScriptFailedException
     */
    public function run(string $code): string
    {
        $script = $this->write($code);

        try {
            $process = new Process($this->arguments($script), $this->path, timeout: null);

            $process->run();

            if (!$process->isSuccessful()) {
                throw ScriptFailedException::forProcess($process);
            }

            return $process->getOutput();
        } finally {
            @unlink($this->path . '/' . $script);
        }
    }

    /**
     * Get the process arguments used to run the given script.
     *
     * @return array<int, string>
     */
    protected function arguments(string $script): array
    {
        $arguments = [
            ...$this->command,
            '-d',
            'error_reporting=E_ALL & ~(' . self::SUPPRESSED_ERROR_TYPES . ')',
        ];

        return $this->bootable()
            ? [...$arguments, $script]
            : [...$arguments, 'artisan', 'tinker', '--execute', 'require ' . var_export($script, true) . ';'];
    }

    /**
     * Determine if the project can be bootstrapped without artisan.
     */
    protected function bootable(): bool
    {
        return $this->bootable ??= is_file($this->path . '/vendor/autoload.php')
            && is_file($this->path . '/bootstrap/app.php');
    }

    /**
     * Get the lines that bootstrap the application inside the script.
     *
     * @return array<int, string>
     */
    protected function bootstrap(): array
    {
        if (!$this->bootable()) {
            return [];
        }

        return [
            "define('LARAVEL_START', microtime(true));",
            "require getcwd() . '/vendor/autoload.php';",
            "\$app = require getcwd() . '/bootstrap/app.php';",
            '$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();',
        ];
    }

    /**
     * Write the script to a file inside the project.
     *
     * @throws ScriptFailedException
     */
    protected function write(string $code): string
    {
        $script = 'storage/framework/lsp-' . bin2hex(random_bytes(8)) . '.php';
        $path = $this->path . '/' . $script;
        $directory = dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0777, true)) {
            throw ScriptFailedException::forUnwritableScript($path);
        }

        if (@file_put_contents($path, $this->code($code)) === false) {
            throw ScriptFailedException::forUnwritableScript($path);
        }

        return $script;
    }

    /**
     * Get PHP code with LSP template helpers available.
     */
    protected function code(string $code): string
    {
        return implode(PHP_EOL, [
            '<?php',
            ...$this->bootstrap(),
            'error_reporting(error_reporting() & ~(' . self::SUPPRESSED_ERROR_TYPES . '));',
            $this->normalize(file_get_contents(__DIR__ . '/Data/Templates/global.php') ?: ''),
            $this->normalize($code),
        ]);
    }

    /**
     * Normalize PHP code before passing it to tinker.
     */
    protected function normalize(string $code): string
    {
        return str_starts_with($code, '<?php')
            ? ltrim(substr($code, 5))
            : $code;
    }

    /**
     * Run PHP code and decode the output as JSON.
     *
     * @throws ScriptFailedException
     */
    public function json(string $code): mixed
    {
        $output = $this->run($code);

        $decoded = json_decode($output, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw ScriptFailedException::forInvalidJson($output);
        }

        return $decoded;
    }
}
