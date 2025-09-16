<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Rector\ClassLike;

use Override;
use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Namespace_;
use Rector\Configuration\Option;
use Rector\Configuration\Parameter\SimpleParameterProvider;
use Rector\Configuration\RenamedClassesDataCollector;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

use const DIRECTORY_SEPARATOR;

use function dirname;
use function file_exists;
use function is_dir;
use function is_string;
use function mb_rtrim;
use function mkdir;
use function register_shutdown_function;
use function rename;
use function str_ends_with;

/**
 * Enforces Exception suffix naming convention following PSR naming conventions.
 *
 * Following PHP-FIG PSR naming conventions and universal PHP standards, all exceptions
 * should have an 'Exception' suffix for clarity. This is a universal convention across
 * all programming languages and frameworks.
 *
 * @see https://www.php-fig.org/bylaws/psr-naming-conventions/
 *
 * Examples:
 * - ValidationFailed -> ValidationFailedException
 * - UserNotFound -> UserNotFoundException
 * - InvalidArgument -> InvalidArgumentException
 *
 * This rector also updates all references across the codebase and renames files to match.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class EnforceExceptionSuffixRector extends AbstractRector
{
    private const string EXCEPTION_SUFFIX = 'Exception';

    /** @var array<string, string> old absolute path => new absolute path */
    private static array $plannedRenames = [];

    private static bool $shutdownRegistered = false;

    public function __construct(
        private readonly RenamedClassesDataCollector $renamedClassesDataCollector,
    ) {
        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;

            register_shutdown_function(static function (): void {
                $isDryRun = SimpleParameterProvider::provideBoolParameter(name: Option::DRY_RUN, default: false);

                if ($isDryRun) {
                    self::$plannedRenames = [];

                    return;
                }

                foreach (self::$plannedRenames as $from => $to) {
                    $targetDir = dirname($to);

                    if (!is_dir($targetDir)) {
                        mkdir($targetDir, 0o777, true);
                    }

                    if (file_exists($to)) {
                        continue;
                    }

                    rename($from, $to);
                }

                self::$plannedRenames = [];
            });
        }
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Enforce Exception suffix following PSR naming conventions (https://www.php-fig.org/bylaws/psr-naming-conventions/)',
            [],
        );
    }

    /**
     * @return array<class-string<Node>>
     */
    #[Override()]
    public function getNodeTypes(): array
    {
        return [Class_::class];
    }

    #[Override()]
    public function refactor(Node $node)
    {
        if (!$node instanceof Class_) {
            return null;
        }

        // Only process classes that extend an Exception
        if (!$this->isExceptionClass($node)) {
            return null;
        }

        $oldShortName = $node->name instanceof Identifier ? $node->name->toString() : null;

        if (!is_string($oldShortName)) {
            return null;
        }

        if (str_ends_with($oldShortName, self::EXCEPTION_SUFFIX)) {
            return null;
        }

        $newShortName = $oldShortName.self::EXCEPTION_SUFFIX;
        $this->renameClassLike($node, $oldShortName, $newShortName);

        return $node;
    }

    private function isExceptionClass(Class_ $class): bool
    {
        $extends = $class->extends;

        if (!$extends instanceof Name) {
            return false;
        }

        $parentName = $this->getName($extends) ?? '';

        if (!is_string($parentName)) {
            return false;
        }

        // Check if parent ends with Exception or is the base Exception/Throwable
        return str_ends_with($parentName, self::EXCEPTION_SUFFIX)
            || $parentName === 'Exception'
            || $parentName === 'Throwable'
            || str_ends_with($parentName, '\\Exception')
            || str_ends_with($parentName, '\\Throwable');
    }

    private function renameClassLike(Class_ $node, string $oldShortName, string $newShortName): void
    {
        // 1) Rename the symbol in-place
        $node->name = new Identifier($newShortName);

        // 2) Announce class rename to update references across codebase
        $namespaceName = $this->fileNamespacePrefix();
        $oldFqn = $namespaceName.$oldShortName;
        $newFqn = $namespaceName.$newShortName;
        $this->renamedClassesDataCollector->addOldToNewClasses([$oldFqn => $newFqn]);

        // 3) Plan file rename to keep PSR-4 mapping
        $oldPath = $this->file->getFilePath();
        $dir = dirname($oldPath);
        $newPath = $dir.DIRECTORY_SEPARATOR.$newShortName.'.php';

        if ($newPath !== $oldPath) {
            self::$plannedRenames[$oldPath] = $newPath;
        }
    }

    private function fileNamespacePrefix(): string
    {
        $namespace = '';
        $oldStmts = $this->file->getOldStmts();

        foreach ($oldStmts as $stmt) {
            if ($stmt instanceof Namespace_ && $stmt->name instanceof Name) {
                $namespace = $stmt->name->toString();

                break;
            }
        }

        return $namespace !== '' ? mb_rtrim($namespace, '\\').'\\' : '';
    }
}
