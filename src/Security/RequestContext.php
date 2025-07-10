<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Security;

/**
 * Request Context
 *
 * Isolated context for each request
 */
final class RequestContext
{
    private string $id;
    private array $data = [];
    private array $mutations = [];

    public function __construct(string $id)
    {
        $this->id = $id;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
        $this->mutations[] = [
            'action' => 'set',
            'key' => $key,
            'time' => microtime(true),
        ];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    public function getMutations(): array
    {
        return $this->mutations;
    }

    public function getId(): string
    {
        return $this->id;
    }
}
