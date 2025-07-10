<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Security;

/**
 * SuperGlobal Handler
 *
 * Intercepts access to superglobals
 */
final class SuperGlobalHandler implements \ArrayAccess
{
    private string $name;
    private array $data = [];
    private array $accessLog = [];

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function offsetExists($offset): bool
    {
        $this->logAccess('exists', $offset);
        return isset($this->data[$offset]);
    }

    public function offsetGet($offset): mixed
    {
        $this->logAccess('get', $offset);

        if (!isset($this->data[$offset])) {
            $offsetStr = is_scalar($offset) ? (string) $offset : 'non-scalar';
            trigger_error(
                "Undefined index: " . $offsetStr . " in \${$this->name}",
                E_USER_NOTICE
            );
            return null;
        }

        return $this->data[$offset];
    }

    public function offsetSet($offset, $value): void
    {
        $this->logAccess('set', $offset);

        if ($this->name === '_SERVER' || $this->name === '_ENV') {
            trigger_error(
                "Cannot modify \${$this->name} in ReactPHP context",
                E_USER_WARNING
            );
            return;
        }

        $this->data[$offset] = $value;
    }

    public function offsetUnset($offset): void
    {
        $this->logAccess('unset', $offset);
        unset($this->data[$offset]);
    }

    private function logAccess(string $operation, mixed $key): void
    {
        $this->accessLog[] = [
            'operation' => $operation,
            'key' => $key,
            'time' => microtime(true),
            'backtrace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3),
        ];
    }

    public function getAccessLog(): array
    {
        return $this->accessLog;
    }

    public function setData(array $data): void
    {
        $this->data = $data;
    }
}
