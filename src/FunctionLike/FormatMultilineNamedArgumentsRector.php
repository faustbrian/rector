<?php declare(strict_types=1);

/**
 * Copyright (C) Brian Faust
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Cline\Rector\FunctionLike;

use Override;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

use function array_any;
use function count;

/**
 * Formats named arguments with 3+ parameters to be multiline for improved readability.
 *
 * This rector transforms single-line named argument calls to multiline format when:
 * - The call has 3 or more named arguments
 * - At least one argument uses named parameter syntax
 *
 * Benefits:
 * - Improved readability for complex constructor calls
 * - Consistent formatting for named arguments
 * - Easier code reviews and diffs
 * - Better alignment with PSR-12 style guidelines
 *
 * Examples:
 * - new User(firstName: 'John', lastName: 'Doe', isActive: true)
 *   becomes:
 *   new User(
 *       firstName: 'John',
 *       lastName: 'Doe',
 *       isActive: true
 *   )
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class FormatMultilineNamedArgumentsRector extends AbstractRector
{
    private const int MIN_ARGUMENTS_FOR_MULTILINE = 3;

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            description: 'Format named arguments with 3+ parameters to multiline',
            codeSamples: [
                new CodeSample(
                    badCode: <<<'CODE_SAMPLE'
<?php

class CollectionEscalationData
{
    public function __construct(
        public string $collectionCaseId,
        public bool $shouldEscalate,
        public EscalationLevel $currentEscalationLevel,
    ) {}

    public static function escalate(string $caseId, EscalationLevel $level): self
    {
        return new self(collectionCaseId: $caseId, shouldEscalate: true, currentEscalationLevel: $level);
    }
}
CODE_SAMPLE
                    ,
                    goodCode: <<<'CODE_SAMPLE'
<?php

class CollectionEscalationData
{
    public function __construct(
        public string $collectionCaseId,
        public bool $shouldEscalate,
        public EscalationLevel $currentEscalationLevel,
    ) {}

    public static function escalate(string $caseId, EscalationLevel $level): self
    {
        return new self(
            collectionCaseId: $caseId,
            shouldEscalate: true,
            currentEscalationLevel: $level
        );
    }
}
CODE_SAMPLE
                ),
            ],
        );
    }

    /**
     * @return array<class-string<Node>>
     */
    #[Override()]
    public function getNodeTypes(): array
    {
        return [New_::class, StaticCall::class, MethodCall::class];
    }

    #[Override()]
    public function refactor(Node $node)
    {
        if ($node instanceof New_) {
            return self::refactorNew($node);
        }

        if ($node instanceof StaticCall) {
            return self::refactorStaticCall($node);
        }

        if ($node instanceof MethodCall) {
            return self::refactorMethodCall($node);
        }

        return null;
    }

    private static function refactorNew(New_ $new): ?New_
    {
        $args = $new->getArgs();

        if (!self::shouldFormatMultiline($args)) {
            return null;
        }

        // The formatting is handled by the printer/formatting system
        // We just need to ensure the node structure supports multiline
        foreach ($args as $arg) {
            // Add attributes to indicate this should be formatted multiline
            $arg->setAttribute('multiline', true);
        }

        return $new;
    }

    private static function refactorStaticCall(StaticCall $staticCall): ?StaticCall
    {
        $args = $staticCall->getArgs();

        if (!self::shouldFormatMultiline($args)) {
            return null;
        }

        foreach ($args as $arg) {
            $arg->setAttribute('multiline', true);
        }

        return $staticCall;
    }

    private static function refactorMethodCall(MethodCall $methodCall): ?MethodCall
    {
        $args = $methodCall->getArgs();

        if (!self::shouldFormatMultiline($args)) {
            return null;
        }

        foreach ($args as $arg) {
            $arg->setAttribute('multiline', true);
        }

        return $methodCall;
    }

    /**
     * @param array<Arg> $args
     */
    private static function shouldFormatMultiline(array $args): bool
    {
        // Must have at least minimum number of arguments
        if (count($args) < self::MIN_ARGUMENTS_FOR_MULTILINE) {
            return false;
        }

        return array_any($args, fn ($arg) => $arg->name !== null);
    }
}
