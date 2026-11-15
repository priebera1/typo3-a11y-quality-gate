<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Remediation;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationPageSummaryService;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationStatus;
use Priebera\A11yQualityGate\Rendered\RenderedHtmlRuleRegistry;
use Priebera\A11yQualityGate\Rule\RuleRegistry;
use Priebera\A11yQualityGate\Service\Contract\RuleMetadataPresentationServiceInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * AI fix coverage (AQG 3.0.0 release gate): every built-in rule has a remediation path with rule-specific guidance in
 * English and German; the observed Plain HTML "Click here" link and an image in rich text are applied through the
 * reviewed direct apply; an exhausted budget sends nothing and writes nothing.
 */
final class RemediationCoverageFunctionalTest extends AbstractDirectApplyFunctionalTestCase
{
    private const PRIEBERA = '<p>Our partner for web development:</p>' . "\n" . '<a href="https://www.priebera.sk">Click here</a>';
    private const TEAM = '<p>Our team in front of the bakery.</p><img src="fileadmin/aqg/team.png" width="64" height="48" class="photo">';
    private const LINKED = '<p><img src="fileadmin/aqg/team.png" alt="Our team"> Meet us: <a href="/contact"><img src="fileadmin/aqg/team.png" width="32"></a></p>';

    protected function setUp(): void
    {
        parent::setUp();
        @mkdir($this->instancePath . '/fileadmin/aqg', 0777, true);
        $image = imagecreatetruecolor(64, 48);
        imagefill($image, 0, 0, (int)imagecolorallocate($image, 200, 150, 60));
        imagepng($image, $this->instancePath . '/fileadmin/aqg/team.png');
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pool->getConnectionForTable('sys_file_storage')->insert('sys_file_storage', ['uid' => 1, 'pid' => 0, 'name' => 'fileadmin', 'driver' => 'Local', 'is_browsable' => 1, 'is_public' => 1, 'is_writable' => 1, 'is_online' => 1,
            'configuration' => '<?xml version="1.0" encoding="utf-8" standalone="yes" ?><T3FlexForms><data><sheet index="sDEF"><language index="lDEF"><field index="basePath"><value index="vDEF">fileadmin/</value></field><field index="pathType"><value index="vDEF">relative</value></field><field index="caseSensitive"><value index="vDEF">1</value></field></language></sheet></data></T3FlexForms>']);
        $content = $pool->getConnectionForTable('tt_content');
        $content->insert('tt_content', ['uid' => 40, 'pid' => 1, 'CType' => 'html', 'header' => 'Partner', 'bodytext' => self::PRIEBERA, 'tstamp' => 1000]);
        $content->insert('tt_content', ['uid' => 41, 'pid' => 1, 'CType' => 'text', 'header' => 'Team', 'bodytext' => self::TEAM, 'tstamp' => 1000]);
        $content->insert('tt_content', ['uid' => 42, 'pid' => 1, 'CType' => 'text', 'header' => 'Linked image', 'bodytext' => self::LINKED, 'tstamp' => 1000]);
        $this->finding(40, 'rte.non_descriptive_link', self::PRIEBERA, 0, 'html');
        $this->finding(41, 'rte.img_alt_missing', self::TEAM);
        $this->finding(42, 'rte.image_in_link_missing_alt', self::LINKED);
    }

    #[Test]
    public function theAltTextOfALinkedImageGoesToThatImageNotToTheLinkOrAnotherImage(): void
    {
        $this->login(self::ADMIN);
        $this->answer = ['status' => 'suggestion', 'value' => 'Contact', 'reason' => 'The link leads to the contact page.'];
        $item = $this->generateAndView(42);

        $applied = $this->workflow()->approve((int)$item['uid'], (int)$item['row_version'], false, '', true);

        self::assertSame(RemediationStatus::VERIFIED, $applied['status'], (string)$applied['status_reason']);
        self::assertSame(str_replace('width="32">', 'width="32" alt="Contact">', self::LINKED), $this->bodytext(42));
    }

    #[Test]
    public function theObservedPlainHtmlLinkIsAppliedInsteadOfCopied(): void
    {
        $this->login(self::EDITOR);
        $this->answer = ['status' => 'suggestion', 'value' => 'Visit the Priebera website', 'reason' => 'The link leads to priebera.sk.'];
        $item = $this->generateAndView(40);
        self::assertSame('server', $this->presented((int)$item['uid'])['applyMode'], 'Page Detail offers Apply and verify for it.');

        $applied = $this->workflow()->approve((int)$item['uid'], (int)$item['row_version'], false, '', true);

        self::assertSame(RemediationStatus::VERIFIED, $applied['status'], (string)$applied['status_reason']);
        self::assertSame(str_replace('>Click here</a>', '>Visit the Priebera website</a>', self::PRIEBERA), $this->bodytext(40));
        self::assertStringContainsString('www.priebera.sk', $this->requests[0]->inputText, 'The destination is the basis, not the page.');
    }

