<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Export;

use Priebera\A11yQualityGate\Utility\BackendTimeUtility;

/**
 * The one filename convention of AQG's PDF and CSV exports:
 * `aqg-{content|frontend}-{overview|detail|acceptance}-{subject}-{Y-m-d}-{Hi}.{ext}`.
 *
 * The subject is the site identifier for overviews and acceptance evidence, and the TYPO3 page UID for a detail report.
 * The time (backend time zone) keeps two exports of one day apart. Every component is reduced to `[a-z0-9-]`, so a
 * site identifier never breaks the Content-Disposition header or a file system.
 */
final class ExportFilename
{
    private const SOURCES = ['content', 'frontend'];
    private const VIEWS = ['overview', 'detail', 'acceptance'];
    private const EXTENSIONS = ['pdf', 'csv', 'txt'];

    public static function build(string $source, string $view, string $subject, int $timestamp, string $extension): string
    {
        $source = self::component($source);
        $view = self::component($view);
        $extension = self::component($extension);
        $subject = self::component($subject);

        return implode('-', [
            'aqg',
            in_array($source, self::SOURCES, true) ? $source : 'content',
            in_array($view, self::VIEWS, true) ? $view : 'overview',
            $subject !== '' ? substr($subject, 0, 60) : 'export',
            BackendTimeUtility::formatDateTime(max(1, $timestamp), 'Y-m-d-Hi'),
        ]) . '.' . (in_array($extension, self::EXTENSIONS, true) ? $extension : 'txt');
    }

    private static function component(string $value): string
    {
        return trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($value))), '-');
    }
}
