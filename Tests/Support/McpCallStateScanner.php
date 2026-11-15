<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Support;

/**
 * Finds per-process state in the services an MCP call can reach (release-gate finding 1, contract R7).
 *
 * The connector is one long-running process: every DI singleton it built lives for all calls of all users. A service
 * that writes one of its own properties outside the constructor keeps that value for the next call, so it must either
 * implement McpCallScopedState (reset before and after every call by McpUserContext) or be listed, with a reason, as
 * deliberately process-wide.
 *
 * Reachability: constructor injection from the roots, following every class of this extension (an interface or abstract
 * type leads to each of its implementations in Classes/), transitively. G-15 (AQG 3.0.0 security gate): also classes a
 * reachable class obtains at run time — `GeneralUtility::makeInstance(X::class)`, `->get(X::class)` (container),
 * `new X(` — and the element types of injected iterables (`iterable<X>` / `X[]` in the constructor docblock: tagged
 * iterators). Static properties written outside the constructor count as state as well (they outlive every instance).
 */
final class McpCallStateScanner
{
    private const NAMESPACE_PREFIX = 'Priebera\\A11yQualityGate\\';

    /** @var array<string, list<string>>|null interface/abstract → implementations */
    private ?array $implementations = null;

    public function __construct(private readonly string $classesDirectory)
    {
    }

    /**
     * @param list<class-string> $roots
     * @return list<class-string> every extension class reachable by constructor injection, roots included
     */
    public function reachable(array $roots): array
    {
        $seen = [];
        $queue = $roots;
        while ($queue !== []) {
            $class = array_shift($queue);
            if (isset($seen[$class]) || !str_starts_with($class, self::NAMESPACE_PREFIX)) {
                continue;
            }
            if (!class_exists($class) && !interface_exists($class)) {
                continue;
            }
            $seen[$class] = true;
            $reflection = new \ReflectionClass($class);
            if ($reflection->isInterface() || $reflection->isAbstract()) {
                foreach ($this->implementationsOf($class) as $implementation) {
                    $queue[] = $implementation;
                }
                continue;
            }
            foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
                foreach ($this->typeNames($parameter->getType()) as $name) {
                    $queue[] = $name;
                }
            }
            foreach ($this->runtimeReferences($reflection) as $name) {
                $queue[] = $name;
            }
        }
        $classes = array_keys(array_filter($seen, static fn (string $class): bool => class_exists($class) && !(new \ReflectionClass($class))->isAbstract(), ARRAY_FILTER_USE_KEY));
        sort($classes);

