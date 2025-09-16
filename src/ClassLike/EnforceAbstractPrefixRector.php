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
use function str_starts_with;

/**
 * Enforces Abstract prefix for abstract classes following traditional naming conventions.
 *
 * While modern practices are moving away from the 'Abstract' prefix, some teams and
 * projects prefer explicit naming conventions. This rector ensures all abstract classes
 * have an 'Abstract' prefix for consistency with traditional PHP naming conventions.
 *
 * @see https://www.php-fig.org/bylaws/psr-naming-conventions/
 *
 * Examples:
 * - AggregateRoot -> AbstractAggregateRoot
 * - Entity -> AbstractEntity
 * - ValueObject -> AbstractValueObject
 *
 * This rector also updates all references across the codebase and renames files to match.
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class EnforceAbstractPrefixRector extends AbstractRector
{
    private const string ABSTRACT_PREFIX = 'Abstract';

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
            'Enforce Abstract prefix for abstract classes following traditional naming conventions (https://www.php-fig.org/bylaws/psr-naming-conventions/)',
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

        // Only process abstract classes
        if (!$node->isAbstract()) {
            return null;
        }

        $oldShortName = $node->name instanceof Identifier ? $node->name->toString() : null;

        if (!is_string($oldShortName)) {
            return null;
        }

        // Skip if already has Abstract prefix
        if (str_starts_with($oldShortName, self::ABSTRACT_PREFIX)) {
            return null;
        }

        $newShortName = self::ABSTRACT_PREFIX.$oldShortName;
        $this->renameClassLike($node, $oldShortName, $newShortName);

        return $node;
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
