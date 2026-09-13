<?php

namespace App\Parsers;

use App\Contexts\AbstractContext;
use App\Contexts\Blade;
use App\Parser\Parse;
use App\Parser\Settings;
use Microsoft\PhpParser\Node\Attribute;
use Microsoft\PhpParser\Node\Expression\CallExpression;
use Microsoft\PhpParser\Node\Expression\ObjectCreationExpression;
use Microsoft\PhpParser\Node\Statement\InlineHtml;
use Microsoft\PhpParser\Parser;
use Microsoft\PhpParser\PositionUtilities;
use Microsoft\PhpParser\Range;
use Stillat\BladeParser\Document\Document;
use Stillat\BladeParser\Nodes\BaseNode;
use Stillat\BladeParser\Nodes\Components\ComponentNode;
use Stillat\BladeParser\Nodes\Components\ParameterAttribute;
use Stillat\BladeParser\Nodes\Components\ParameterType;
use Stillat\BladeParser\Nodes\DirectiveNode;
use Stillat\BladeParser\Nodes\EchoNode;
use Stillat\BladeParser\Nodes\EchoType;
use Stillat\BladeParser\Nodes\LiteralNode;
use Stillat\BladeParser\Nodes\PhpBlockNode;

class InlineHtmlParser extends AbstractParser
{
    protected $echoStrings = [
        '{!!' => '!!}',
        '{{{' => '}}}',
        '{{'  => '}}',
    ];

    protected $startLine = 0;

    /**
     * @var Blade
     */
    protected AbstractContext $context;

    protected array $items = [];

    /**
     * Stillat\BladeParser\Document\Document::fromText treats multibyte characters
     * as indentations and spaces resulting in a miscalculated Node position.
     *
     * This function replaces the multibyte characters with a single, placeholder character
     */
    private function replaceMultibyteChars(string $text, string $placeholder = '*'): string
    {
        return preg_replace('/[^\x00-\x7F]/u', $placeholder, $text);
    }

    public function parse(InlineHtml $node)
    {
        if ($node->getStartPosition() > 0) {
            $range = PositionUtilities::getRangeFromPosition(
                $node->getStartPosition(),
                mb_strlen($node->getText()),
                $node->getRoot()->getFullText(),
            );

            $this->startLine = $range->start->line;
        }

        $this->parseBladeContent(Document::fromText(
            $this->replaceMultibyteChars($node->getText())
        ));

        if (count($this->items)) {
            $blade = new Blade;
            $this->context->initNew($blade);

            $blade->children = $this->items;

            return $blade;
        }

        return $this->context;
    }

    protected function parseBladeContent($node)
    {
        foreach ($node->getNodes() as $child) {
            // TODO: Add other echo types as well
            if ($child instanceof LiteralNode) {
                $this->parseLiteralNode($child);
            }

            if ($child instanceof DirectiveNode) {
                $this->parseBladeDirective($child);
            }

            if ($child instanceof EchoNode) {
                $this->parseEchoNode($child);
            }

            if ($child instanceof PhpBlockNode) {
                $this->parsePhpBlock($child);
            }

            if ($child instanceof ComponentNode) {
                $this->parseComponentParameters($child);
            }

            $this->parseBladeContent($child);
        }
    }

    protected function doEchoParse(BaseNode $node, $prefix, $content)
    {
        $snippet = "<?php\n" . str_repeat(' ', $node->getStartIndentationLevel()) . str_replace($prefix, '', $content) . ';';

        $sourceFile = (new Parser)->parseSourceFile($snippet);

        $suffix = $this->echoStrings[$prefix];

        Settings::$calculatePosition = function (Range $range) use ($node, $prefix, $suffix) {
            if ($range->start->line === 1) {
                $range->start->character += mb_strlen($prefix);
                $range->end->character += mb_strlen($suffix);
            }

            $range->start->line += $this->startLine + $node->position->startLine - 2;
            $range->end->line += $this->startLine + $node->position->startLine - 2;

            return $range;
        };

        $result = Parse::parse($sourceFile);

        if (count($result->children) === 0) {
            return;
        }

        $child = $result->children[0];

        $this->items[] = $child;
    }

    protected function parseLiteralNode(LiteralNode $node)
    {
        foreach ($this->echoStrings as $prefix => $suffix) {
            if (!str_starts_with($node->content, $prefix)) {
                continue;
            }

            $this->doEchoParse($node, $prefix, $node->content);
        }
    }

    protected function parseBladeDirective(DirectiveNode $node)
    {
        if ($node->isClosingDirective || !$node->hasArguments()) {
            return;
        }

        $methodUsed = '@' . $node->content;
        $safetyPrefix = 'directive';
        $snippet = "<?php\n" . str_repeat(' ', $node->getStartIndentationLevel()) . str_replace($methodUsed, $safetyPrefix . $node->content, $node->toString() . ';');

        $sourceFile = (new Parser)->parseSourceFile($snippet);

        Settings::$calculatePosition = function (Range $range) use ($node, $safetyPrefix) {
            if ($range->start->line === 1) {
                $range->start->character -= mb_strlen($safetyPrefix) - 1;
                $range->end->character -= mb_strlen($safetyPrefix) - 1;
            }

            $range->start->line += $this->startLine + $node->position->startLine - 2;
            $range->end->line += $this->startLine + $node->position->startLine - 2;

            return $range;
        };

        $result = Parse::parse($sourceFile);

        $child = $result->children[0];

        $child->methodName = '@' . substr($child->methodName, mb_strlen($safetyPrefix));

        $this->items[] = $child;
    }

