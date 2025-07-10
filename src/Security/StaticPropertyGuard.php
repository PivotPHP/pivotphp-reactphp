<?php

declare(strict_types=1);

namespace PivotPHP\ReactPHP\Security;

/**
 * Static Property Guard
 *
 * Monitors and controls static property access
 */
final class StaticPropertyGuard
{
    private array $whitelist = [];
    private array $tracked = [];
    private array $violations = [];

    /**
     * Add class to whitelist
     */
    public function allowClass(string $className, array $properties = []): void
    {
        $this->whitelist[$className] = $properties;
    }

    /**
     * Track static property
     */
    public function trackProperty(string $class, string $property): void
    {
        $key = "$class::$property";

        if (!isset($this->tracked[$key])) {
            $this->tracked[$key] = [
                'class' => $class,
                'property' => $property,
                'original' => $this->getStaticPropertyValue($class, $property),
                'accesses' => 0,
                'modifications' => 0,
            ];
        }

        $this->tracked[$key]['accesses']++;
    }

    /**
     * Check if static property access is allowed
     */
    public function isAllowed(string $class, string $property): bool
    {
        // Check whitelist
        if (isset($this->whitelist[$class])) {
            $allowedProps = $this->whitelist[$class];
            return $allowedProps === [] || in_array($property, $allowedProps, true);
        }

        // Log violation
        $this->violations[] = [
            'class' => $class,
            'property' => $property,
            'time' => microtime(true),
            'backtrace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3),
        ];

        return false;
    }

    /**
     * Get static property value using reflection
     */
    private function getStaticPropertyValue(string $class, string $property): mixed
    {
        try {
            if (!class_exists($class)) {
                return false;
            }
            $reflection = new \ReflectionClass($class);
            $prop = $reflection->getProperty($property);
            $prop->setAccessible(true);
            return $prop->getValue();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Reset tracked static properties
     */
    public function resetTrackedProperties(): void
    {
        foreach ($this->tracked as $key => $info) {
            if ($info['modifications'] > 0) {
                try {
                    $reflection = new \ReflectionClass($info['class']);
                    $prop = $reflection->getProperty($info['property']);
                    $prop->setAccessible(true);
                    $prop->setValue(null, $info['original']);
                } catch (\Throwable $e) {
                    // Log error
                }
            }
        }

        $this->tracked = [];
    }

    /**
     * Get violations report
     */
    public function getViolations(): array
    {
        return $this->violations;
    }
}
