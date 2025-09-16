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
 * Removes redundant service suffixes (DomainService, ApplicationService)
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class RemoveRedundantServiceSuffixRector extends AbstractRector
{
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Remove redundant service suffixes as namespace provides layer context',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
class OrderApplicationService
{
    // ...
}

class MoneyConversionDomainService
{
    // ...
}
CODE_SAMPLE
                    ,
                    <<<'CODE_SAMPLE'
class OrderService
{
    // ...
}

class MoneyConversionService
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

                // Only process if the use statement references a Service
                if (str_contains($name, '\\Service\\')) {
                    $parts = explode('\\', $name);
                    $className = end($parts);
                    $newClassName = self::removeRedundantServiceSuffix($className);

                    if ($newClassName !== $className) {
                        $parts[count($parts) - 1] = $newClassName;
                        $newName = implode('\\', $parts);
                        $useUse->name = new Name($newName);

                        // Also update alias if it exists
                        if ($useUse->alias !== null) {
                            $aliasName = $useUse->alias->toString();
                            $newAliasName = self::removeRedundantServiceSuffix($aliasName);

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

        $newName = self::removeRedundantServiceSuffix($nameString);

        if ($newName !== $nameString) {
            return new Name($newName);
        }

        return null;
    }

    private static function removeRedundantServiceSuffix(string $className): string
    {
        // Remove DomainService suffix and replace with Service
        if (str_ends_with($className, 'DomainService')) {
            return mb_substr($className, 0, -13).'Service';
        }

        // Remove ApplicationService suffix and replace with Service
        if (str_ends_with($className, 'ApplicationService')) {
            return mb_substr($className, 0, -18).'Service';
        }

        return $className;
    }

    private function refactorClass(Class_ $class): ?Class_
    {
        if ($class->name === null) {
            return null;
        }

        $className = $class->name->toString();

        // Only process if it's in a Service directory
        if (!$this->isInServiceDirectory()) {
            return null;
        }

        $newName = self::removeRedundantServiceSuffix($className);

        if ($newName !== $className) {
            $class->name = new Identifier($newName);

            return $class;
        }

        return null;
    }

    private function isInServiceDirectory(): bool
    {
        $file = $this->file->getFilePath();

        return str_contains($file, '/Service/')
               || str_contains($file, '\\Service\\');
    }
}