    /**
     * An `@php` block holds plain PHP, but it reaches here as a directive without
     * arguments, so nothing used to look inside it. Its content is parsed as its own
     * source file, and every line it reports is moved back onto the Blade file.
     */
    protected function parsePhpBlock(PhpBlockNode $node)
    {
        $snippet = "<?php\n" . $node->innerContent;

        $sourceFile = (new Parser)->parseSourceFile($snippet);

        // `@php $a = 1; @endphp` puts its code on the directive's own line, where the
        // snippet lost everything written before it. A block opened on its own line
        // starts with a newline and needs no such correction.
        $inlineOffset = str_starts_with($node->innerContent, "\n")
            ? 0
            : $node->position->startColumn + 3;

        Settings::$calculatePosition = function (Range $range) use ($node, $inlineOffset) {
            if ($inlineOffset > 0 && $range->start->line === 1) {
                $range->start->character += $inlineOffset;
                $range->end->character += $inlineOffset;
            }

            $range->start->line += $this->startLine + $node->position->startLine - 2;
            $range->end->line += $this->startLine + $node->position->startLine - 2;

            return $range;
        };

        // A block holds statements, not a single expression, and a call can sit anywhere
        // inside one. Collecting them as they are visited is what the detect walker does
        // with plain PHP, and it keeps `$a = route('home');` visible.
        Parse::parse(
            node: $sourceFile,
            callback: function ($node, AbstractContext $context): void {
                if ($node instanceof CallExpression
                    || $node instanceof ObjectCreationExpression
                    || $node instanceof Attribute) {
                    $this->items[] = $context;
                }
            },
        );
    }

    /**
     * A component tag keeps its PHP in its parameters: `:title="__('key')"` holds a bare
     * expression, `title="{{ __('key') }}"` an echo. Neither reaches the document as a
     * node of its own, so each one is parsed as a snippet put back at its own column.
     */
    protected function parseComponentParameters(ComponentNode $node): void
    {
        foreach ($node->parameters as $parameter) {
            $valueNode = $parameter->valueNode;

            if ($valueNode === null || $valueNode->position === null) {
                continue;
            }

            // A raw echo leaves its parameter typed as plain, so every other value is
            // scanned for echoes rather than only the ones the parser calls interpolated.
            $expressions = $parameter->type === ParameterType::DynamicVariable
                ? [[$parameter->value, $this->quoteLength($valueNode->content)]]
                : $this->echoesIn($valueNode->content);

            foreach ($expressions as [$expression, $offset]) {
                $this->parseParameterExpression($valueNode, $expression, $offset);
            }
        }
    }

    /** The parameter value keeps its quotes, and the expression starts right after the opening one. */
    private function quoteLength(string $value): int
    {
        return str_starts_with($value, '"') || str_starts_with($value, "'") ? 1 : 0;
    }

    /**
     * Every echo of an attribute value with its offset in that value, `class="{{ $a }} {{ $b }}"`
     * giving two.
     *
     * @return array<int, array{string, int}>
     */
    private function echoesIn(string $value): array
    {
        preg_match_all('/\{\{(.*?)\}\}|\{!!(.*?)!!\}/s', $value, $matches, PREG_OFFSET_CAPTURE | PREG_UNMATCHED_AS_NULL);

        $expressions = [];

        foreach (array_keys($matches[0]) as $index) {
            [$expression, $offset] = $matches[1][$index][0] === null ? $matches[2][$index] : $matches[1][$index];

            $expressions[] = [$expression, $offset];
        }

        return $expressions;
    }

    protected function parseParameterExpression(ParameterAttribute $valueNode, string $expression, int $offset): void
    {
        $before = substr($valueNode->content, 0, $offset);
        $linesBefore = substr_count($before, "\n");

        // A value that spans lines puts the expression on a later line, counted from that
        // line's start rather than from the opening quote.
        $column = $linesBefore === 0
            ? $valueNode->position->startColumn - 1 + $offset
            : $offset - strrpos($before, "\n") - 1;
        $line = $valueNode->position->startLine + $linesBefore;

        $snippet = "<?php\n" . str_repeat(' ', $column) . $expression . ';';

        $sourceFile = (new Parser)->parseSourceFile($snippet);

        Settings::$calculatePosition = function (Range $range) use ($line) {
            $range->start->line += $this->startLine + $line - 2;
            $range->end->line += $this->startLine + $line - 2;

            return $range;
        };

        $result = Parse::parse($sourceFile);

        if (count($result->children) === 0) {
            return;
        }

        $this->items[] = $result->children[0];
    }

    protected function parseEchoNode(EchoNode $node)
    {
        $prefix = match ($node->type) {
            EchoType::RawEcho    => '{!!',
            EchoType::TripleEcho => '{{{',
            default              => '{{',
        };

        $this->doEchoParse($node, $prefix, $node->innerContent);
    }
}
