<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Scan;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Domain\Enum\IssueStatus;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleViolation;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * N-06 (final customer acceptance): single-page content scans of a translation failed before their rendered check
 * ("Duplicate entry … for key uniq_site_fingerprint"), recorded no rendered result and left stale findings open. A
 * rendered finding retired as a duplicate of a rich-text finding (deleted = 1) was invisible to the fingerprint lookup,
 * so when it was no longer a duplicate the scan inserted it again and the unique key refused the row. The retired row
 * is the finding: it comes back open instead.
 */
final class RetiredDuplicateRevivalFunctionalTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function aRetiredDuplicateThatIsReportedAgainIsRevivedNotInsertedTwice(): void
    {
        [$violation, $context] = self::finding();
        $repository = $this->get(IssueRepository::class);
        self::assertSame('inserted', $repository->upsert($violation, $context, 1));
        $repository->retireOpenDuplicates('nordhafen', 13, [$violation->fingerprint($context)]);

        self::assertSame('updated', $repository->upsert($violation, $context, 2));

        $rows = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_issue')
            ->select(['deleted', 'status', 'last_seen_scan_uid'], 'tx_a11y_issue', ['fingerprint' => $violation->fingerprint($context)])->fetchAllAssociative();
        self::assertCount(1, $rows);
        self::assertSame(0, (int)$rows[0]['deleted']);
        self::assertSame(IssueStatus::Open->value, (int)$rows[0]['status']);
        self::assertSame(2, (int)$rows[0]['last_seen_scan_uid']);
    }

    #[Test]
    public function ignoringARetiredDuplicateUsesTheSameRow(): void
    {
        [$violation, $context] = self::finding();
        $repository = $this->get(IssueRepository::class);
        $repository->upsert($violation, $context, 1);
        $repository->retireOpenDuplicates('nordhafen', 13, [$violation->fingerprint($context)]);

        $uid = $repository->createIgnoredFromViolation($violation, $context, 'Decorative button', 1, 'Admin', 'admin');

        $row = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_issue')
            ->select(['uid', 'deleted', 'status'], 'tx_a11y_issue', ['fingerprint' => $violation->fingerprint($context)])->fetchAssociative();
        self::assertSame($uid, (int)$row['uid']);
        self::assertSame(0, (int)$row['deleted']);
        self::assertSame(IssueStatus::Ignored->value, (int)$row['status']);
    }

    /** @return array{0: RuleViolation, 1: CheckContext} */
    private static function finding(): array
    {
        $context = new CheckContext(
            siteIdentifier: 'nordhafen',
            pageUid: 13,
            sourceLangUid: 1,
            sourceTable: 'pages',
            sourceUid: 13,
            sourceField: '__rendered_html',
            content: '',
            cType: '',
            contextPath: 'Page:13 > rendered HTML',
            sourceType: 'rendered',
        );
        $violation = new RuleViolation(
            ruleId: 'rendered.empty_button',
            severity: Severity::Critical,
            message: 'Rendered button has no accessible name.',
            hint: '',
            contextSnippet: '<button></button>',
            contextPath: 'html > body > button',
            sourceType: 'rendered',
            frontendUrl: 'https://nordhafen.invalid/de/',
            cssSelector: 'button',
            sourceTable: 'tt_content',
            sourceUid: 27,
            sourceField: 'bodytext',
        );

        return [$violation, $context];
    }
}
