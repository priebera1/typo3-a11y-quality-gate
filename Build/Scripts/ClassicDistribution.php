<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Build;

/**
 * F12 / F11-01: the Classic/TER distribution's composer.json, derived from the Composer source manifest.
 *
 * AQG ships two artefacts from one commit (Build/Scripts/build-distributions.sh):
 *
 *   * Composer source — the commit itself (git archive; what Packagist installs). Its composer.json requires
 *     `mpdf/mpdf`, so Composer installations get PDF export.
 *   * Classic/TER — the same tree with only composer.json changed by this transform: the Composer-only runtime
 *     requirements (mPDF) are removed. Nothing is bundled, nothing is provided, every other byte is identical.
 *
 * Why two manifests: TYPO3 14 Classic mode reads an extension's dependencies from composer.json alone when it declares
 * `extra.typo3/cms.version` and `Package.providesPackages` (PackageManager::isComposerOnlyCapable()). A `require` that
 * is no TYPO3 package then blocks activation unless it is "installed". Declaring mPDF in `providesPackages` (F11) made
 * TYPO3 register it globally (PackageManager::scanPackagePathsForExtensions() → `installedPackageNames`), which also
 * satisfied — and so hid — another extension's real mPDF requirement while the library was absent. AQG never provides a
 * third-party package: `providesPackages` stays empty in both manifests.
 *
 * The transform fails closed: a new third-party runtime requirement is not silently dropped from Classic mode; it must
 * be added to COMPOSER_ONLY_REQUIREMENTS (a product decision) or declared otherwise.
 */
final class ClassicDistribution
{
    /** Runtime requirements that exist only in Composer installations, and why AQG works without them in Classic mode. */
    public const COMPOSER_ONLY_REQUIREMENTS = [
        'mpdf/mpdf' => 'PDF export is Composer-only; the PDF routes and controls are gated by PdfGenerator::isAvailable().',
    ];

    /**
     * @return string the Classic/TER composer.json (same formatting as the source, trailing newline)
     * @throws \UnexpectedValueException when the source manifest does not meet the distribution contract
     */
    public static function classicManifest(string $sourceJson): string
    {
        $manifest = json_decode($sourceJson, false, 512, JSON_THROW_ON_ERROR);
        if (!$manifest instanceof \stdClass) {
            throw new \UnexpectedValueException('composer.json is not an object.');
        }
        if (self::encode($manifest) !== $sourceJson) {
            throw new \UnexpectedValueException('composer.json is not in canonical format (4-space JSON, unescaped slashes/unicode, trailing newline); the Classic manifest would differ in more than its requirements.');
        }
        $typo3 = $manifest->extra->{'typo3/cms'} ?? null;
        if (!$typo3 instanceof \stdClass || !isset($typo3->version, $typo3->{'extension-key'})) {
            throw new \UnexpectedValueException('extra.typo3/cms.version and extension-key are required.');
        }
        $provides = $typo3->Package->providesPackages ?? null;
        if (!$provides instanceof \stdClass || get_object_vars($provides) !== []) {
            throw new \UnexpectedValueException('extra.typo3/cms.Package.providesPackages must be an empty object: AQG provides no third-party package (it would satisfy other extensions\' requirements globally in Classic mode).');
        }
        $require = $manifest->require ?? null;
        if (!$require instanceof \stdClass) {
            throw new \UnexpectedValueException('require is missing.');
        }

        $removed = [];
        foreach (array_keys(get_object_vars($require)) as $package) {
            if (self::isPlatformOrFramework($package)) {
                continue;
            }
            if (!array_key_exists($package, self::COMPOSER_ONLY_REQUIREMENTS)) {
                throw new \UnexpectedValueException(sprintf('Third-party requirement "%s" is not declared Composer-only; decide how Classic mode handles it before building the TER artefact.', $package));
            }
            unset($require->{$package});
            $removed[] = $package;
        }
        $missing = array_diff(array_keys(self::COMPOSER_ONLY_REQUIREMENTS), $removed);
        if ($missing !== []) {
            throw new \UnexpectedValueException('Composer-only requirement missing from the source manifest: ' . implode(', ', $missing) . ' (Composer installations would lose it).');
        }

        return self::encode($manifest);
    }

    public static function isPlatformOrFramework(string $package): bool
    {
        return $package === 'php' || $package === 'composer-runtime-api' || str_starts_with($package, 'ext-')
            || str_starts_with($package, 'typo3/cms-');
    }

    private static function encode(\stdClass $manifest): string
    {
        return json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    }
}

// CLI: php ClassicDistribution.php < composer.json > composer.classic.json
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    try {
        fwrite(STDOUT, ClassicDistribution::classicManifest((string)stream_get_contents(STDIN)));
    } catch (\Throwable $exception) {
        fwrite(STDERR, 'Classic manifest: ' . $exception->getMessage() . "\n");
        exit(1);
    }
}
