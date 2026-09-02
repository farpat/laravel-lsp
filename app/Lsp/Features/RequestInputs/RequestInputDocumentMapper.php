<?php

declare(strict_types=1);

namespace App\Lsp\Features\RequestInputs;

use App\Lsp\Detection\AutocompleteArgument;
use App\Lsp\Detection\DetectedArgument;
use App\Lsp\Detection\DetectedArguments;
use App\Lsp\Detection\Pattern;
use App\Lsp\Document;
use App\Lsp\Features\Support\DocumentMapper;
use App\Lsp\Project;
use App\Lsp\Support\FileUri;
use Illuminate\Support\Collection;

class RequestInputDocumentMapper extends DocumentMapper
{
    /**
     * Every way the current request is reached: injected, helper, facade.
     *
     * @var array<int, string>
     */
    protected const REQUEST_CLASSES = [
        'Illuminate\\Http\\Request',
        'request',
        'Request',
        'Illuminate\\Support\\Facades\\Request',
    ];

    /**
     * Methods reading one input by name, the name always being the first argument.
     *
     * @var array<int, string>
     */
    protected const READING_METHODS = [
        'anyFilled', 'array', 'boolean', 'collect', 'date', 'enum', 'except', 'exists',
        'filled', 'float', 'has', 'hasAny', 'input', 'integer', 'isNotFilled', 'json',
        'missing', 'old', 'only', 'post', 'query', 'str', 'string', 'validated',
        'whenFilled', 'whenHas', 'whenMissing',
    ];

    /**
     * The document the inputs are read from.
     */
    protected ?Document $document = null;

    /**
     * Create a new request input document mapper instance.
     */
    public function __construct(
        protected Project $project,
    ) {}

    /**
     * Get request input detection patterns.
     *
     * @return array<int, Pattern>
     */
    protected function patterns(): array
    {
        // A form request is a class of the project, so the classes to look for are the
        // ones the application declares, not a list written here.
        $classes = [...self::REQUEST_CLASSES, ...$this->formRequests()->keys()->all()];

        return [
            Pattern::method(method: self::READING_METHODS, class: $classes, argument: 0),
        ];
    }

    /**
     * Get matched request input arguments from the document.
     *
     * @return Collection<int, DetectedArgument>
     */
    public function arguments(Document $document): Collection
    {
        $this->document = $document;

        return DetectedArguments::in($document)
            ->matching($this->patterns())
            ->strings()
            ->filter(fn (DetectedArgument $argument): bool => $this->shouldAccept($argument))
            ->values();
    }

    /**
     * Get matched request input arguments being completed.
     *
     * @param  array<string, mixed>  $position
     * @return Collection<int, AutocompleteArgument>
     */
    public function completionArguments(Document $document, array $position): Collection
    {
        // The inputs can be declared by the document itself, which the completion path
        // would otherwise never see.
        $this->document = $document;

        return parent::completionArguments($document, $position);
    }

