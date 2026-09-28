<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Utility\BackendTimeUtility;
use Symfony\Component\Mime\Address;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Mail\MailerInterface;
use TYPO3\CMS\Core\Mail\MailMessage;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MailUtility;

/**
 * The monitoring e-mail: site, scan time, what changed against the baseline scan, and a link into AQG. An
 * incomplete or failed scan says so instead of reporting a regression, and never claims that nothing got
 * worse: pages it could not compare are listed as not compared. The text is English like the PDF exports,
 * since it is sent without a backend user and language.
 */
final class MonitoringNotifier
{
    private const LISTED_ITEMS = 10;

    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly UriBuilder $uriBuilder,
    ) {
    }

    /**
     * @param list<string> $recipients
     * @param array{outcome:string,site:Site,scan:array<string, mixed>,baseline:array<string, mixed>|null,comparison:array<string, mixed>|null,gaps?:list<array{url:string,reason:string}>,backendBaseUrl:string,languageUid:int} $report
     */
    public function send(array $recipients, array $report): bool
    {
        $recipients = array_values(array_filter($recipients, static fn (string $address): bool => GeneralUtility::validEmail($address)));
        if ($recipients === []) {
            return false;
        }

        [$subject, $text] = $this->compose($report);

        try {
            $message = GeneralUtility::makeInstance(MailMessage::class);
            $from = MailUtility::getSystemFrom();
            if (is_array($from) && $from !== []) {
                $address = (string)array_key_first($from);
                $message->from(new Address(is_numeric($address) ? (string)reset($from) : $address, is_numeric($address) ? '' : (string)reset($from)));
            }
            $message->to(...$recipients)->subject($subject)->text($text);
            $this->mailer->send($message);

            return true;
        } catch (\Throwable $exception) {
            GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__)->error('AQG monitoring notification could not be sent', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param array<string, mixed> $report
     * @return array{0:string,1:string}
     */
    public function compose(array $report): array
    {
        /** @var Site $site */
        $site = $report['site'];
        $scan = $report['scan'];
        $comparison = is_array($report['comparison']) ? $report['comparison'] : [];
        $gaps = is_array($report['gaps'] ?? null) ? $report['gaps'] : [];
        $siteLabel = $site->getIdentifier() . ' (' . rtrim((string)$site->getBase(), '/') . ')';
        $scannedAt = BackendTimeUtility::formatDateTime((int)($scan['finished_at'] ?? 0) ?: time());

        $lines = [
            'Site: ' . $siteLabel,
            'Scan finished: ' . $scannedAt,
        ];
        if (is_array($report['baseline'])) {
            $lines[] = 'Baseline: the scan of ' . BackendTimeUtility::formatDateTime((int)($report['baseline']['finished_at'] ?? 0)) . ' (same scope, language and start URL).';
        }
        $lines[] = '';

        switch ($report['outcome']) {
            case RemoteMonitoringService::OUTCOME_FAILED:
                $subject = sprintf('[AQG] Monitoring scan failed: %s', $site->getIdentifier());
                $lines[] = 'The monitoring scan did not complete. This is not a regression: nothing was compared.';
                $lines[] = 'Check that the site is reachable for the AQG scanner, then run the scan again.';
                break;
            case RemoteMonitoringService::OUTCOME_INCOMPLETE:
                $subject = sprintf('[AQG] Monitoring scan incomplete: %s', $site->getIdentifier());
                $lines[] = 'The monitoring scan did not check every page it had to, so AQG cannot say whether issues are new or worse. This is not an all-clear.';
                $lines[] = 'Not checked:';
                $lines = array_merge($lines, $this->listGaps($gaps));
                $lines[] = '';
                $lines[] = 'The baseline stays the last complete scan; the next run compares with it again.';
                break;
            default:
                $subject = sprintf('[AQG] Accessibility regression: %s', $site->getIdentifier());
                $lines[] = sprintf(
                    '%d new and %d worse issue types since the previous scan (%d fixed).',
                    count($comparison['new'] ?? []),
                    count($comparison['regressed'] ?? []),
                    count($comparison['fixed'] ?? [])
                );
                $lines[] = '';
                foreach (array_slice(array_merge($comparison['new'] ?? [], $comparison['regressed'] ?? []), 0, self::LISTED_ITEMS) as $entry) {
                    $lines[] = sprintf(
                        '- [%s] %s on %s (%d → %d occurrences)',
                        $entry['impact'] !== '' ? $entry['impact'] : 'unknown',
                        $entry['ruleId'],
                        $entry['url'],
                        $entry['before'],
                        $entry['after']
                    );
                }
                if ($gaps !== []) {
                    $lines[] = '';
                    $lines[] = 'Some pages were not checked, so this list may be incomplete:';
                    $lines = array_merge($lines, $this->listGaps($gaps));
                }
        }

        $link = $this->buildBackendLink($site, (string)$report['backendBaseUrl'], (int)$report['languageUid']);
        if ($link !== '') {
            $lines[] = '';
            $lines[] = 'Investigate in AQG: ' . $link;
        }
        $lines[] = '';
        $lines[] = 'Automated scans find common accessibility issues; they do not confirm WCAG conformance. Manual review may still be required.';

        return [$subject, implode("\n", $lines) . "\n"];
    }

    /**
     * @param list<array{url:string,reason:string}> $gaps
     * @return list<string>
     */
    private function listGaps(array $gaps): array
    {
        $lines = [];
        foreach (array_slice($gaps, 0, self::LISTED_ITEMS) as $gap) {
            $reason = match ($gap['reason']) {
                'not_in_current' => 'not scanned this time',
                'page_failed' => 'page failed to load',
                'evidence_incomplete' => 'results stored incompletely',
                default => 'the scan returned no checked pages',
            };
            $lines[] = $gap['url'] !== '' ? sprintf('- %s (%s)', $gap['url'], $reason) : sprintf('- %s', $reason);
        }
        if (count($gaps) > self::LISTED_ITEMS) {
            $lines[] = sprintf('- … and %d more', count($gaps) - self::LISTED_ITEMS);
        }

        return $lines;
    }

    private function buildBackendLink(Site $site, string $backendBaseUrl, int $languageUid): string
    {
        try {
            $path = (string)$this->uriBuilder->buildUriFromRoute('web_a11y', [
                'id' => (int)$site->getRootPageId(),
                'site' => $site->getIdentifier(),
                'language' => $languageUid,
            ]);
        } catch (\Throwable) {
            return '';
        }

        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        $base = trim($backendBaseUrl);
        if ($base === '') {
            $parts = parse_url((string)$site->getBase());
            $base = is_array($parts) && isset($parts['host'])
                ? ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '')
                : '';
        }

        return $base === '' ? '' : rtrim($base, '/') . '/' . ltrim($path, '/');
    }
}
