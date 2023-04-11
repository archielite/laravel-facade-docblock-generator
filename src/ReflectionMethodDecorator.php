<?php

namespace ArchiElite\LaravelFacadeDocBlockGenerator;

use ReflectionClass;
use ReflectionMethod;

/**
 * @mixin \ReflectionMethod
 */
class ReflectionMethodDecorator
{
    public function __construct(private ReflectionMethod $method, private string $sourceClass)
    {
    }

    public function __call(string $name, array $arguments)
    {
        return $this->method->{$name}(...$arguments);
    }

    public function toBase(): ReflectionMethod
    {
        return $this->method;
    }

    public function sourceClass(): ReflectionClass
    {
        return new ReflectionClass($this->sourceClass);
    }
}
