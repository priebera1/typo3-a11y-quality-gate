<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Export;

use Priebera\A11yQualityGate\Service\ScanComparisonService;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Baseline → current acceptance evidence for a client or stakeholder.
 *
 * Two compatible stored scans are compared URL by URL (ScanComparisonService): what was fixed, what is new,
 * what is still open, and what could not be compared. The document states the coverage of both scans and
 * the limits of automated testing; it never claims WCAG or legal conformance. English, like every AQG
 * export.
 */
final class AcceptanceEvidenceBuilder
{
    public const LIMITATIONS = [
        'Automated checks find common accessibility issues. They cannot confirm WCAG conformance or compliance with accessibility law.',
        'Only pages that both scans loaded completely are compared. Pages listed as not compared need a new scan or manual review.',
        'Keyboard use, screen reader output, meaningful text alternatives, reading order and content quality need manual review.',
        'A fixed finding means the automated rule no longer reports it on that URL; it does not prove that the underlying barrier is gone.',
    ];

    public function __construct(
        private readonly ScanComparisonService $scanComparisonService,
        private readonly PdfTemplateRenderer $pdfTemplateRenderer,
        private readonly PdfGenerator $pdfGenerator,
    ) {
    }

    /**
     * @param array<string, mixed> $baseline
     * @param array<string, mixed> $current
     * @return array<string, mixed>
     */
    public function build(Site $site, array $baseline, array $current): array
    {
        $comparison = $this->scanComparisonService->compare($baseline, $current);

        return [
            'site' => [
                'identifier' => $site->getIdentifier(),
                'base' => rtrim((string)$site->getBase(), '/'),
            ],
            'preparedAt' => $this->formatDate(time()),
            'baseline' => $this->describeScan($baseline),
            'current' => $this->describeScan($current),
            'counts' => [
                'fixed' => count($comparison['fixed']),
                'new' => count($comparison['new']),
                'regressed' => count($comparison['regressed']),
                'unresolved' => count($comparison['unresolved']),
                'unverified' => count($comparison['unverified']),
                'comparedPages' => $comparison['comparedPages'],
            ],
            'fixed' => $comparison['fixed'],
            'new' => $comparison['new'],
            'regressed' => $comparison['regressed'],
            'unresolved' => $comparison['unresolved'],
            'unverified' => array_map(
                fn (array $entry): array => $entry + ['reasonLabel' => $this->unverifiedReasonLabel($entry['reason'])],
                $comparison['unverified']
            ),
            'limitations' => self::LIMITATIONS,
        ];
    }

    /**
     * @param array<string, mixed> $evidence
     */
    public function renderPdf(array $evidence, ?ServerRequestInterface $request = null): string
    {
        $html = $this->pdfTemplateRenderer->render(
            templateName: 'Export/AcceptanceEvidencePdf',
            variables: ['evidence' => $evidence],
            request: $request,
        );

        return $this->pdfGenerator->render($html, 'Accessibility acceptance evidence — ' . $evidence['site']['identifier']);
    }

    /**
     * @param array<string, mixed> $evidence
     */
    public function renderCsv(array $evidence): string
    {
        $output = fopen('php://memory', 'r+b');
        if ($output === false) {
            return '';
        }

        // Every row carries the site, scope, language and both scan dates, so a filtered or detached CSV
        // still says what was compared; coverage and the manual-review limitations close the file.
        $context = [
            (string)$evidence['site']['base'],
            (string)$evidence['current']['scope'],
            (string)$evidence['current']['languageUid'],
            (string)$evidence['baseline']['finishedAt'],
            (string)$evidence['current']['finishedAt'],
        ];
        $write = static function (array $row) use ($output, $context): void {
            fputcsv($output, array_map([CsvValueSanitizer::class, 'sanitize'], [...$row, ...$context]), ',', '"', '');
        };

        fputcsv($output, [
            'status', 'url', 'rule', 'impact', 'baseline_occurrences', 'current_occurrences', 'note',
            'site', 'scope', 'language_uid', 'baseline_scan_finished', 'current_scan_finished',
        ], ',', '"', '');
        foreach (['fixed' => 'fixed', 'new' => 'new', 'regressed' => 'worse', 'unresolved' => 'open'] as $bucket => $status) {
            foreach ($evidence[$bucket] as $entry) {
                $write([$status, $entry['url'], $entry['ruleId'], $entry['impact'], (string)$entry['before'], (string)$entry['after'], '']);
            }
        }
        foreach ($evidence['unverified'] as $entry) {
            $write(['not_compared', $entry['url'], '', '', '', '', $entry['reasonLabel']]);
        }
        $write(['coverage', '', '', '', '', '', sprintf(
            'Pages compared: %d. Pages not compared: %d. Prepared %s.',
            (int)$evidence['counts']['comparedPages'],
            (int)$evidence['counts']['unverified'],
            (string)$evidence['preparedAt']
        )]);
        foreach ($evidence['limitations'] as $limitation) {
            $write(['limitation', '', '', '', '', '', (string)$limitation]);
        }

        rewind($output);
        $csv = (string)stream_get_contents($output);
        fclose($output);

        return "\xEF\xBB\xBF" . $csv;
    }

    /**
     * @param array<string, mixed> $scan
     * @return array<string, mixed>
     */
    private function describeScan(array $scan): array
    {
        $pagesScanned = (int)($scan['pages_scanned'] ?? 0);
        $pagesFailed = (int)($scan['pages_failed'] ?? 0);

        return [
            'jobId' => (string)($scan['job_id'] ?? ''),
            'finishedAt' => $this->formatDate((int)($scan['finished_at'] ?? 0)),
            'scope' => (string)($scan['scan_scope'] ?? '') === 'page' ? 'Single page' : 'Site',
            'startUrl' => (string)($scan['start_url'] ?? ''),
            'languageUid' => (int)($scan['language_uid'] ?? -1),
            'pagesScanned' => $pagesScanned,
            'pagesFailed' => $pagesFailed,
            'issuesTotal' => (int)($scan['issues_total'] ?? 0),
        ];
    }

    private function unverifiedReasonLabel(string $reason): string
    {
        return match ($reason) {
            'page_failed' => 'Page failed to load in one of the scans',
            'evidence_incomplete' => 'Results of the page are incomplete in one of the scans',
            'not_in_baseline' => 'Not covered by the baseline scan',
            'not_in_current' => 'Not covered by the current scan',
            default => 'Not compared',
        };
    }

    private function formatDate(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return '—';
        }

        return (new \DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->format('d M Y · H:i T');
    }
}
