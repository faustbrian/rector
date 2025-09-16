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
use function mb_strlen;
use function mb_substr;
use function str_contains;
use function str_ends_with;

/**
 * Ensures all Specification classes end with "Specification"
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class StandardizeSpecificationNamingRector extends AbstractRector
{
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Ensure all Specification classes end with "Specification"',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
class ActiveUser implements SpecificationInterface
{
    // ...
}

class ValidEmailSpec implements SpecificationInterface
{
    // ...
}
CODE_SAMPLE
                    ,
                    <<<'CODE_SAMPLE'
class ActiveUserSpecification implements SpecificationInterface
{
    // ...
}

class ValidEmailSpecification implements SpecificationInterface
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
        // Only process Domain Specification classes
        if (!$this->isInSpecificationDirectory()) {
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

        if (str_ends_with($className, 'Specification')) {
            return null; // Already correct
        }

        $newName = self::standardizeSpecificationName($className);

        if ($newName !== $className) {
            $class->name = new Identifier($newName);

            return $class;
        }

        return null;
    }

    private static function refactorUseStatement(Use_ $use): ?Use_
    {
        $hasChanged = false;

        foreach ($use->uses as $useUse) {
            if ($useUse instanceof UseUse) {
                $name = $useUse->name->toString();

                // Only process if the use statement references a Specification
                if (str_contains($name, '\\Specification\\')) {
                    $parts = explode('\\', $name);
                    $className = end($parts);
                    $newClassName = self::standardizeSpecificationName($className);

                    if ($newClassName !== $className) {
                        $parts[count($parts) - 1] = $newClassName;
                        $newName = implode('\\', $parts);
                        $useUse->name = new Name($newName);

                        // Also update alias if it exists
                        if ($useUse->alias !== null) {
                            $aliasName = $useUse->alias->toString();
                            $newAliasName = self::standardizeSpecificationName($aliasName);

                            if ($newAliasName !== $aliasName) {
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

        $newName = self::standardizeSpecificationName($nameString);

        if ($newName !== $nameString) {
            return new Name($newName);
        }

        return null;
    }

    private static function standardizeSpecificationName(string $className): string
    {
        // Already ends with Specification
        if (str_ends_with($className, 'Specification')) {
            return $className;
        }

        // Replace common suffixes
        $replacements = [
            'Spec' => 'Specification',
            'Rule' => 'Specification', // Business rules are often specifications
        ];

        foreach ($replacements as $suffix => $replacement) {
            if (str_ends_with($className, $suffix)) {
                return mb_substr($className, 0, -mb_strlen($suffix)).$replacement;
            }
        }

        // Default: just append Specification
        return $className.'Specification';
    }

    private function isInSpecificationDirectory(): bool
    {
        $file = $this->file->getFilePath();

        return str_contains($file, '/Specification/')
               || str_contains($file, '\\Specification\\');
    }
}
