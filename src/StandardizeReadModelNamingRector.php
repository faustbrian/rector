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

use function count;
use function end;
use function explode;
use function implode;
use function mb_substr;
use function str_contains;
use function str_ends_with;

/**
 * Standardizes ReadModel naming - removes redundant "ReadModel" suffix when obvious
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class StandardizeReadModelNamingRector extends AbstractRector
{
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Standardize ReadModel naming - remove redundant "ReadModel" suffix when context is obvious',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
// In Application/ReadModel/ directory
class CircuitBreakerReadModel
{
    // ...
}
CODE_SAMPLE
                    ,
                    <<<'CODE_SAMPLE'
// In Application/ReadModel/ directory
class CircuitBreaker
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
        // Only process ReadModel classes
        if (!$this->isInReadModelDirectory()) {
            return null;
        }

        if ($node instanceof Class_) {
            return self::refactorClass($node);
        }

        if ($node instanceof Use_) {
            return self::refactorUseStatement($node);
        }

        if ($node instanceof Name) {
            return self::refactorNameReference($node);
        }

        return null;
    }

    private static function refactorClass(Class_ $class): ?Class_
    {
        if ($class->name === null) {
            return null;
        }

        $className = $class->name->toString();

        if (!str_ends_with($className, 'ReadModel')) {
            return null;
        }

        // Remove ReadModel suffix since directory context makes it clear
        $newName = mb_substr($className, 0, -9); // Remove "ReadModel"
        $class->name = new Identifier($newName);

        return $class;
    }

    private static function refactorUseStatement(Use_ $use): ?Use_
    {
        $hasChanged = false;

        foreach ($use->uses as $useUse) {
            if ($useUse instanceof UseUse) {
                $name = $useUse->name->toString();

                // Only process if the use statement references a ReadModel
                if (str_contains($name, '\\ReadModel\\')) {
                    $parts = explode('\\', $name);
                    $className = end($parts);

                    if (str_ends_with($className, 'ReadModel')) {
                        $newClassName = mb_substr($className, 0, -9);
                        $parts[count($parts) - 1] = $newClassName;
                        $newName = implode('\\', $parts);
                        $useUse->name = new Name($newName);

                        // Also update alias if it exists
                        if ($useUse->alias !== null) {
                            $aliasName = $useUse->alias->toString();

                            if (str_ends_with($aliasName, 'ReadModel')) {
                                $newAliasName = mb_substr($aliasName, 0, -9);
                                $useUse->alias = new Identifier($newAliasName);
                            }
                        }

                        $hasChanged = true;
                    }
                }
            }
        }

        return $hasChanged ? $use : null;
    }

    private static function refactorNameReference(Name $name): ?Name
    {
        $nameString = $name->toString();

        // Only process simple class names
        if (str_contains($nameString, '\\')) {
            return null;
        }

        if (!str_ends_with($nameString, 'ReadModel')) {
            return null;
        }

        $newName = mb_substr($nameString, 0, -9);

        return new Name($newName);
    }

    private function isInReadModelDirectory(): bool
    {
        $file = $this->file->getFilePath();

        return str_contains($file, '/ReadModel/')
               || str_contains($file, '\\ReadModel\\');
    }
}
