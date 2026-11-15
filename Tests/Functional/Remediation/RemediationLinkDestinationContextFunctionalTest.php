<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Remediation;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiContextBuilder;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationStatus;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Link text context (AQG 3.0.0, observed case C: an empty link to /r1 got the title of the page it was on). Links into
 * the site are resolved to their TYPO3 page; a path that is no page of the site is said to be unknown; the title of
 * the page the link is on is never sent as a basis for link text.
 */
final class RemediationLinkDestinationContextFunctionalTest extends AbstractDirectApplyFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $content = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tt_content');
        $content->insert('tt_content', ['uid' => 30, 'pid' => 1, 'CType' => 'text', 'header' => 'Unknown target', 'bodytext' => '<p>Teaser <a href="/r1"></a></p>', 'tstamp' => 1000]);
        $this->finding(30, 'rte.empty_link', '<p>Teaser <a href="/r1"></a></p>');
    }

    #[Test]
    public function aLinkIntoTheSiteIsResolvedToItsPage(): void
    {
        $this->login(self::ADMIN);
        $builder = $this->get(EditorAiContextBuilder::class);

        self::assertSame(['internal TYPO3 page /contact', 'Contact and directions'], $builder->linkTarget('/contact', true, 'test'));
        self::assertSame(['internal TYPO3 page /contact', 'Contact and directions'], $builder->linkTarget('https://example.test/contact', true, 'test'), 'an address on the site host');
        self::assertSame(['internal TYPO3 page /contact', ''], $builder->linkTarget('/contact', false, 'test'), 'no title without the site\'s page-title permission');
        self::assertSame(['https://other.example.test/contact', ''], $builder->linkTarget('https://other.example.test/contact', true, 'test'), 'another host is not this site');
        self::assertStringContainsString('destination unknown', $builder->linkTarget('/r1', true, 'test')[0]);
        self::assertSame(['/contact', ''], [$builder->linkTarget('/contact?tx_news[id]=5', true, 'test')[0], ''], 'a plugin record is not the page');
    }

    #[Test]
    public function anUnknownDestinationIsSentAsUnknownAndThePageTitleIsNotSent(): void
    {
        $this->login(self::ADMIN);
        $this->answer = ['status' => 'needs_review', 'value' => '', 'reason' => 'Where /r1 leads could not be determined.'];

        $item = $this->generateAndView(30, false);

        self::assertSame(RemediationStatus::NO_SUGGESTION, $item['status']);
        self::assertCount(1, $this->requests);
        self::assertStringContainsString('destination unknown', $this->requests[0]->inputText);
        self::assertStringNotContainsString('Bakery', $this->requests[0]->inputText, 'The title of the page the link is on is not offered.');
        self::assertSame('Where /r1 leads could not be determined.', $item['explanation']);
    }
}
