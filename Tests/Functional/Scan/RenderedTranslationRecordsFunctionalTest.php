<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Scan;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Domain\Enum\IssueStatus;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Scan\IssuePersistenceCoordinator;
use Priebera\A11yQualityGate\Rule\RuleViolation;
use Priebera\A11yQualityGate\Scan\RenderedTranslationRecords;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * N-02 (final customer acceptance): the frontend renders a translated content element with the default record's uid
 * in AQG's marker ({field:uid} after the language overlay). Rendered findings of a German page were therefore stored
 * against the English record: not deduplicated against the German rich-text findings (doubled counts in My issues),
 * and "Edit record" opened the English record. The rendered language's own translation record is used instead.
 */
final class RenderedTranslationRecordsFunctionalTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $content = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tt_content');
        foreach ([
            [10, 0, 0, 0],   // default record
            [20, 1, 10, 0],  // its German translation (connected mode)
            [21, 2, 10, 1],  // a hidden Polish translation: the frontend falls back to the default record
            [30, 1, 0, 0],   // a German record without a default (free mode): its uid is already the rendered record
            [40, 0, 0, 0],   // a default record without a German translation
        ] as [$uid, $language, $parent, $hidden]) {
            $content->insert('tt_content', ['uid' => $uid, 'pid' => 1, 'sys_language_uid' => $language, 'l18n_parent' => $parent, 'hidden' => $hidden, 'CType' => 'text']);
        }
    }

    #[Test]
    public function aRenderedFindingOfATranslationBelongsToTheTranslationRecord(): void
    {
        $mapped = $this->service()->forLanguage([
            self::violation('tt_content', 10),
            self::violation('tt_content', 30),
            self::violation('tt_content', 40),
            self::violation('pages', 1),
        ], 1);

        self::assertSame([20, 30, 40, 1], array_map(static fn (RuleViolation $v): int => $v->sourceUid, $mapped));
        self::assertSame('rendered.empty_link', $mapped[0]->ruleId, 'Only the record changes.');
        self::assertSame('<a href="/de/"></a>', $mapped[0]->contextSnippet);
    }

    #[Test]
    public function theDefaultLanguageAndHiddenTranslationsKeepTheRenderedRecord(): void
    {
        $service = $this->service();
        self::assertSame(10, $service->forLanguage([self::violation('tt_content', 10)], 0)[0]->sourceUid);
        self::assertSame(10, $service->forLanguage([self::violation('tt_content', 10)], 2)[0]->sourceUid, 'A hidden translation is not what the visitor saw.');
    }

    #[Test]
    public function aFindingStoredOnTheDefaultRecordKeepsItsIdentityAndDecision(): void
    {
        // Review F-01: the remap must not turn an existing finding into a new one (CLAUDE.md: identity survives a
        // format change). An ignored German finding stored on the English record stays the same, ignored row.
        $context = new CheckContext(siteIdentifier: 'main', pageUid: 1, sourceLangUid: 1, sourceTable: 'pages', sourceUid: 1,
            sourceField: '__rendered_html', content: '', cType: '', contextPath: 'Page:1 > rendered HTML', sourceType: 'rendered');
        $repository = $this->get(IssueRepository::class);
        $before = self::violation('tt_content', 10);
        $repository->upsert($before, $context, 1);
        $uid = (int)$repository->findByFingerprintForSite($before->fingerprint($context), 'main')['uid'];
        $repository->ignore($uid, 'Decorative', 1);

        $after = $this->service()->forLanguage([$before], 1);
        $coordinator = $this->get(IssuePersistenceCoordinator::class);
        $coordinator->keepIdentityOfRemapped([$before], $after, $context);

        self::assertSame('protected', $repository->upsert($after[0], $context, 2));
        $rows = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_issue')
            ->select(['uid', 'source_uid', 'status'], 'tx_a11y_issue', ['deleted' => 0])->fetchAllAssociative();
        self::assertCount(1, $rows, 'No second row for the same element.');
        self::assertSame([$uid, 20, IssueStatus::Ignored->value], [(int)$rows[0]['uid'], (int)$rows[0]['source_uid'], (int)$rows[0]['status']]);
    }

    private function service(): RenderedTranslationRecords
    {
        return new RenderedTranslationRecords($this->get(ConnectionPool::class));
    }

    private static function violation(string $table, int $uid): RuleViolation
    {
        return new RuleViolation(
            ruleId: 'rendered.empty_link',
            severity: Severity::Critical,
            message: 'Rendered link has no accessible name.',
            hint: '',
            contextSnippet: '<a href="/de/"></a>',
            contextPath: 'html > body > a',
            sourceType: 'rendered',
            frontendUrl: 'https://example.invalid/de/',
            cssSelector: 'a',
            sourceTable: $table,
            sourceUid: $uid,
            sourceField: $table === 'tt_content' ? 'bodytext' : '',
        );
    }
}
