<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Security;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Interface for Request Isolation functionality
 */
interface RequestIsolationInterface
{
    /**
     * Create isolated context for a request
     */
    public function createContext(ServerRequestInterface $request): string;

    /**
     * Destroy context and restore state
     */
    public function destroyContext(string $contextId): void;

    /**
     * Get context information
     */
    public function getContextInfo(string $contextId): ?array;

    /**
     * Check if context exists
     */
    public function hasContext(string $contextId): bool;
}
