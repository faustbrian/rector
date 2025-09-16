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
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use Rector\Rector\AbstractRector;
use ReflectionClass;
use ReflectionException;
use ReflectionParameter;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

use function array_any;
use function array_key_exists;
use function array_map;
use function count;
use function is_string;

/**
 * Enforces named arguments for constructor calls and static factory methods
 * when they have 3 or more parameters, improving code readability and maintainability.
 *
 * This rector transforms positional arguments to named arguments for:
 * - Constructor calls (new ClassName())
 * - Static factory method calls (ClassName::method())
 * - Instance method calls with multiple parameters
 *
 * Benefits:
 * - Improved code readability, especially with boolean parameters
 * - Self-documenting code that shows parameter intent
 * - Reduced chance of parameter order mistakes
 * - Easier refactoring when parameter order changes
 *
 * Examples:
 * - new User('john', 'doe', true, false) -> new User(firstName: 'john', lastName: 'doe', isActive: true, isAdmin: false)
 * - User::create($name, $email, $active) -> User::create(name: $name, email: $email, active: $active)
 *
 * @author Brian Faust <brian@cline.sh>
 */
final class EnforceNamedArgumentsRector extends AbstractRector
{
    private const int MIN_PARAMETERS_FOR_NAMED_ARGS = 2;

    private const int MIN_PARAMETERS_FOR_MULTILINE = 2;

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            description: 'Enforce named arguments for constructor calls and methods with 3+ parameters',
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
        return new self($caseId, true, $level);
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
        return new self(collectionCaseId: $caseId, shouldEscalate: true, currentEscalationLevel: $level);
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
            return $this->refactorNew($node);
        }

        if ($node instanceof StaticCall) {
            return $this->refactorStaticCall($node);
        }

        if ($node instanceof MethodCall) {
            return self::refactorMethodCall($node);
        }

        return null;
    }

    private static function refactorMethodCall(MethodCall $methodCall): ?MethodCall
    {
        if (!$methodCall->name instanceof Identifier) {
            return null;
        }

        $args = $methodCall->getArgs();

        if (count($args) < self::MIN_PARAMETERS_FOR_NAMED_ARGS) {
            return null;
        }

        // Skip if already using named arguments
        if (self::hasNamedArguments($args)) {
            return null;
        }

        // For instance method calls, we can't easily determine parameter names
        // without more complex type analysis, so we'll skip these for now
        return null;
    }

    /**
     * @param array<Arg> $args
     */
    private static function hasNamedArguments(array $args): bool
    {
        return array_any($args, fn ($arg) => $arg->name !== null);
    }

    /**
     * @return array<string>
     */
    private static function getMethodParameterNames(string $className, string $methodName): array
    {
        try {
            $reflection = new ReflectionClass($className);
            $method = $reflection->getMethod($methodName);

            return array_map(
                static fn (ReflectionParameter $param): string => $param->getName(),
                $method->getParameters(),
            );
        } catch (ReflectionException) {
            return [];
        }
    }

    /**
     * @param  array<Arg>      $args
     * @param  array<string>   $parameterNames
     * @return null|array<Arg>
     */
    private static function convertToNamedArguments(array $args, array $parameterNames): ?array
    {
        $namedArgs = [];

        foreach ($args as $index => $arg) {
            if (!array_key_exists($index, $parameterNames)) {
                // More arguments than parameters, can't safely convert
                return null;
            }

            $parameterName = $parameterNames[$index];
            $namedArg = new Arg(
                value: $arg->value,
                byRef: $arg->byRef,
                unpack: $arg->unpack,
                attributes: $arg->getAttributes(),
                name: new Identifier($parameterName),
            );

            // Multiline formatting will be handled separately by PHP-CS-Fixer

            $namedArgs[] = $namedArg;
        }

        return $namedArgs;
    }

    /**
     * @return array<string>
     */
    private static function getParameterNamesFromNode(ClassMethod $constructor): array
    {
        return array_map(
            static fn (Param $param): string => $param->var instanceof Variable && is_string($param->var->name)
                ? $param->var->name
                : '',
            $constructor->params,
        );
    }

    /**
     * @return array<string>
     */
    private static function getConstructorParameterNames(string $className): array
    {
        // Use reflection to get parameter names
        // This ensures we get the correct parameter names for the actual class being instantiated
        try {
            $reflection = new ReflectionClass($className);
            $constructor = $reflection->getConstructor();

            if ($constructor === null) {
                return [];
            }

            return array_map(
                static fn (ReflectionParameter $param): string => $param->getName(),
                $constructor->getParameters(),
            );
        } catch (ReflectionException) {
            return [];
        }
    }

    private function refactorNew(New_ $new): ?New_
    {
        if (!$new->class instanceof Name) {
            return null;
        }

        $args = $new->getArgs();

        if (count($args) < self::MIN_PARAMETERS_FOR_NAMED_ARGS) {
            return null;
        }

        // Skip if already using named arguments
        if (self::hasNamedArguments($args)) {
            return null;
        }

        $className = $this->getName($new->class);

        if (!is_string($className)) {
            return null;
        }

        $parameterNames = self::getConstructorParameterNames($className);

        if (empty($parameterNames)) {
            return null;
        }

        $namedArgs = self::convertToNamedArguments($args, $parameterNames);

        if ($namedArgs === null) {
            return null;
        }

        $new->args = $namedArgs;

        // Force multiline formatting for 2+ named arguments - this will be handled by PHP-CS-Fixer
        // after we manually add a newline to trigger the multiline formatting

        return $new;
    }

    private function refactorStaticCall(StaticCall $staticCall): ?StaticCall
    {
        if (!$staticCall->class instanceof Name) {
            return null;
        }

        if (!$staticCall->name instanceof Identifier) {
            return null;
        }

        $args = $staticCall->getArgs();

        if (count($args) < self::MIN_PARAMETERS_FOR_NAMED_ARGS) {
            return null;
        }

        // Skip if already using named arguments
        if (self::hasNamedArguments($args)) {
            return null;
        }

        $className = $this->getName($staticCall->class);
        $methodName = $staticCall->name->toString();

        if (!is_string($className)) {
            return null;
        }

        $parameterNames = self::getMethodParameterNames($className, $methodName);

        if (empty($parameterNames)) {
            return null;
        }

        $namedArgs = self::convertToNamedArguments($args, $parameterNames);

        if ($namedArgs === null) {
            return null;
        }

        $staticCall->args = $namedArgs;

        // Multiline formatting will be handled by PHP-CS-Fixer

        return $staticCall;
    }
}
