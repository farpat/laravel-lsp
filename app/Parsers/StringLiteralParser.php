<?php

namespace App\Parsers;

use App\Contexts\AbstractContext;
use App\Contexts\StringValue;
use App\Lsp\Support\Position;
use App\Parser\Settings;
use Microsoft\PhpParser\Node;
use Microsoft\PhpParser\Node\StringLiteral;
use Microsoft\PhpParser\LineCharacterPosition;
use Microsoft\PhpParser\Range;
use Microsoft\PhpParser\TokenKind;

class StringLiteralParser extends AbstractParser
{
    /**
     * @var StringValue
     */
    protected AbstractContext $context;

    public function parse(StringLiteral $node)
    {
        $contents = $this->contents($node);

        $this->context->value = $contents;
        $this->context->interpolated = $this->isInterpolated($node);

        if (Settings::$capturePosition) {
            $range = $this->getRange($node, $contents);

            if (Settings::$calculatePosition !== null) {
                $range = Settings::adjustPosition($range);
            }

            $this->context->setPosition($range);
        }

        return $this->context;
    }

    /**
     * Get the range of the string, from its opening quote, in UTF-16 units as LSP counts.
     *
     * The parser counts bytes, so `é` or an emoji before the string on the same line
     * pushed the range to the right.
     */
    protected function getRange(StringLiteral $node, string $contents): Range
    {
        $text = $node->getRoot()->getFullText();
        $start = Position::fromOffset($text, $node->getStartPosition());
        $end = str_contains($contents, "\n")
            ? Position::fromOffset($text, $node->getStartPosition() + strlen($contents))
            : ['line' => $start['line'], 'character' => $start['character'] + Position::countCharacters($contents)];

        return new Range(
            new LineCharacterPosition($start['line'], $start['character']),
            new LineCharacterPosition($end['line'], $end['character']),
        );
    }

    /**
     * Get the contents of the string.
     *
     * A heredoc or nowdoc body ends with the newline that precedes its
     * closing identifier, which PHP does not include in the value.
     */
    protected function contents(StringLiteral $node): string
    {
        $contents = $node->getStringContentsText();

        if (($node->startQuote->kind ?? null) !== TokenKind::HeredocStart) {
            return $contents;
        }

        return preg_replace('/\r?\n$/', '', $contents);
    }

    /**
     * Determine if the string contains an interpolated expression.
     *
     * The contents are read straight from the source, so an interpolated
     * string yields the expression verbatim rather than its value. Only
     * interpolation produces child nodes; escaped dollars, single quoted
     * strings and nowdocs are plain tokens.
     */
    protected function isInterpolated(StringLiteral $node): bool
    {
        if (!is_array($node->children)) {
            return false;
        }

        foreach ($node->children as $child) {
            if ($child instanceof Node) {
                return true;
            }
        }

        return false;
    }

    public function initNewContext(): ?AbstractContext
    {
        return new StringValue;
    }
}
