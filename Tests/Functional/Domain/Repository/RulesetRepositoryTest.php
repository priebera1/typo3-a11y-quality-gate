<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Domain\Repository;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Domain\Repository\RulesetRepository;
use Priebera\A11yQualityGate\QualityGate\QualityGateChecker;
use Priebera\A11yQualityGate\Service\RuleConfigurationService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * A site row holds two settings: the site's Quality Gate override and its Remote Scan Access (Scanner Token,
 * HTTP auth, crawl lists). Removing the override used to delete the Scanner Token with it, saving Remote Scan
 * Access created an override ("Warn editors") nobody chose, and an override made the site ignore Settings > Rules.
 */
final class RulesetRepositoryTest extends AbstractFunctionalTestCase
{
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private RulesetRepository $subject;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new RulesetRepository(GeneralUtility::makeInstance(ConnectionPool::class));
        // Per-site scope: a global block gate that site overrides may replace.
        $this->subject->saveForSiteOrDefault('', publishMode: 2, thresholdCritical: 0, thresholdWarning: -1, isGlobal: false);
    }

    #[Test]
    public function removingAGateOverrideKeepsTheSitesRemoteScanAccess(): void
    {
        $this->subject->saveForSiteOrDefault('main', publishMode: 0, thresholdCritical: 5, thresholdWarning: -1, isGlobal: false);
        $this->subject->saveRemoteScanAccessForSiteOrDefault('main', self::TOKEN, 'crawler', 'secret', '["/private/*"]', '["#accept"]', '[]');

        $this->subject->deleteSiteSpecificExcept([]);

        $site = $this->subject->findBySiteIdentifier('main');
        self::assertIsArray($site, 'The site keeps its Remote Scan Access settings.');
        self::assertSame(self::TOKEN, $site['scanner_token']);
        self::assertSame('crawler', $site['http_auth_user']);
        self::assertSame('["/private/*"]', $site['excluded_patterns']);
        self::assertSame([], $this->siteIdentifiers($this->subject->findSiteSpecificRulesets()), 'The override itself is gone.');
        self::assertSame(2, (int)$this->subject->findForSiteOrDefault('main')['publish_mode'], 'The site uses the global gate again.');
    }

    #[Test]
    public function removingAnOverrideWithoutRemoteScanAccessDeletesTheRow(): void
    {
        $this->subject->saveForSiteOrDefault('main', publishMode: 1, thresholdCritical: 0, thresholdWarning: -1, isGlobal: false);

        $this->subject->deleteSiteSpecificExcept([]);

        self::assertNull($this->subject->findBySiteIdentifier('main'));
    }

    #[Test]
    public function savingRemoteScanAccessOrATokenCreatesNoGateOverride(): void
    {
        $this->subject->saveRemoteScanAccessForSiteOrDefault('main', '', 'crawler', null, '[]', '[]', '[]');
        $this->subject->saveScannerTokenForSiteOrDefault('other', self::TOKEN);

        self::assertSame([], $this->siteIdentifiers($this->subject->findSiteSpecificRulesets()));
        self::assertSame('', $this->subject->findForSiteOrDefault('main')['site_identifier']);
        self::assertSame('', $this->subject->findForSiteOrDefault('other')['site_identifier']);
        self::assertSame(self::TOKEN, $this->subject->findBySiteIdentifier('other')['scanner_token']);
    }

    #[Test]
    public function addingAnOverrideAgainKeepsTheScannerToken(): void
    {
        $this->subject->saveScannerTokenForSiteOrDefault('main', self::TOKEN);

        $this->subject->saveForSiteOrDefault('main', publishMode: 0, thresholdCritical: 0, thresholdWarning: -1, isGlobal: false);

        self::assertSame(['main'], $this->siteIdentifiers($this->subject->findSiteSpecificRulesets()));
        self::assertSame(self::TOKEN, $this->subject->findForSiteOrDefault('main')['scanner_token']);
        self::assertSame(0, (int)$this->subject->findForSiteOrDefault('main')['publish_mode']);
    }

    #[Test]
    public function rulesFromSettingsApplyToASiteWithAGateOverride(): void
    {
        $this->subject->saveRulesJsonForDefault('{"disabledRules":["rte.non_descriptive_link"]}');
        $this->subject->saveForSiteOrDefault('main', publishMode: 2, thresholdCritical: 0, thresholdWarning: 0, isGlobal: false);
        $this->insertIssue(['site_identifier' => 'main', 'page_uid' => 7, 'rule_id' => 'rte.non_descriptive_link', 'severity' => 2]);

        $rules = new RuleConfigurationService($this->subject);
        self::assertSame(['rte.non_descriptive_link'], $rules->getDisabledRuleIdsForSite('main'));

        $verdict = (new QualityGateChecker($this->createIssueRepository(), $this->subject))->check(7, 'main', 0);
        self::assertTrue($verdict->isPassed(), 'A rule disabled in Settings > Rules must not count for the gate.');
    }

    /**
     * @param list<array<string, mixed>> $rulesets
     * @return list<string>
     */
    private function siteIdentifiers(array $rulesets): array
    {
        return array_map(static fn (array $ruleset): string => (string)$ruleset['site_identifier'], $rulesets);
    }
}
