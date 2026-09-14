<?php

namespace App\Parsers;

use App\Contexts\AbstractContext;
use App\Contexts\AssignmentValue;
use App\Contexts\MethodCall;
use Microsoft\PhpParser\Node\Expression\CallExpression;
use Microsoft\PhpParser\Node\Expression\MemberAccessExpression;
use Microsoft\PhpParser\Node\Expression\ScopedPropertyAccessExpression;
use Microsoft\PhpParser\Node\Expression\Variable;
use Microsoft\PhpParser\Node\QualifiedName;

class MemberAccessExpressionParser extends AbstractParser
{
    /**
     * @var MethodCall
     */
    protected AbstractContext $context;

    public function parse(MemberAccessExpression $node)
    {
        $this->context->methodName = $node->memberName->getFullText($node->getRoot()->getFullText());
        $this->context->chain = $this->chainBefore($node);

        foreach ($node->getDescendantNodes() as $child) {
            if ($child instanceof QualifiedName) {
                $this->context->className ??= (string) ($child->getResolvedName() ?? $child->getText());

                return $this->context;
            }

            if ($child instanceof Variable) {
                if ($child->getName() === 'this') {
                    $parent = $child->getParent();

                    if ($parent?->getParent() instanceof CallExpression) {
                        // They are calling a method on the current class
                        $result = $this->context->nearestClassDefinition();

                        if ($result) {
                            $this->context->className = $result->className;
                        }

                        continue;
                    }

                    if ($parent instanceof MemberAccessExpression) {
                        $propName = $parent->memberName->getFullText($node->getRoot()->getFullText());

                        $result = $this->context->searchForProperty($propName);

                        if ($result) {
                            $this->context->className = $result['types'][0] ?? null;
                        }
                    }

                    continue;
                }

                $varName = $child->getName();

                $result = $this->context->searchForVar($varName);

                if (!$result) {
                    return $this->context;
                }

                if ($result instanceof AssignmentValue) {
                    $this->context->className = $result->getValue()['name'] ?? null;
                } else {
                    $this->context->className = $result;
                }
            }
        }

        return $this->context;
    }

    /**
     * Get the names of the calls chained between the receiver and this member access.
     *
     * The class resolved for `$order->customer()->update()` is the one of `$order`, so a
     * reader knowing what `customer()` returns needs to see it was called in between.
     *
     * @return array<int, string>
     */
    protected function chainBefore(MemberAccessExpression $node): array
    {
        $source = $node->getRoot()->getFullText();
        $chain = [];
        $receiver = $node->dereferencableExpression;

        while ($receiver instanceof CallExpression) {
            $callable = $receiver->callableExpression;

            if (!($callable instanceof MemberAccessExpression) && !($callable instanceof ScopedPropertyAccessExpression)) {
                break;
            }

            array_unshift($chain, trim($callable->memberName->getText($source)));

            $receiver = $callable instanceof MemberAccessExpression
                ? $callable->dereferencableExpression
                : $callable->scopeResolutionQualifier;
        }

        return $chain;
    }

    public function initNewContext(): ?AbstractContext
    {
        if (!($this->context instanceof MethodCall) || $this->context->methodName !== null) {
            return new MethodCall;
        }

        return null;
    }
}
