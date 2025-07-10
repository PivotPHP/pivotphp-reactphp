<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Middleware;

/**
 * Security Exception
 */
class SecurityException extends \Exception
{
    public function __construct(string $message, int $code = 400)
    {
        parent::__construct($message, $code);
    }
}
