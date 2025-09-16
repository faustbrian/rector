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
 * Removes redundant "Value" suffix from Value Objects
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class RemoveValueSuffixFromValueObjectsRector extends AbstractRector
{
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Remove redundant "Value" suffix from Value Objects as they represent the value directly',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
class MoneyValue
{
    // ...
}

class EmailValue
{
    // ...
}
CODE_SAMPLE
                    ,
                    <<<'CODE_SAMPLE'
class Money
{
    // ...
}

class Email
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

                // Only process if the use statement references a ValueObject
                if (str_contains($name, '\\ValueObject\\')) {
                    $parts = explode('\\', $name);
                    $className = end($parts);

                    if (str_ends_with($className, 'Value') && !str_ends_with($className, 'Id')) {
                        $newClassName = mb_substr($className, 0, -5);
                        $parts[count($parts) - 1] = $newClassName;
                        $newName = implode('\\', $parts);
                        $useUse->name = new Name($newName);

                        // Also update alias if it exists
                        if ($useUse->alias !== null) {
                            $aliasName = $useUse->alias->toString();

                            if (str_ends_with($aliasName, 'Value') && !str_ends_with($aliasName, 'Id')) {
                                $newAliasName = mb_substr($aliasName, 0, -5);
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

        // Remove "Value" suffix but keep "Id" suffix for identity VOs
        if (str_ends_with($nameString, 'Value') && !str_ends_with($nameString, 'Id')) {
            $newName = mb_substr($nameString, 0, -5);

            return new Name($newName);
        }

        return null;
    }

    private function refactorClass(Class_ $class): ?Class_
    {
        if ($class->name === null) {
            return null;
        }

        $className = $class->name->toString();

        // Only process if it's in a ValueObject directory
        if (!$this->isInValueObjectDirectory()) {
            return null;
        }

        // Remove "Value" suffix but keep "Id" suffix for identity VOs
        if (str_ends_with($className, 'Value') && !str_ends_with($className, 'Id')) {
            $newName = mb_substr($className, 0, -5);
            $class->name = new Identifier($newName);

            return $class;
        }

        return null;
    }

    private function isInValueObjectDirectory(): bool
    {
        $file = $this->file->getFilePath();

        return str_contains($file, '/ValueObject/')
               || str_contains($file, '\\ValueObject\\');
    }
}
