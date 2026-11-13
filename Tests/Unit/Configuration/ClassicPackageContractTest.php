<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Build\ClassicDistribution;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

require_once dirname(__DIR__, 3) . '/Build/Scripts/ClassicDistribution.php';

/**
 * Classic mode (TER) is supported on TYPO3 13 and 14; PDF export is Composer-only (owner decision, I-03 option B).
 *
 * F11-01 (independent F11 review): F11 declared mpdf/mpdf in `extra.typo3/cms.Package.providesPackages` so that TYPO3 14
 * Classic would activate AQG. TYPO3 registers every declared provision globally (PackageManager::
 * scanPackagePathsForExtensions() → installedPackageNames → isComposerDependency()), so AQG's manifest also satisfied
 * another extension's real mPDF requirement while the library was absent. F12 ships two manifests from one commit
 * (Build/Scripts/build-distributions.sh): the Composer source requires mPDF and provides nothing; the Classic/TER
 * artefact's composer.json is the source minus the Composer-only requirement (Build/Scripts/ClassicDistribution.php).
 */
final class ClassicPackageContractTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private function sourceJson(): string
    {
        return (string)file_get_contents($this->root() . '/composer.json');
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        return json_decode($json, true, 64, JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function aqgProvidesNoThirdPartyPackage(): void
    {
        $manifest = json_decode($this->sourceJson(), false, 64, JSON_THROW_ON_ERROR);
        $provides = $manifest->extra->{'typo3/cms'}->Package->providesPackages ?? null;

        self::assertInstanceOf(\stdClass::class, $provides, 'providesPackages stays declared (TYPO3 14 reads composer.json)');
        self::assertSame([], get_object_vars($provides), 'a provision would satisfy other extensions\' requirements globally in Classic mode');
        self::assertArrayNotHasKey('provide', $this->decode($this->sourceJson()), 'no Composer provide rule');
        self::assertArrayNotHasKey('replace', $this->decode($this->sourceJson()), 'no Composer replace rule');
    }

    #[Test]
    public function composerInstallationsStillRequireMpdf(): void
    {
        self::assertSame('^8.2', $this->decode($this->sourceJson())['require']['mpdf/mpdf'] ?? null, 'Composer installs mPDF (PDF export)');
    }

    #[Test]
    public function theClassicManifestDiffersOnlyByTheComposerOnlyRequirement(): void
    {
        $source = $this->decode($this->sourceJson());
        $classicJson = ClassicDistribution::classicManifest($this->sourceJson());
        $classic = $this->decode($classicJson);

        self::assertArrayNotHasKey('mpdf/mpdf', $classic['require']);
        $expected = $source;
        unset($expected['require']['mpdf/mpdf']);
        self::assertSame($expected, $classic, 'every other key, value and order is the source\'s');
        self::assertSame([], array_values(array_filter(
            array_keys($classic['require']),
            static fn (string $package): bool => !ClassicDistribution::isPlatformOrFramework($package)
        )), 'Classic mode requires only PHP and TYPO3 packages');
        self::assertSame([], get_object_vars(json_decode($classicJson)->extra->{'typo3/cms'}->Package->providesPackages));
        self::assertSame(
            ['-        "typo3/cms-scheduler": "^13.4 || ^14.3",', '-        "mpdf/mpdf": "^8.2"', '+        "typo3/cms-scheduler": "^13.4 || ^14.3"'],
            $this->lineDiff($this->sourceJson(), $classicJson)
        );
    }

    #[Test]
    public function theTransformFailsClosed(): void
    {
        $source = json_decode($this->sourceJson(), false, 64, JSON_THROW_ON_ERROR);
        $encode = static fn (\stdClass $manifest): string => json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";

        $providing = json_decode($this->sourceJson(), false, 64, JSON_THROW_ON_ERROR);
        $providing->extra->{'typo3/cms'}->Package->providesPackages = (object)['mpdf/mpdf' => ''];
        $this->assertRefused($encode($providing), 'providesPackages must be an empty object');

        $unknown = json_decode($this->sourceJson(), false, 64, JSON_THROW_ON_ERROR);
        $unknown->require->{'vendor/new-library'} = '^1.0';
        $this->assertRefused($encode($unknown), 'vendor/new-library');

        $missing = json_decode($this->sourceJson(), false, 64, JSON_THROW_ON_ERROR);
        unset($missing->require->{'mpdf/mpdf'});
        $this->assertRefused($encode($missing), 'mpdf/mpdf');

        $this->assertRefused(str_replace('    ', "\t", $this->sourceJson()), 'canonical format');
        self::assertSame($this->sourceJson(), $encode($source), 'the source manifest is canonical');
    }

    #[Test]
    public function bothArtefactsAreBuiltFromOneCommitByTheBuildScript(): void
    {
        $script = $this->root() . '/Build/Scripts/build-distributions.sh';
        self::assertFileExists($script);
        self::assertTrue(is_executable($script), 'build-distributions.sh is executable');
        $code = (string)file_get_contents($script);
        self::assertStringContainsString('git -C "$repo" archive --format=zip -o "$composer_zip" "$commit"', $code, 'the Composer artefact is the commit itself');
        self::assertStringContainsString('"$commit:Build/Scripts/ClassicDistribution.php"', $code, 'the Classic manifest comes from the commit\'s own transform');
        self::assertStringContainsString('[ "$changed" = "composer.json" ]', $code, 'the Classic tree differs only in composer.json');
    }

    #[Test]
    public function mpdfIsNotBundledAndTypo313ReadsExtEmconf(): void
    {
        self::assertDirectoryDoesNotExist($this->root() . '/vendor', 'the extension ships no vendor directory');
        self::assertDirectoryDoesNotExist($this->root() . '/Resources/Private/PHP/mpdf', 'mPDF is not bundled for Classic mode');
        self::assertFileExists($this->root() . '/ext_emconf.php', 'TYPO3 13 Classic reads ext_emconf.php');
        $EM_CONF = [];
        $_EXTKEY = 'a11y_quality_gate';
        include $this->root() . '/ext_emconf.php';
        self::assertSame($this->decode($this->sourceJson())['extra']['typo3/cms']['version'], $EM_CONF[$_EXTKEY]['version'] ?? null);
        self::assertSame(['typo3', 'php'], array_keys($EM_CONF[$_EXTKEY]['constraints']['depends'] ?? []), 'no mPDF dependency in Classic TYPO3 13');
    }

    private function assertRefused(string $json, string $reason): void
    {
        try {
            ClassicDistribution::classicManifest($json);
            self::fail('refused: ' . $reason);
        } catch (\UnexpectedValueException $exception) {
            self::assertStringContainsString($reason, $exception->getMessage());
        }
    }

    /** @return list<string> */
    private function lineDiff(string $a, string $b): array
    {
        $old = explode("\n", $a);
        $new = explode("\n", $b);
        $diff = [];
        foreach ($old as $line) {
            if (!in_array($line, $new, true)) {
                $diff[] = '-' . $line;
            }
        }
        foreach ($new as $line) {
            if (!in_array($line, $old, true)) {
                $diff[] = '+' . $line;
            }
        }

        return $diff;
    }
}
