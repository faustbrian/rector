<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Rector;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use Rector\Core\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

use function str_contains;

/**
 * Removes Domain Actions in favor of Commands and Aggregate methods
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class RemoveDomainActionsRector extends AbstractRector
{
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Remove Domain Actions in favor of Commands and Aggregate methods',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
// Domain/Action/CreateOrder.php
class CreateOrder
{
    public function handle(): void
    {
        // Action logic
    }
}
CODE_SAMPLE
                    ,
                    <<<'CODE_SAMPLE'
// This file should be removed and logic moved to:
// 1. Application/Command/CreateOrderCommand.php
// 2. OrderAggregate methods
CODE_SAMPLE
                ),
            ],
        );
    }

    public function getNodeTypes(): array
    {
        return [Class_::class];
    }

    public function refactor(Node $node): ?Node
    {
        if (!$node instanceof Class_) {
            return null;
        }

        // Only process if it's in a Domain/Action directory
        if (!$this->isInDomainActionDirectory()) {
            return null;
        }

        // Add comment suggesting removal
        $this->file->addError(
            'Domain Actions should be removed. Move logic to Application Commands or Aggregate methods.',
            $node->getLine(),
        );

        return null;
    }

    private function isInDomainActionDirectory(): bool
    {
        $file = $this->file->getFilePath();

        return str_contains($file, '/Domain/Action/')
               || str_contains($file, '\\Domain\\Action\\');
    }
}
