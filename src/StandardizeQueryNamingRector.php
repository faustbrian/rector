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

use function array_key_exists;
use function count;
use function end;
use function explode;
use function implode;
use function preg_match;
use function str_contains;
use function str_ends_with;
use function str_starts_with;

/**
 * Standardizes Query naming to start with "Get" and end with "Query"
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class StandardizeQueryNamingRector extends AbstractRector
{
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Standardize Query naming to start with "Get" and end with "Query"',
            [
                new CodeSample(
                    <<<'CODE_SAMPLE'
class ValidateUserHierarchyQuery
{
    // ...
}

class CalculateRateQuery
{
    // ...
}
CODE_SAMPLE
                    ,
                    <<<'CODE_SAMPLE'
class GetValidateUserHierarchyQuery
{
    // ...
}

class GetCalculateRateQuery
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
        // Only process Application Query classes
        if (!$this->isInApplicationQueryDirectory()) {
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

        if (!str_ends_with($className, 'Query')) {
            return null;
        }

        $newName = self::standardizeQueryName($className);

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

                // Only process if the use statement references a Query
                if (str_contains($name, '\\Application\\Query\\')) {
                    $parts = explode('\\', $name);
                    $className = end($parts);
                    $newClassName = self::standardizeQueryName($className);

                    if ($newClassName !== $className) {
                        $parts[count($parts) - 1] = $newClassName;
                        $newName = implode('\\', $parts);
                        $useUse->name = new Name($newName);

                        // Also update alias if it exists
                        if ($useUse->alias !== null) {
                            $aliasName = $useUse->alias->toString();
                            $newAliasName = self::standardizeQueryName($aliasName);

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

        // Only process simple class names that are queries
        if (str_contains($nameString, '\\')) {
            return null;
        }

        if (!str_ends_with($nameString, 'Query')) {
            return null;
        }

        $newName = self::standardizeQueryName($nameString);

        if ($newName !== $nameString) {
            return new Name($newName);
        }

        return null;
    }

    private static function standardizeQueryName(string $className): string
    {
        if (!str_ends_with($className, 'Query')) {
            return $className;
        }

        // Already follows convention
        if (str_starts_with($className, 'Get')) {
            return $className;
        }

        // Special cases that should remain as is
        $specialCases = [
            'ValidateUserHierarchyQuery' => 'GetUserHierarchyValidationQuery',
            'ValidateMetadataQuery' => 'GetMetadataValidationQuery',
            'CalculateRateQuery' => 'GetRateCalculationQuery',
            'CalculateBulkRatesQuery' => 'GetBulkRatesCalculationQuery',
            'IsAssignedToUserQuery' => 'GetUserAssignmentQuery',
            'IsAssignedToTeamQuery' => 'GetTeamAssignmentQuery',
            'IsAssignedToOrganizationQuery' => 'GetOrganizationAssignmentQuery',
            'CheckBusinessUnitAssignmentLimitQuery' => 'GetBusinessUnitAssignmentLimitQuery',
        ];

        if (array_key_exists($className, $specialCases)) {
            return $specialCases[$className];
        }

        // For verbs like "Validate", "Calculate", "Check", "Is" - convert to "Get{Noun}Query"
        if (preg_match('/^(Validate|Calculate|Check|Is)(.+)Query$/', $className, $matches)) {
            $verb = $matches[1];
            $noun = $matches[2];

            return match ($verb) {
                'Validate' => 'Get'.$noun.'ValidationQuery',
                'Calculate' => 'Get'.$noun.'CalculationQuery',
                'Check' => 'Get'.$noun.'CheckQuery',
                'Is' => 'Get'.$noun.'StatusQuery',
                default => 'Get'.$className,
            };
        }

        // Default: just prepend "Get"
        return 'Get'.$className;
    }

    private function isInApplicationQueryDirectory(): bool
    {
        $file = $this->file->getFilePath();

        return str_contains($file, '/Application/Query/')
               || str_contains($file, '\\Application\\Query\\');
    }
}
