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
use PhpParser\Node\Stmt\Interface_;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\Stmt\UseUse;
use Rector\Core\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

use function str_contains;
use function str_ends_with;
use function str_replace;

/**
 * Renames Directory interfaces to Provider interfaces in SharedKernel
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class DirectoryToProviderRector extends AbstractRector
{
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Rename Directory interfaces to Provider interfaces in SharedKernel following Laravel conventions',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
interface UserDirectoryInterface
{
    public function findById(UserId $id): ?User;
}
CODE_SAMPLE
                    ,
                    <<<'CODE_SAMPLE'
interface UserProviderInterface
{
    public function findById(UserId $id): ?User;
}
CODE_SAMPLE
                ),
            ],
        );
    }

    public function getNodeTypes(): array
    {
        return [Interface_::class, Use_::class, Name::class];
    }

    public function refactor(Node $node): ?Node
    {
        // Only process SharedKernel Domain Contracts
        if (!$this->isInSharedKernelDomainContract()) {
            return null;
        }

        if ($node instanceof Interface_) {
            return self::refactorInterface($node);
        }

        if ($node instanceof Use_) {
            return self::refactorUseStatement($node);
        }

        if ($node instanceof Name) {
            return self::refactorNameReference($node);
        }

        return null;
    }

    private static function refactorInterface(Interface_ $interface): ?Interface_
    {
        if ($interface->name === null) {
            return null;
        }

        $className = $interface->name->toString();

        if (!str_ends_with($className, 'DirectoryInterface')) {
            return null;
        }

        $newName = str_replace('DirectoryInterface', 'ProviderInterface', $className);
        $interface->name = new Identifier($newName);

        return $interface;
    }

    private static function refactorUseStatement(Use_ $use): ?Use_
    {
        $hasChanged = false;

        foreach ($use->uses as $useUse) {
            if ($useUse instanceof UseUse) {
                $name = $useUse->name->toString();

                if (str_ends_with($name, 'DirectoryInterface')) {
                    $newName = str_replace('DirectoryInterface', 'ProviderInterface', $name);
                    $useUse->name = new Name($newName);

                    // Also update alias if it exists
                    if ($useUse->alias !== null) {
                        $aliasName = $useUse->alias->toString();

                        if (str_ends_with($aliasName, 'DirectoryInterface')) {
                            $newAliasName = str_replace('DirectoryInterface', 'ProviderInterface', $aliasName);
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

        if (!str_ends_with($nameString, 'DirectoryInterface')) {
            return null;
        }

        $newName = str_replace('DirectoryInterface', 'ProviderInterface', $nameString);

        return new Name($newName);
    }

    private function isInSharedKernelDomainContract(): bool
    {
        $file = $this->file->getFilePath();

        return str_contains($file, '/SharedKernel/Domain/Contract/')
               || str_contains($file, '\\SharedKernel\\Domain\\Contract\\');
    }
}
