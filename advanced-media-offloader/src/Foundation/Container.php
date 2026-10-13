<?php

declare(strict_types=1);

namespace AdvancedMediaOffloader\Foundation;

use Closure;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

class Container
{
    private array $bindings = [];
    private array $instances = [];
    private array $aliases = [];
    private array $extenders = [];
    private array $tags = [];
    private array $buildStack = [];

    public function __construct()
    {
        foreach ([static::class, ...array_values(class_parents($this))] as $class) {
            $this->instances[$class] = $this;
        }
    }

    /**
     * Binding an alias rebinds its target, so a service replaced under its 4.x id is the one core uses.
     */
    public function bind(string $abstract, Closure|string|null $concrete = null, bool $shared = false): static
    {
        $abstract = $this->getAlias($abstract);
        unset($this->instances[$abstract]);

        $this->bindings[$abstract] = [
            'concrete' => $concrete ?? $abstract,
            'shared'   => $shared,
        ];

        return $this;
    }

    public function singleton(string $abstract, Closure|string|null $concrete = null): static
    {
        return $this->bind($abstract, $concrete, true);
    }

    public function instance(string $abstract, mixed $instance): mixed
    {
        $this->instances[$this->getAlias($abstract)] = $instance;

        return $instance;
    }

    public function alias(string $abstract, string $alias): static
    {
        if ($abstract === $alias) {
            throw new ContainerException("'$abstract' cannot be an alias of itself."); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
        }

        $this->aliases[$alias] = $abstract;

        return $this;
    }

    /**
     * Wraps or replaces what the container returns for $abstract. The closure gets the object and the container.
     */
    public function extend(string $abstract, Closure $extender): void
    {
        $abstract = $this->getAlias($abstract);

        if (array_key_exists($abstract, $this->instances)) {
            $this->instances[$abstract] = $extender($this->instances[$abstract], $this);
            return;
        }

        $this->extenders[$abstract][] = $extender;
    }

    public function tag(string|array $abstracts, string $tag): static
    {
        foreach ((array) $abstracts as $abstract) {
            $this->tags[$tag][] = $abstract;
        }

        return $this;
    }

    public function tagged(string $tag): array
    {
        return array_map(fn (string $abstract) => $this->make($abstract), $this->tags[$tag] ?? []);
    }

    public function has(string $id): bool
    {
        $id = $this->getAlias($id);

        return isset($this->bindings[$id]) || array_key_exists($id, $this->instances);
    }

    public function resolved(string $abstract): bool
    {
        return array_key_exists($this->getAlias($abstract), $this->instances);
    }

    public function get(string $id): mixed
    {
        return $this->make($id);
    }

    public function make(string $abstract, array $parameters = []): mixed
    {
        $abstract = $this->getAlias($abstract);

        if (array_key_exists($abstract, $this->instances) && !$parameters) {
            return $this->instances[$abstract];
        }

        $binding = $this->bindings[$abstract] ?? null;
        $concrete = $binding['concrete'] ?? $abstract;

        if ($concrete instanceof Closure) {
            $object = $concrete($this, $parameters);
        } elseif ($concrete === $abstract) {
            $object = $this->build($concrete, $parameters);
        } else {
            $object = $this->make($concrete, $parameters);
        }

        foreach ($this->extenders[$abstract] ?? [] as $extender) {
            $object = $extender($object, $this);
        }

        if (($binding['shared'] ?? false) && !$parameters) {
            $this->instances[$abstract] = $object;
        }

        return $object;
    }

    public function build(string $class, array $parameters = []): object
    {
        // phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
        if (!class_exists($class)) {
            throw new ContainerException("Nothing is bound to '$class' and no class has that name.");
        }

        if (in_array($class, $this->buildStack, true)) {
            throw new ContainerException('Circular dependency: ' . implode(' -> ', [...$this->buildStack, $class]));
        }

        $reflector = new ReflectionClass($class);

        if (!$reflector->isInstantiable()) {
            throw new ContainerException("'$class' cannot be instantiated. Bind it to a concrete class or a closure.");
        }
        // phpcs:enable

        $constructor = $reflector->getConstructor();

        if ($constructor === null) {
            return new $class();
        }

        $this->buildStack[] = $class;

        try {
            $arguments = array_map(
                fn (ReflectionParameter $parameter) => $this->resolveParameter($parameter, $parameters, $class),
                $constructor->getParameters()
            );
        } finally {
            array_pop($this->buildStack);
        }

        return $reflector->newInstanceArgs($arguments);
    }

    private function resolveParameter(ReflectionParameter $parameter, array $parameters, string $class): mixed
    {
        if (array_key_exists($parameter->getName(), $parameters)) {
            return $parameters[$parameter->getName()];
        }

        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $dependency = $type->getName();

            if (array_key_exists($dependency, $parameters)) {
                return $parameters[$dependency];
            }

            if ($this->has($dependency) || class_exists($dependency)) {
                return $this->make($dependency);
            }
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($type !== null && $type->allowsNull()) {
            return null;
        }

        throw new ContainerException("Cannot resolve \${$parameter->getName()} of '$class'."); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
    }

    private function getAlias(string $abstract): string
    {
        while (isset($this->aliases[$abstract])) {
            $abstract = $this->aliases[$abstract];
        }

        return $abstract;
    }
}
