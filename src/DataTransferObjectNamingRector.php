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
 * Standardizes DTO naming to use "Data" suffix consistently
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class DataTransferObjectNamingRector extends AbstractRector
{
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Standardize DTO naming to use "Data" suffix consistently in DataTransferObject folders',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
class OrderDataTransferObject
{
    // ...
}

class CreateOrderDTO
{
    // ...
}
CODE_SAMPLE
                    ,
                    <<<'CODE_SAMPLE'
class OrderData
{
    // ...
}

class CreateOrderData
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
            return $this->refactorUseStatement($node);
        }

        if ($node instanceof Name) {
            return $this->refactorNameReference($node);
        }

        return null;
    }

    private function refactorClass(Class_ $class): ?Class_
    {
        if ($class->name === null) {
            return null;
        }

        $className = $class->name->toString();

        // Only process if it's in a DataTransferObject directory
        if (!$this->isInDataTransferObjectDirectory()) {
            return null;
        }

        $newName = $this->standardizeDataObjectName($className);

        if ($newName !== $className) {
            $class->name = new Identifier($newName);

            return $class;
        }

        return null;
    }

    private function refactorUseStatement(Use_ $use): ?Use_
    {
        $hasChanged = false;

        foreach ($use->uses as $useUse) {
            if ($useUse instanceof UseUse) {
                $name = $useUse->name->toString();

                // Only process if the use statement references a DataTransferObject
                if (str_contains($name, '\\DataTransferObject\\')) {
                    $parts = explode('\\', $name);
                    $className = end($parts);
                    $newClassName = $this->standardizeDataObjectName($className);

                    if ($newClassName !== $className) {
                        $parts[count($parts) - 1] = $newClassName;
                        $newName = implode('\\', $parts);
                        $useUse->name = new Name($newName);

                        // Also update alias if it exists
                        if ($useUse->alias !== null) {
                            $aliasName = $useUse->alias->toString();
                            $newAliasName = $this->standardizeDataObjectName($aliasName);

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

    private function refactorNameReference(Name $name): ?Name
    {
        $nameString = $name->toString();

        // Only process simple class names that need standardization
        if (str_contains($nameString, '\\')) {
            return null;
        }

        $newName = $this->standardizeDataObjectName($nameString);

        if ($newName !== $nameString) {
            return new Name($newName);
        }

        return null;
    }

    private function standardizeDataObjectName(string $className): string
    {
        // Remove DataTransferObject suffix and replace with Data
        if (str_ends_with($className, 'DataTransferObject')) {
            return mb_substr($className, 0, -18).'Data';
        }

        // Replace DTO suffix with Data
        if (str_ends_with($className, 'DTO')) {
            return mb_substr($className, 0, -3).'Data';
        }

        // If already ends with Data, keep as is
        if (str_ends_with($className, 'Data')) {
            return $className;
        }

        // For other cases in DataTransferObject directory, ensure Data suffix
        if ($this->isInDataTransferObjectDirectory() && !str_ends_with($className, 'Data')) {
            return $className.'Data';
        }

        return $className;
    }

    private function isInDataTransferObjectDirectory(): bool
    {
        $file = $this->file->getFilePath();

        return str_contains($file, '/DataTransferObject/')
               || str_contains($file, '\\DataTransferObject\\');
    }
}