    #[Test]
    public function anImageAltTextInRichTextIsAppliedToThatImage(): void
    {
        $this->login(self::ADMIN);
        $this->answer = ['status' => 'suggestion', 'value' => 'Four bakers in white aprons in front of the shop', 'reason' => 'r'];
        $item = $this->generateAndView(41);

        $applied = $this->workflow()->approve((int)$item['uid'], (int)$item['row_version'], false, '', true);

        self::assertSame(RemediationStatus::VERIFIED, $applied['status'], (string)$applied['status_reason']);
        self::assertSame(str_replace('class="photo">', 'class="photo" alt="Four bakers in white aprons in front of the shop">', self::TEAM), $this->bodytext(41));
        self::assertNotNull($this->requests[0]->image, 'The image itself was the basis of the text.');
    }

    #[Test]
    public function anExhaustedBudgetSendsNothingAndWritesNothing(): void
    {
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pool->getConnectionForTable(Tables::AI_SITE_SETTING)->insert(Tables::AI_SITE_SETTING, ['site_identifier' => 'test', 'budget_micro_usd' => 1_000, 'budget_hard_stop' => 1]);
        $pool->getConnectionForTable(Tables::AI_USAGE)->insert(Tables::AI_USAGE, ['site_identifier' => 'test', 'crdate' => time() - 10, 'feature' => 'link_text', 'provider' => 'anthropic',
            'model' => 'claude-haiku-5-5', 'outcome' => 'success', 'estimated_cost_micro_usd' => 5_000, 'cost_known' => 1, 'cost_basis' => 'reported']);
        $this->login(self::ADMIN);
        $this->answer = ['status' => 'suggestion', 'value' => 'Directions and parking', 'reason' => 'r'];

        try {
            $prepared = $this->workflow()->prepare([$this->findings[10]], \Priebera\A11yQualityGate\Remediation\Workflow\RemediationAccessService::ORIGIN_EDITOR_REQUEST)['items'][0];
            if (($prepared['outcome'] ?? '') === 'queued') {
                $item = $this->item((int)$prepared['itemUid']);
                try {
                    $this->workflow()->generate((int)$item['uid'], (int)$item['row_version']);
                } catch (\Throwable) {
                }
                self::assertNotSame(RemediationStatus::PENDING_REVIEW, $this->item((int)$item['uid'])['status']);
            } else {
                self::assertSame('budget_reached', $prepared['reason'] ?? '');
            }
        } catch (\Priebera\A11yQualityGate\Remediation\Workflow\RemediationException $exception) {
            self::assertSame('budget_reached', $exception->safeCode);
        }
        self::assertSame([], $this->requests, 'No provider request.');
        self::assertSame(self::CONTACT, $this->bodytext(10));
    }

    #[Test]
    public function everyBuiltInRuleHasARemediationPathAndRuleSpecificGuidance(): void
    {
        $ids = array_merge(
            array_map(static fn ($rule): string => $rule->getRuleId(), $this->get(RuleRegistry::class)->getAll()),
            array_map(static fn ($rule): string => $rule->getRuleId(), array_values((array)(new \ReflectionProperty(RenderedHtmlRuleRegistry::class, 'rules'))->getValue($this->get(RenderedHtmlRuleRegistry::class)))),
        );
        $ids = array_values(array_unique($ids));
        self::assertCount(48, $ids, implode(', ', $ids));
        $presentation = $this->get(RuleMetadataPresentationServiceInterface::class);
        $summary = $this->get(RemediationPageSummaryService::class);
        foreach ($ids as $id) {
            $source = str_starts_with($id, 'rendered.') ? 'rendered' : (str_starts_with($id, 'structured.') ? 'structured' : 'rte');
            $path = $summary->fixPath(['rule_id' => $id, 'source_type' => $source, 'context_path' => '']);
            self::assertContains($path, [RemediationPageSummaryService::FIX_AI_TEXT, RemediationPageSummaryService::FIX_AI_IMAGE, RemediationPageSummaryService::FIX_RULE_EDITOR,
                RemediationPageSummaryService::FIX_DEVELOPER, RemediationPageSummaryService::FIX_MANUAL], $id);
            foreach (['en', 'de'] as $language) {
                $guidance = $presentation->present(['rule_id' => $id], $language);
                self::assertNotSame('', trim((string)$guidance['howToFix']), $id . ' (' . $language . '): no fix guidance');
                self::assertNotSame('', trim((string)$guidance['title']), $id . ' (' . $language . '): no title');
            }
        }
    }

    /** @return array<string,mixed> */
    private function presented(int $itemUid): array
    {
        return $this->get(\Priebera\A11yQualityGate\Remediation\Workflow\RemediationItemPresenter::class)->present($this->item($itemUid));
    }
}
