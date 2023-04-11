<?php

namespace ArchiElite\LaravelFacadeDocBlockGenerator\Commands;

use ArchiElite\LaravelFacadeDocBlockGenerator\DynamicParameter;
use ArchiElite\LaravelFacadeDocBlockGenerator\Exceptions\DirectoryNotFoundException;
use ArchiElite\LaravelFacadeDocBlockGenerator\FacadeFinder;
use ArchiElite\LaravelFacadeDocBlockGenerator\ReflectionMethodDecorator;
use ArrayAccess;
use Closure;
use Illuminate\Cache\Repository;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Conditionable;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ThisTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionType;
use ReflectionUnionType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

#[AsCommand('facade-docblock:generate')]
class FacadeDocblockGenerateCommand extends Command
{
    public function handle(): void
    {
        $path = $this->argument('path');

        if (! $path) {
            $path = $this->components->ask('Enter path to the directory with facades', app_path('Facades'));
        }

        try {
            $facadesCollection = (new FacadeFinder())->find($path, $this->option('exclude') ?: []);
        } catch (DirectoryNotFoundException $exception) {
            $this->components->error($exception->getMessage());
            exit(self::FAILURE);
        }

        if ($facadesCollection->isEmpty()) {
            $this->components->error('No facades found');

            exit(self::FAILURE);
        }

        $this->components->info('Found ' . $facadesCollection->count() . ' facades');

        $this->resolveFacades($facadesCollection);

        $this->components->info('Done');

        exit(self::SUCCESS);
    }

    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::OPTIONAL, 'Path to the directory with facades')
            ->addOption('exclude', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL, 'Exclude paths');
    }

    protected function conflictsWithFacade(ReflectionClass $facade, ReflectionMethodDecorator|string $method): bool
    {
        return collect($facade->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_STATIC))
            ->map(fn ($method) => $method->getName())
            ->contains(is_string($method) ? $method : $method->getName());
    }

    protected function fulfillsBuiltinInterface(ReflectionMethodDecorator|string $method): bool
    {
        if (is_string($method)) {
            return false;
        }

        if ($method->sourceClass()->implementsInterface(ArrayAccess::class)) {
            return in_array($method->getName(), ['offsetExists', 'offsetGet', 'offsetSet', 'offsetUnset']);
        }

        return false;
    }

    protected function handleConditionalType(ReflectionMethodDecorator $method, ConditionalTypeNode $typeNode): string
    {
        if (
            in_array($method->getname(), ['pull', 'get']) &&
            $method->getDeclaringClass()->getName() === Repository::class
        ) {
            return 'mixed';
        }

        $this->components->error('Found unknown conditional type. You will need to update the `handleConditionalType` to handle this new conditional type.');
        exit(self::FAILURE);
    }

    protected function handleUnknownIdentifierType(ReflectionMethodDecorator $method, IdentifierTypeNode $typeNode): string
    {
        if (
            $typeNode->name === 'TCacheValue' &&
            $method->getDeclaringClass()->getName() === Repository::class
        ) {
            return 'mixed';
        }

        if (
            $typeNode->name === 'TWhenParameter' &&
            in_array(Conditionable::class, class_uses_recursive($method->getDeclaringClass()->getName()))
        ) {
            return 'mixed';
        }

        if (
            $typeNode->name === 'TWhenReturnType' &&
            in_array(Conditionable::class, class_uses_recursive($method->getDeclaringClass()->getName()))
        ) {
            return 'mixed';
        }

        if (
            $typeNode->name === 'TUnlessParameter' &&
            in_array(Conditionable::class, class_uses_recursive($method->getDeclaringClass()->getName()))
        ) {
            return 'mixed';
        }

        if (
            $typeNode->name === 'TUnlessReturnType' &&
            in_array(Conditionable::class, class_uses_recursive($method->getDeclaringClass()->getName()))
        ) {
            return 'mixed';
        }

        if (
            $typeNode->name === 'TEnum' &&
            $method->getDeclaringClass()->getName() === Request::class
        ) {
            return 'object';
        }

        $this->components->error('Found unknown type: ' . $typeNode->name);
        $this->components->error('You may need to update the `handleUnknownIdentifierType` to handle this new type / generic.');
        exit(self::FAILURE);
    }

    protected function isBuiltIn(string $type): bool
    {
        return in_array($type, [
            'null', 'bool', 'int', 'float', 'string', 'array', 'object',
            'resource', 'never', 'void', 'mixed', 'iterable', 'self', 'static',
            'parent', 'true', 'false', 'callable',
        ]);
    }

    protected function isDeprecated(ReflectionMethodDecorator|string $method): bool
    {
        if (is_string($method)) {
            return false;
        }

        return $method->isDeprecated() || $this->resolveDocTags($method->getDocComment(), '@deprecated')->isNotEmpty();
    }

    protected function isInternal(ReflectionMethodDecorator|string $method): bool
    {
        if (is_string($method)) {
            return false;
        }

        return $this->resolveDocTags($method->getDocComment(), '@internal')->isNotEmpty();
    }

    protected function isKnownOptionalDependency(string $type): bool
    {
        return in_array($type, [
            '\Pusher\Pusher',
            '\GuzzleHttp\Psr7\RequestInterface',
        ]);
    }

    protected function isMagic(ReflectionMethodDecorator|string $method): bool
    {
        return Str::startsWith(is_string($method) ? $method : $method->getName(), '__');
    }

    protected function normalizeDetails(ReflectionMethodDecorator|string $method): array|string
    {
        return is_string($method) ? $method : [
            'name' => $method->getName(),
            'parameters' => $this->resolveParameters($method)
                ->map(fn ($parameter) => [
                    'name' => '$'.$parameter->getName(),
                    'optional' => $parameter->isOptional() && ! $parameter->isVariadic(),
                    'default' => $parameter->isDefaultValueAvailable()
                        ? $parameter->getDefaultValue()
                        : "❌ Unknown default for [{$parameter->getName()}] in [{$parameter->getDeclaringClass()?->getName()}::{$parameter->getDeclaringFunction()->getName()}] ❌",
                    'variadic' => $parameter->isVariadic(),
                    'type' => $this->resolveDocParamType($method, $parameter) ?? value(function () use ($method, $parameter) {
                        $return = $this->resolveType($parameter->getType());

                        return $return === 'self' || $return === 'static'
                            ? '\\' . $method->getDeclaringClass()->getName()
                            : $return;
                    }),
                ]),
            'returns' => value(function () use ($method) {
                $return = $this->resolveReturnDocType($method) ?? $this->resolveType($method->getReturnType()) ?? 'mixed';

                return $return === 'self' ? '\\' . $method->getDeclaringClass()->getName() : $return;
            }),
        ];
    }

    protected function parseDocblock(string $docblock): PhpDocNode
    {
        return (new PhpDocParser(new TypeParser(new ConstExprParser()), new ConstExprParser()))->parse(
            new TokenIterator((new Lexer())->tokenize($docblock ?: '/** */'))
        );
    }

    protected function resolveDefaultValue(array $parameter): string
    {
        // Reflection limitation fix for:
        // - Illuminate\Filesystem\Filesystem::ensureDirectoryExists()
        // - Illuminate\Filesystem\Filesystem::makeDirectory()
        if ($parameter['name'] === '$mode' && $parameter['default'] === 493) {
            return '0755';
        }

        $default = json_encode($parameter['default']);

        return Str::of($default === false ? 'unknown' : $default)
            ->replace('"', "'")
            ->replace('\\/', '/')
            ->toString();
    }

    protected function resolveDocblockTypes(ReflectionMethodDecorator $method, TypeNode $typeNode): string
    {
        if ($typeNode instanceof UnionTypeNode) {
            return '('.collect($typeNode->types)
                    ->map(fn ($node) => $this->resolveDocblockTypes($method, $node))
                    ->unique()
                    ->implode('|').')';
        }

        if ($typeNode instanceof IntersectionTypeNode) {
            return '('.collect($typeNode->types)
                    ->map(fn ($node) => $this->resolveDocblockTypes($method, $node))
                    ->unique()
                    ->implode('&').')';
        }

        if ($typeNode instanceof GenericTypeNode) {
            return $this->resolveDocblockTypes($method, $typeNode->type);
        }

        if ($typeNode instanceof ThisTypeNode) {
            return '\\'.$method->sourceClass()->getName();
        }

        if ($typeNode instanceof ArrayTypeNode) {
            return $this->resolveDocblockTypes($method, $typeNode->type).'[]';
        }

        if ($typeNode instanceof IdentifierTypeNode) {
            if ($typeNode->name === 'static') {
                return '\\'.$method->sourceClass()->getName();
            }

            if ($typeNode->name === 'self') {
                return '\\'.$method->getDeclaringClass()->getName();
            }

            if ($this->isBuiltIn($typeNode->name)) {
                return (string) $typeNode;
            }

            if (class_exists($typeNode->name)) {
                return (string) $typeNode;
            }

            if (interface_exists($typeNode->name)) {
                return (string) $typeNode;
            }

            if (version_compare(PHP_VERSION, '8.1', '>=') && enum_exists($typeNode->name)) {
                return (string) $typeNode;
            }

            if ($this->isKnownOptionalDependency($typeNode->name)) {
                return (string) $typeNode;
            }

            if ($typeNode->name === 'class-string') {
                return 'string';
            }

            return $this->handleUnknownIdentifierType($method, $typeNode);
        }

        if ($typeNode instanceof ConditionalTypeNode) {
            return $this->handleConditionalType($method, $typeNode);
        }

        if ($typeNode instanceof NullableTypeNode) {
            return '?'.$this->resolveDocblockTypes($method, $typeNode->type);
        }

        if ($typeNode instanceof CallableTypeNode) {
            return $this->resolveDocblockTypes($method, $typeNode->identifier);
        }

        $this->components->error('Unhandled type: '.$typeNode::class);
        $this->components->error('You may need to update the `resolveDocblockTypes` to handle this type.');
        exit(self::FAILURE);
    }

    protected function resolveDocMethods(ReflectionClass $class): Collection
    {
        return $this->resolveDocTags($class->getDocComment() ?: '', '@method')
            ->map(fn ($tag) => Str::squish($tag))
            ->map(fn ($tag) => Str::before($tag, ')').')');
    }

    protected function resolveDocMixins(ReflectionClass $class): Collection
    {
        return $this->resolveDocTags($class->getDocComment() ?: '', '@mixin')
            ->reject(fn (string $tag) => Str::startsWith($tag, 'https://'))
            ->filter(fn ($class) => class_exists($class) || interface_exists($class))
            ->map(fn ($class) => new ReflectionClass($class))
            ->flatMap(fn ($class) => [$class, ...$this->resolveDocMixins($class)]);
    }

    protected function resolveDocSees(ReflectionClass $class): Collection
    {
        return $this->resolveDocTags($class->getDocComment() ?: '', '@see')
            ->reject(fn (string $tag) => Str::startsWith($tag, 'https://'))
            ->filter(fn ($class) => class_exists($class) || interface_exists($class))
            ->map(fn ($class) => new ReflectionClass($class));
    }

    protected function resolveDocTags(string $docblock, string $tag): Collection
    {
        return Str::of($docblock)
            ->explode("\n")
            ->skip(1)
            ->reverse()
            ->skip(1)
            ->reverse()
            ->map(fn ($line) => ltrim($line, ' \*'))
            ->filter(fn ($line) => Str::startsWith($line, $tag))
            ->map(fn ($line) => Str::of($line)->after($tag)->trim()->toString())
            ->values();
    }

    protected function resolveFacades(Collection $facades): void
    {
        $facades->each(function (ReflectionClass $facade) {
            $proxies = $this->resolveDocSees($facade);

            $resolvedMethods = $proxies
                ->flatMap(fn (ReflectionClass $class) => [$class, ...$this->resolveDocMixins($class)])
                ->flatMap(fn (ReflectionClass $class) => $this->resolveMethods($class))
                ->reject(fn (ReflectionMethodDecorator $method) => $this->isMagic($method))
                ->reject(fn (ReflectionMethodDecorator $method) => $this->isInternal($method))
                ->reject(fn (ReflectionMethodDecorator $method) => $this->isDeprecated($method))
                ->reject(fn (ReflectionMethodDecorator $method) => $this->fulfillsBuiltinInterface($method))
                ->reject(fn (ReflectionMethodDecorator $method) => $this->conflictsWithFacade($facade, $method))
                ->unique(fn (ReflectionMethodDecorator $method) => $this->resolveName($method))
                ->map(fn (ReflectionMethodDecorator $method) => $this->normalizeDetails($method))
            ;

            $methods = $resolvedMethods->map(function ($method) {
                if (is_string($method)) {
                    return " * @method static {$method}";
                }

                $parameters = $method['parameters']->map(function ($parameter) {
                    $rest = $parameter['variadic'] ? '...' : '';

                    $default = $parameter['optional'] ? ' = '.$this->resolveDefaultValue($parameter) : '';

                    $parameter['type'] = empty($parameter['type']) ? '' : $parameter['type'].' ';

                    return "{$parameter['type']}{$rest}{$parameter['name']}{$default}";
                });

                return " * @method static {$method['returns']} {$method['name']}({$parameters->join(', ')})";
            });

            $directMixins = $this->resolveDocTags($facade->getDocComment() ?: '', '@mixin');

            $docblock = <<< PHP
    /**
    {$methods->join(PHP_EOL)}
     *
    {$proxies->map(fn (ReflectionClass $class) => " * @see \\{$class->getName()}")->merge($directMixins->map(fn ($class) => " * @mixin {$class}"))->join(PHP_EOL)}
     */
    PHP;

            if (($facade->getDocComment() ?: '') === $docblock) {
                $this->components->warn("Docblock for [{$facade->getName()}] is up to date. Skip...");
                return;
            }

            $contents = file_get_contents($facade->getFileName());
            $contents = Str::replace($facade->getDocComment(), $docblock, $contents);
            file_put_contents($facade->getFileName(), $contents);

            $this->components->info('Docblock for ['.$facade->getName().'] generated');
        });
    }

    protected function resolveMethods(ReflectionClass $class): Collection
    {
        return collect($class->getMethods(ReflectionMethod::IS_PUBLIC))
            ->map(fn ($method) => new ReflectionMethodDecorator($method, $class->getName()))
            ->merge($this->resolveDocMethods($class));
    }

    protected function resolveName(ReflectionMethodDecorator|string $method): string
    {
        return is_string($method)
            ? Str::of($method)->after(' ')->before('(')->toString()
            : $method->getName();
    }

    protected function resolveParameters(ReflectionMethodDecorator $method): Collection
    {
        $dynamicParameters = $this->resolveDocParameters($method)
            ->skip($method->getNumberOfParameters())
            ->mapInto(DynamicParameter::class);

        return collect($method->getParameters())->merge($dynamicParameters);
    }

    protected function resolveDocParameters(ReflectionMethodDecorator $method): Collection
    {
        return $this->resolveDocTags($method->getDocComment() ?: '', '@param')
            ->map(fn ($tag) => Str::squish($tag));
    }

    protected function resolveDocParamType(ReflectionMethodDecorator $method, DynamicParameter|ReflectionParameter $parameter): string|null
    {
        $paramTypeNode = collect($this->parseDocblock($method->getDocComment())->getParamTagValues())
            ->firstWhere('parameterName', '$'.$parameter->getName());

        // As we didn't find a param type, we will now recursivly check if the prototype has a value specified...

        if ($paramTypeNode === null) {
            try {
                $prototype = new ReflectionMethodDecorator($method->getPrototype(), $method->sourceClass()->getName());

                return $this->resolveDocParamType($prototype, $parameter);
            } catch (Throwable) {
                return null;
            }
        }

        $type = $this->resolveDocblockTypes($method, $paramTypeNode->type);

        return trim($type, '()');
    }

    protected function resolveReturnDocType(ReflectionMethodDecorator $method): string|null
    {
        $returnTypeNode = array_values($this->parseDocblock($method->getDocComment())->getReturnTagValues())[0] ?? null;

        if ($returnTypeNode === null) {
            return null;
        }

        $type = $this->resolveDocblockTypes($method, $returnTypeNode->type);

        return trim($type, '()');
    }

    protected function resolveType(ReflectionType|null $type): string|null
    {
        if (version_compare(PHP_VERSION, '8.1', '>=') && $type instanceof \ReflectionIntersectionType) {
            return collect($type->getTypes())
                ->map(Closure::fromCallable([$this, 'resolveType']))
                ->filter()
                ->join('&');
        }

        if ($type instanceof ReflectionUnionType) {
            return collect($type->getTypes())
                ->map(Closure::fromCallable([$this, 'resolveType']))
                ->filter()
                ->join('|');
        }

        if ($type instanceof ReflectionNamedType && $type->getName() === 'null') {
            return ($type->isBuiltin() ? '' : '\\').$type->getName();
        }

        if ($type instanceof ReflectionNamedType && $type->getName() !== 'null') {
            return ($type->isBuiltin() || $this->isBuiltIn($type->getName()) ? '' : '\\').$type->getName().($type->allowsNull() ? '|null' : '');
        }

        return null;
    }
}
