<?php

declare(strict_types=1);

namespace League\Container\Definition;

use League\Container\Argument\{
    ArgumentResolverInterface,
    ArgumentResolverTrait,
    ArgumentInterface,
    LiteralArgumentInterface
};
use League\Container\ContainerAwareTrait;
use League\Container\Exception\ContainerException;
use Psr\Container\ContainerInterface;
use ReflectionClass;

class Definition implements ArgumentResolverInterface, DefinitionInterface
{
    use ArgumentResolverTrait;
    use ContainerAwareTrait;

    protected mixed $resolved = null;

    public function __construct(
        protected string $id,
        protected mixed $concrete = null,
        protected bool $shared = false,
        protected array $arguments = [],
        protected array $methods = [],
        protected array $tags = [],
    ) {
        $this->concrete ??= $this->id;
    }

    public function addTag(string $tag): DefinitionInterface
    {
        $this->tags[$tag] = true;
        return $this;
    }

    public function hasTag(string $tag): bool
    {
        return isset($this->tags[$tag]);
    }

    public function setId(string $id): DefinitionInterface
    {
        $this->id = $id;
        return $this;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function setAlias(string $id): DefinitionInterface
    {
        return $this->setId($id);
    }

    public function getAlias(): string
    {
        return $this->getId();
    }

    public function setShared(bool $shared = true): DefinitionInterface
    {
        $this->shared = $shared;
        return $this;
    }

    public function isShared(): bool
    {
        return $this->shared;
    }

    public function getConcrete(): mixed
    {
        return $this->concrete;
    }

    public function setConcrete($concrete): DefinitionInterface
    {
        $this->concrete = $concrete;
        $this->resolved = null;
        return $this;
    }

    public function addArgument($arg): DefinitionInterface
    {
        $this->arguments[] = $arg;
        return $this;
    }

    public function addArguments(array $args): DefinitionInterface
    {
        foreach ($args as $arg) {
            $this->addArgument($arg);
        }

        return $this;
    }

    public function addMethodCall(string $method, array $args = []): DefinitionInterface
    {
        $this->methods[] = [
            'method' => $method,
            'arguments' => $args
        ];

        return $this;
    }

    public function addMethodCalls(array $methods = []): DefinitionInterface
    {
        foreach ($methods as $method => $args) {
            $this->addMethodCall($method, $args);
        }

        return $this;
    }

    public function resolve(): mixed
    {
        if (null !== $this->resolved && $this->isShared()) {
            return $this->resolved;
        }

        return $this->resolveNew();
    }

    public function resolveNew(): mixed
    {
        $concrete = $this->concrete;

        if (is_callable($concrete)) {
            $concrete = $this->resolveCallable($concrete);
        }

        if ($concrete instanceof LiteralArgumentInterface) {
            $this->resolved = $concrete->getValue();
            return $concrete->getValue();
        }

        if ($concrete instanceof ArgumentInterface) {
            $concrete = $concrete->getValue();
        }

        if (is_string($concrete)) {
            if (class_exists($concrete)) {
                $concrete = $this->resolveClass($concrete);
            } elseif ($this->getAlias() === $concrete) {
                return $concrete;
            }
        }

        if (is_object($concrete)) {
            $concrete = $this->invokeMethods($concrete);
        }

        try {
            $container = $this->getContainer();
        } catch (ContainerException $e) {
            $container = null;
        }

        // if we still have a string, try to pull it from the container
        // this allows for `alias -> alias -> ... -> concrete
        if (is_string($concrete) && $container instanceof ContainerInterface && $container->has($concrete)) {
            $concrete = $container->get($concrete);
        }

        $this->resolved = $concrete;
        return $concrete;
    }

    protected function resolveCallable(callable $concrete): mixed
    {
        $resolved = $this->resolveArguments($this->arguments);
        return call_user_func_array($concrete, $resolved);
    }

    protected function resolveClass(string $concrete): object
    {
        $resolved   = $this->resolveArguments($this->arguments);
        $reflection = new ReflectionClass($concrete);
        return $reflection->newInstanceArgs($resolved);
    }

    protected function invokeMethods(object $instance): object
    {
        foreach ($this->methods as $method) {
            $args = $this->resolveArguments($method['arguments']);
            $callable = [$instance, $method['method']];
            call_user_func_array($callable, $args);
        }

        return $instance;
    }
}
