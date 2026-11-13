<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Code that runs outside dependency injection (Scheduler tasks, static helpers) fetches AQG services with
 * `GeneralUtility::getContainer()->get(X::class)` / `$container->get(X::class)`. Symfony removes or inlines private
 * services, so every such lookup of an AQG class must be declared `public: true` in Configuration/Services.yaml.
 * Product acceptance D-02: the Scheduler content scan task fetched ScanStatusService (not public) and failed on every run.
 */
final class ContainerLookupPublicServiceTest extends TestCase
{
    #[Test]
    public function everyAqgServiceTakenFromTheContainerIsPublic(): void
    {
        $root = dirname(__DIR__, 3);
        $public = $this->publicServices((string)file_get_contents($root . '/Configuration/Services.yaml'));
        $missing = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/Classes', \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string)file_get_contents($file->getPathname());
            if (preg_match_all('/(?:getContainer\(\)|\$container)->get\(\s*\\\\?([A-Za-z0-9_\\\\]+)::class/', $source, $matches) === 0) {
                continue;
            }
            foreach ($matches[1] as $reference) {
                $class = $this->resolve($reference, $source);
                if (str_starts_with($class, 'Priebera\\A11yQualityGate\\') && !isset($public[$class])) {
                    $missing[] = $class . ' (' . substr($file->getPathname(), strlen($root) + 1) . ')';
                }
            }
        }

        self::assertSame([], array_values(array_unique($missing)), 'Declare these services public: true in Configuration/Services.yaml');
    }

    /** @return array<string, true> */
    private function publicServices(string $yaml): array
    {
        $public = [];
        $current = null;
        foreach (preg_split('/\R/', $yaml) ?: [] as $line) {
            if (preg_match('/^  ([A-Za-z\\\\][A-Za-z0-9\\\\_]+):\s*$/', $line, $match) === 1) {
                $current = $match[1];
                continue;
            }
            if ($current !== null && preg_match('/^\s+public:\s*true\s*$/', $line) === 1) {
                $public[$current] = true;
            }
        }

        return $public;
    }

    private function resolve(string $reference, string $source): string
    {
        if (str_contains($reference, '\\')) {
            return ltrim($reference, '\\');
        }
        if (preg_match('/^use\s+([A-Za-z0-9_\\\\]+\\\\' . preg_quote($reference, '/') . ');/m', $source, $match) === 1) {
            return $match[1];
        }
        if (preg_match('/^use\s+([A-Za-z0-9_\\\\]+)\s+as\s+' . preg_quote($reference, '/') . ';/m', $source, $match) === 1) {
            return $match[1];
        }
        preg_match('/^namespace\s+([^;]+);/m', $source, $namespace);

        return ($namespace[1] ?? '') . '\\' . $reference;
    }
}
