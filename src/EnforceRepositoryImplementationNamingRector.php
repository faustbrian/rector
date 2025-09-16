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
use function str_contains;
use function str_ends_with;
use function str_starts_with;

/**
 * Enforces Repository implementation naming with technology prefix
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class EnforceRepositoryImplementationNamingRector extends AbstractRector
{
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Enforce Repository implementation naming with technology prefix (e.g., EloquentUserRepository)',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
class UserRepository implements UserRepositoryInterface
{
    // ...
}
CODE_SAMPLE
                    ,
                    <<<'CODE_SAMPLE'
class EloquentUserRepository implements UserRepositoryInterface
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
        // Only process Infrastructure Repository implementations
        if (!$this->isInInfrastructureRepositoryDirectory()) {
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

        if (!str_ends_with($className, 'Repository')) {
            return null;
        }

        // Skip if already has technology prefix
        if (self::hasTechnologyPrefix($className)) {
            return null;
        }

        // Add Eloquent prefix (most common in Laravel)
        $newName = 'Eloquent'.$className;
        $class->name = new Identifier($newName);

        return $class;
    }

    private static function refactorUseStatement(Use_ $use): ?Use_
    {
        $hasChanged = false;

        foreach ($use->uses as $useUse) {
            if ($useUse instanceof UseUse) {
                $name = $useUse->name->toString();

                // Only process if the use statement references an Infrastructure Repository
                if (str_contains($name, '\\Infrastructure\\Repository\\')) {
                    $parts = explode('\\', $name);
                    $className = end($parts);

                    if (str_ends_with($className, 'Repository') && !self::hasTechnologyPrefix($className)) {
                        $newClassName = 'Eloquent'.$className;
                        $parts[count($parts) - 1] = $newClassName;
                        $newName = implode('\\', $parts);
                        $useUse->name = new Name($newName);

                        // Also update alias if it exists
                        if ($useUse->alias !== null) {
                            $aliasName = $useUse->alias->toString();

                            if (str_ends_with($aliasName, 'Repository') && !self::hasTechnologyPrefix($aliasName)) {
                                $newAliasName = 'Eloquent'.$aliasName;
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

        if (!str_ends_with($nameString, 'Repository')) {
            return null;
        }

        if (self::hasTechnologyPrefix($nameString)) {
            return null;
        }

        $newName = 'Eloquent'.$nameString;

        return new Name($newName);
    }

    private static function hasTechnologyPrefix(string $className): bool
    {
        $technologyPrefixes = [
            'Eloquent',
            'Redis',
            'Memory',
            'InMemory',
            'File',
            'Database',
            'Cache',
            'Cached',
            'Mock',
            'Fake',
            'Stub',
            'Null',
        ];

        foreach ($technologyPrefixes as $prefix) {
            if (str_starts_with($className, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function isInInfrastructureRepositoryDirectory(): bool
    {
        $file = $this->file->getFilePath();

        return str_contains($file, '/Infrastructure/Repository/')
               || str_contains($file, '\\Infrastructure\\Repository\\');
    }
}
