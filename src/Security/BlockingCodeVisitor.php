<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Security;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;

/**
 * AST Visitor for detecting blocking code
 */
final class BlockingCodeVisitor extends NodeVisitorAbstract
{
    private array $violations;
    private string $context;

    public function __construct(array &$violations, string $context)
    {
        $this->violations = &$violations;
        $this->context = $context;
    }

    public function enterNode(Node $node)
    {
        // Check function calls
        if ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name) {
            $functionName = $node->name->toString();

            if (isset(BlockingCodeDetector::BLOCKING_FUNCTIONS[$functionName])) {
                $this->violations[] = [
                    'type' => 'blocking_function',
                    'severity' => 'error',
                    'function' => $functionName,
                    'line' => $node->getLine(),
                    'context' => $this->context,
                    'message' => "Blocking function '$functionName' will freeze the server",
                    'suggestion' => BlockingCodeDetector::BLOCKING_FUNCTIONS[$functionName],
                ];
            } elseif (isset(BlockingCodeDetector::WARNING_FUNCTIONS[$functionName])) {
                $this->violations[] = [
                    'type' => 'unsafe_function',
                    'severity' => 'warning',
                    'function' => $functionName,
                    'line' => $node->getLine(),
                    'context' => $this->context,
                    'message' => "Function '$functionName' may cause issues in ReactPHP",
                    'suggestion' => BlockingCodeDetector::WARNING_FUNCTIONS[$functionName],
                ];
            }
        }

        // Check for exit/die (language constructs, not functions)
        if ($node instanceof Node\Expr\Exit_) {
            $type = $node->getAttribute('kind') === Node\Expr\Exit_::KIND_DIE ? 'die' : 'exit';
            $this->violations[] = [
                'type' => 'blocking_function',
                'severity' => 'error',
                'function' => $type,
                'line' => $node->getLine(),
                'context' => $this->context,
                'message' => "Language construct '$type' kills the entire server",
                'suggestion' => BlockingCodeDetector::BLOCKING_FUNCTIONS[$type],
            ];
        }

        // Check for global variable access
        if ($node instanceof Node\Expr\Variable) {
            $varName = is_string($node->name) ? $node->name : null;
            if ($varName !== null && in_array($varName, ['GLOBALS', '_SESSION', '_SERVER', '_ENV'], true)) {
                $this->violations[] = [
                    'type' => 'global_access',
                    'severity' => 'warning',
                    'variable' => '$' . $varName,
                    'line' => $node->getLine(),
                    'context' => $this->context,
                    'message' => "Global variable \$$varName is shared across all requests",
                    'suggestion' => 'Use request attributes or dependency injection',
                ];
            }
        }

        // Check for static variables in functions
        if ($node instanceof Node\Stmt\Static_) {
            $this->violations[] = [
                'type' => 'static_variable',
                'severity' => 'warning',
                'line' => $node->getLine(),
                'context' => $this->context,
                'message' => 'Static variables persist across requests',
                'suggestion' => 'Use class properties with proper lifecycle management',
            ];
        }

        // Check for infinite loops
        if ($node instanceof Node\Stmt\While_ && $this->isPotentiallyInfinite($node->cond)) {
            $this->violations[] = [
                'type' => 'infinite_loop',
                'severity' => 'error',
                'line' => $node->getLine(),
                'context' => $this->context,
                'message' => 'Potentially infinite loop will block the server',
                'suggestion' => 'Add timeout or use ReactPHP periodic timers',
            ];
        }

        return null;
    }

    /**
     * Check if a condition might be infinite
     */
    private function isPotentiallyInfinite(Node $condition): bool
    {
        // Check for while(true) or while(1)
        if ($condition instanceof Node\Expr\ConstFetch) {
            $name = $condition->name->toString();
            return $name === 'true';
        }

        if ($condition instanceof Node\Scalar\LNumber && $condition->value === 1) {
            return true;
        }

        return false;
    }

    public function getViolations(): array
    {
        return $this->violations;
    }
}
