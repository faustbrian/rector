<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Rector;

use Node\Identifier;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\Stmt\UseUse;
use Rector\Core\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

use function str_contains;
use function str_ends_with;
use function str_replace;

/**
 * Renames AggregateRoot classes to Aggregate for consistency
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class AggregateRootToAggregateRector extends AbstractRector
{
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Rename AggregateRoot classes to Aggregate for consistency',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
class OrderAggregateRoot extends AbstractAggregateRoot
{
    // ...
}
CODE_SAMPLE
                    ,
                    <<<'CODE_SAMPLE'
class OrderAggregate extends AbstractAggregateRoot
{
    // ...
}
CODE_SAMPLE
                ),
            ],
        );
    }

    public function getNodeTypes(): array
    {
        return [Class_::class, Use_::class, Name::class];
    }

    public function refactor(Node $node): ?Node
    {
        if ($node instanceof Class_) {
            return $this->refactorClass($node);
        }

        if ($node instanceof Use_) {
            return self::refactorUseStatement($node);
        }

        if ($node instanceof Name) {
            return self::refactorNameReference($node);
        }

        return null;
    }

    private static function refactorUseStatement(Use_ $use): ?Use_
    {
        $hasChanged = false;

        foreach ($use->uses as $useUse) {
            if ($useUse instanceof UseUse) {
                $name = $useUse->name->toString();

                if (str_ends_with($name, 'AggregateRoot') && str_contains($name, '\\Domain\\Aggregate\\')) {
                    $newName = str_replace('AggregateRoot', 'Aggregate', $name);
                    $useUse->name = new Name($newName);

                    // Also update alias if it exists
                    if ($useUse->alias !== null) {
                        $aliasName = $useUse->alias->toString();

                        if (str_ends_with($aliasName, 'AggregateRoot')) {
                            $newAliasName = str_replace('AggregateRoot', 'Aggregate', $aliasName);
                            $useUse->alias = new Identifier($newAliasName);
                        }
                    }

                    $hasChanged = true;
                }
            }
        }

        return $hasChanged ? $use : null;
    }

    private static function refactorNameReference(Name $name): ?Name
    {
        $nameString = $name->toString();

        // Only refactor if it's likely an aggregate reference
        if (!str_ends_with($nameString, 'AggregateRoot')) {
            return null;
        }

        // Skip if it's AbstractAggregateRoot (base class)
        if ($nameString === 'AbstractAggregateRoot') {
            return null;
        }

        $newName = str_replace('AggregateRoot', 'Aggregate', $nameString);

        return new Name($newName);
    }

    private function refactorClass(Class_ $class): ?Class_
    {
        if ($class->name === null) {
            return null;
        }

        $className = $class->name->toString();

        if (!str_ends_with($className, 'AggregateRoot')) {
            return null;
        }

        // Only process if it's in a Domain/Aggregate directory
        if (!$this->isInDomainAggregateDirectory()) {
            return null;
        }

        $newName = str_replace('AggregateRoot', 'Aggregate', $className);
        $class->name = new Identifier($newName);

        return $class;
    }

    private function isInDomainAggregateDirectory(): bool
    {
        $file = $this->file->getFilePath();

        return str_contains($file, '/Domain/Aggregate/')
               || str_contains($file, '\\Domain\\Aggregate\\');
    }
}