        return $classes;
    }

    /**
     * Properties of $class written outside its constructor (instance state that survives a call).
     *
     * @return list<string>
     */
    public function mutableState(string $class): array
    {
        $reflection = new \ReflectionClass($class);
        $file = $reflection->getFileName();
        if ($file === false) {
            return [];
        }
        $source = (string)file_get_contents($file);
        $constructorSource = '';
        $constructor = $reflection->getConstructor();
        if ($constructor !== null && $constructor->getDeclaringClass()->getName() === $class) {
            $lines = file($file);
            $constructorSource = implode('', array_slice($lines, $constructor->getStartLine() - 1, $constructor->getEndLine() - $constructor->getStartLine() + 1));
        }
        $outsideConstructor = $constructorSource !== '' ? str_replace($constructorSource, '', $source) : $source;
        $state = [];
        foreach ($reflection->getProperties() as $property) {
            if ($property->isReadOnly() || $property->getDeclaringClass()->getName() !== $class) {
                continue;
            }
            $name = preg_quote($property->getName(), '/');
            // $this->name = …, $this->name ??= …, $this->name[…] = …, $this->name[] = …, unset($this->name[…]), ++/--;
            // for static properties the same through self::$name / static::$name / ClassName::$name.
            $target = $property->isStatic()
                ? '(?:self|static|' . preg_quote($reflection->getShortName(), '/') . ')::\$' . $name
                : '\$this->' . $name;
            $pattern = '/' . $target . '(\[[^\]]*\])*\s*(=(?!=)|\?\?=|\.=|\+=|-=|\+\+|--)|unset\(\s*' . $target . '\b|(\+\+|--)' . $target . '\b/';
            if (preg_match($pattern, $outsideConstructor) === 1) {
                $state[] = $property->getName();
            }
        }

        return $state;
    }

    /**
     * Extension classes $reflection obtains at run time or receives as iterable elements (G-15).
     *
     * @return list<string>
     */
    private function runtimeReferences(\ReflectionClass $reflection): array
    {
        $file = $reflection->getFileName();
        if ($file === false) {
            return [];
        }
        $source = (string)file_get_contents($file);
        $class = '\\\\?([A-Za-z_][A-Za-z0-9_\\\\]*)';
        $names = [];
        foreach (['/makeInstance\(\s*' . $class . '::class/', '/->get\(\s*' . $class . '::class/', '/\bnew\s+' . $class . '\s*\(/'] as $pattern) {
            preg_match_all($pattern, $source, $matches);
            $names = [...$names, ...$matches[1]];
        }
        $docComment = (string)($reflection->getConstructor()?->getDocComment() ?: '');
        preg_match_all('/iterable<\s*(?:[A-Za-z0-9_]+\s*,\s*)?' . $class . '\s*>|' . $class . '\[\]/', $docComment, $docMatches);
        $names = [...$names, ...array_filter([...$docMatches[1], ...$docMatches[2]])];

        $resolved = [];
        foreach (array_unique($names) as $name) {
            $resolvedName = $this->resolveName($name, $reflection, $source);
            if ($resolvedName !== null && str_starts_with($resolvedName, self::NAMESPACE_PREFIX)) {
                $resolved[] = $resolvedName;
            }
        }

        return $resolved;
    }

    /** A class name as written in $source, resolved through its namespace and `use` imports. */
    private function resolveName(string $name, \ReflectionClass $reflection, string $source): ?string
    {
        if (in_array(strtolower($name), ['self', 'static', 'parent'], true) || !preg_match('/^[A-Z]/', $name)) {
            return null;
        }
        if (str_starts_with($name, self::NAMESPACE_PREFIX)) {
            return $name;
        }
        $first = explode('\\', $name)[0];
        $rest = substr($name, strlen($first));
        if (preg_match('/^use\s+([A-Za-z0-9_\\\\]+\\\\' . preg_quote($first, '/') . ')\s*;/m', $source, $use) === 1) {
            return $use[1] . $rest;
        }
        if (preg_match('/^use\s+([A-Za-z0-9_\\\\]+)\s+as\s+' . preg_quote($first, '/') . '\s*;/m', $source, $alias) === 1) {
            return $alias[1] . $rest;
        }

        return $reflection->getNamespaceName() . '\\' . $name;
    }

    /** @return list<class-string> */
    private function implementationsOf(string $type): array
    {
        if ($this->implementations === null) {
            $this->implementations = [];
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->classesDirectory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen(rtrim($this->classesDirectory, '/')) + 1, -4);
                $class = self::NAMESPACE_PREFIX . str_replace('/', '\\', $relative);
                if (!class_exists($class)) {
                    continue;
                }
                $reflection = new \ReflectionClass($class);
                if ($reflection->isAbstract() || $reflection->isInterface()) {
                    continue;
                }
                foreach ([...$reflection->getInterfaceNames(), ...$this->parents($reflection)] as $parent) {
                    $this->implementations[$parent][] = $class;
                }
            }
        }

        return $this->implementations[$type] ?? [];
    }

    /** @return list<string> */
    private function parents(\ReflectionClass $reflection): array
    {
        $parents = [];
        while (($reflection = $reflection->getParentClass()) !== false) {
            $parents[] = $reflection->getName();
        }

        return $parents;
    }

    /** @return list<string> */
    private function typeNames(?\ReflectionType $type): array
    {
        if ($type instanceof \ReflectionNamedType) {
            return $type->isBuiltin() ? [] : [$type->getName()];
        }
        if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
            $names = [];
            foreach ($type->getTypes() as $inner) {
                $names = [...$names, ...$this->typeNames($inner)];
            }

            return $names;
        }

        return [];
    }
}
