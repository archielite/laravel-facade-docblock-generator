<?php

namespace ArchiElite\LaravelFacadeDocBlockGenerator;

use Illuminate\Support\Str;

class DynamicParameter
{
    public function __construct(private string $definition)
    {
    }

    public function getName(): string
    {
        return Str::of($this->definition)
            ->after('$')
            ->before(' ')
            ->toString();
    }

    public function isOptional(): bool
    {
        return true;
    }

    public function isVariadic(): bool
    {
        return Str::contains($this->definition, " ...\${$this->getName()}");
    }

    public function isDefaultValueAvailable(): bool
    {
        return true;
    }

    public function getDefaultValue()
    {
        return null;
    }
}