    /**
     * Convert the given argument to document links.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function toLinks(DetectedArgument $argument): array
    {
        $keys = $this->keysFor($argument);

        return collect($argument->stringValues())
            ->map(function (array $value) use ($keys): ?array {
                $key = $keys[$value['value']] ?? null;

                if ($key === null || !is_string($key['path'] ?? null)) {
                    return null;
                }

                return $this->project->link($value['range'], $key['path'], is_numeric($key['line'] ?? null) ? (int) $key['line'] : null);
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Convert the given argument to diagnostics.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function toDiagnostics(DetectedArgument $argument): array
    {
        $keys = $this->keysFor($argument);

        // Nothing declares the inputs of this request: every name is as good as another.
        if ($keys === []) {
            return [];
        }

        return collect($argument->stringValues())
            ->reject(fn (array $value): bool => isset($keys[$value['value']]))
            ->map(fn (array $value): array => [
                'range'    => $value['range'],
                'severity' => 2,
                'source'   => 'Laravel Extension',
                'code'     => 'request-input',
                'message'  => "Input [{$value['value']}] is not validated by this request.",
            ])
            ->values()
            ->all();
    }

    /**
     * Convert the given argument to completion items.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function toCompletions(AutocompleteArgument $argument): array
    {
        $keys = $this->keysForClass($argument->item()['className'] ?? null);

        return collect($keys)
            ->map(fn (array $key, string $name): array => [
                'label'    => $name,
                'kind'     => 5,
                'detail'   => (string) ($key['rules'] ?? ''),
                'textEdit' => [
                    'range'   => $argument->replacementRange(),
                    'newText' => $name,
                ],
            ])
            ->values()
            ->all();
    }

    /**
     * Get the inputs the given call can read.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function keysFor(DetectedArgument $argument): array
    {
        return $this->keysForClass($argument->item()['className'] ?? null);
    }

    /**
     * Get the inputs declared for the given request class.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function keysForClass(?string $className): array
    {
        $formRequest = is_string($className) ? $this->formRequests()->get($className) : null;

        if (is_array($formRequest)) {
            return collect($formRequest['keys'] ?? [])
                ->mapWithKeys(fn (array $key): array => [
                    (string) $key['name'] => [
                        'rules' => $key['rules'] ?? '',
                        'line'  => $key['line'] ?? null,
                        'path'  => $formRequest['path'] ?? null,
                    ],
                ])
                ->all();
        }

        return $this->inlineKeys();
    }

    /**
     * Get the inputs a `validate()` call declares in the document itself.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function inlineKeys(): array
    {
        if ($this->document === null) {
            return [];
        }

        $path = $this->project->uri()->relativePath(FileUri::of($this->document->uri)->path());

        return $this->document->detect()
            ->flatMap(fn (array $item): array => $this->rulesOf($item))
            ->mapWithKeys(fn (array $rule): array => [
                $rule['name'] => [
                    'rules' => $rule['rules'],
                    'line'  => $rule['line'],
                    'path'  => $path,
                ],
            ])
            ->all();
    }

    /**
     * Get the rules a detected call declares, if it declares any.
     *
     * @param  array<string, mixed>  $item
     * @return array<int, array<string, mixed>>
     */
    protected function rulesOf(array $item): array
    {
        $declaring = [
            ['methods' => ['validate'], 'classes' => [...self::REQUEST_CLASSES, null], 'argument' => 0],
            ['methods' => ['make'], 'classes' => ['Validator', 'Illuminate\\Support\\Facades\\Validator'], 'argument' => 1],
        ];

        foreach ($declaring as $call) {
            if (($item['type'] ?? null) !== 'methodCall'
                || !in_array($item['methodName'] ?? null, $call['methods'], true)
                || !in_array($item['className'] ?? null, $call['classes'], true)) {
                continue;
            }

            $argument = $item['arguments']['children'][$call['argument']] ?? null;

            foreach ($argument['children'] ?? [] as $child) {
                if (($child['type'] ?? null) === 'array') {
                    return $this->rulesFromArray($child);
                }
            }
        }

        return [];
    }

    /**
     * Read the rules an array of validation rules declares.
     *
     * @param  array<string, mixed>  $array
     * @return array<int, array<string, mixed>>
     */
    protected function rulesFromArray(array $array): array
    {
        return collect($array['children'] ?? [])
            ->filter(fn (array $item): bool => ($item['key']['type'] ?? null) === 'string')
            ->map(fn (array $item): array => [
                'name'  => (string) $item['key']['value'],
                'rules' => $this->rulesToString($item['value'] ?? []),
                // A rule is written where its key is, which is where a reader expects to land.
                'line'  => (int) ($item['key']['start']['line'] ?? 0),
            ])
            ->values()
            ->all();
    }

    /**
     * Read a rule set written either as a string or as an array.
     *
     * @param  array<string, mixed>  $value
     */
    protected function rulesToString(array $value): string
    {
        if (($value['type'] ?? null) === 'string') {
            return (string) $value['value'];
        }

        if (($value['type'] ?? null) !== 'array') {
            return '';
        }

        return collect($value['children'] ?? [])
            ->map(fn (array $item): ?string => ($item['value']['type'] ?? null) === 'string' ? (string) $item['value']['value'] : null)
            ->filter()
            ->implode('|');
    }

    /**
     * Get the form requests the application declares.
     *
     * @return Collection<string, array<string, mixed>>
     */
    protected function formRequests(): Collection
    {
        return $this->project->index->formRequests();
    }
}
