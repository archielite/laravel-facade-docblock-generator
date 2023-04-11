<?php

namespace ArchiElite\LaravelFacadeDocBlockGenerator;

use ArchiElite\LaravelFacadeDocBlockGenerator\Exceptions\DirectoryNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

class FacadeFinder
{
    public function find(string $path = null, array $ignorePaths = []): Collection
    {
        if ($path === null || ! File::isDirectory($path)) {
            throw new DirectoryNotFoundException($path);
        }

        if (! empty($ignorePaths)) {
            $ignorePaths = array_map(fn ($ignorePath) => ltrim(ltrim($ignorePath, $path), '/'), $ignorePaths);
        }

        $finder = (new Finder())->in($path)->files()
            ->notPath('vendor')
            ->notPath($ignorePaths)
            ->name('*.php');

        return (new Collection($finder))
            ->map(function (SplFileInfo $file) {
                return $this->resolveNamespaceFromFile($file->getRealPath());
            })
            ->filter(fn (string $class) => class_exists($class))
            ->map(fn (string $class) => new ReflectionClass($class))
            ->filter(function (ReflectionClass $class) {
                if (! $class->getParentClass()) {
                    return false;
                }

                return $class->getParentClass()->getName() === Facade::class;
            });
    }

    protected function resolveNamespaceFromFile(string $path): string|false
    {
        if (! file_exists($path)) {
            return false;
        }

        $content = file_get_contents($path);
        $namespace = Str::of($content)->after('namespace ')->before(';')->toString();
        $basename = basename($path, '.php');

        if (! $namespace) {
            return $basename;
        }

        return $namespace . '\\' . $basename;
    }
}
