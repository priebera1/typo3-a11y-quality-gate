<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Pro;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Pro\ViewModel\LicenceGuidance;

/**
 * An invalid licence must lead to the step that can fix it, never to a generic dead end: an ended trial
 * to the plans, an expired licence to the renewal, a domain problem to the domain management, and an
 * outage to a retry — never to a purchase.
 */
final class LicenceGuidanceTest extends TestCase
{
    private const URLS = [
        LicenceGuidance::ACTION_RETRY => '/typo3/module/web/a11y/settings?tab=licence',
        LicenceGuidance::ACTION_PRICING => 'https://typo3.priebera.sk/pricing',
        LicenceGuidance::ACTION_PORTAL => 'https://typo3.priebera.sk/portal',
        LicenceGuidance::ACTION_SUPPORT => 'https://typo3.priebera.sk/contact',
    ];

    /** @return array<string, array{0:?string, 1:string, 2:string}> */
    public static function reasons(): array
    {
        return [
            'trial expired → buy' => ['trial_expired', 'trial_expired', LicenceGuidance::ACTION_PRICING],
            'paid licence expired → renew' => ['expired', 'expired', LicenceGuidance::ACTION_PORTAL],
            'domain limit → manage domains' => ['domain_limit_reached', 'domain_limit_reached', LicenceGuidance::ACTION_PORTAL],
            'wrong domain → manage domains' => ['domain_mismatch', 'domain_mismatch', LicenceGuidance::ACTION_PORTAL],
            'other project → support' => ['licence_project_mismatch', 'project_mismatch', LicenceGuidance::ACTION_SUPPORT],
            'invalid key → portal' => ['invalid_key', 'invalid_key', LicenceGuidance::ACTION_PORTAL],
            'API outage → retry' => ['api_unreachable', 'api_unreachable', LicenceGuidance::ACTION_RETRY],
            'rate limited → retry' => ['rate_limited', 'rate_limited', LicenceGuidance::ACTION_RETRY],
            'unknown reason → retry' => ['something_new', 'unknown', LicenceGuidance::ACTION_RETRY],
            'missing reason → retry' => [null, 'unknown', LicenceGuidance::ACTION_RETRY],
        ];
    }

    #[Test]
    #[DataProvider('reasons')]
    public function reasonLeadsToTheActionThatCanResolveIt(?string $reason, string $expectedState, string $expectedPrimaryAction): void
    {
        $guidance = LicenceGuidance::forReason($reason);

        self::assertSame($expectedState, $guidance->state);
        self::assertSame($expectedPrimaryAction, $guidance->primaryAction);
    }

    #[Test]
    public function outagesOfferARetryAndNeverAPurchase(): void
    {
        foreach (['api_unreachable', 'rate_limited'] as $reason) {
            $view = LicenceGuidance::forReason($reason)->toView(self::URLS, static fn (string $key): string => $key);
            $actions = array_column($view['actions'], 'action');

            self::assertSame([LicenceGuidance::ACTION_RETRY], $actions, $reason);
            self::assertSame(self::URLS[LicenceGuidance::ACTION_RETRY], $view['actions'][0]['url']);
        }
    }

    #[Test]
    public function viewResolvesTranslationsAndDropsActionsWithoutUrl(): void
    {
        $view = LicenceGuidance::forReason('domain_limit_reached')->toView(
            [LicenceGuidance::ACTION_PORTAL => 'https://portal.example'],
            static fn (string $key): string => 'T:' . $key,
        );

        self::assertSame('T:settings.licence.guidance.domainLimitReached.title', $view['title']);
        self::assertSame('T:settings.licence.guidance.domainLimitReached.text', $view['text']);
        self::assertCount(1, $view['actions'], 'The pricing action has no URL and must be left out.');
        self::assertSame('https://portal.example', $view['actions'][0]['url']);
        self::assertTrue($view['actions'][0]['primary']);
        self::assertSame('T:settings.licence.manageDomains', $view['actions'][0]['label']);
    }

    #[Test]
    public function everyGuidanceKeyIsTranslatedInEnglishAndGerman(): void
    {
        $english = $this->loadIds('locallang.xlf');
        $german = $this->loadIds('de.locallang.xlf');

        foreach (LicenceGuidance::translationKeys() as $key) {
            self::assertArrayHasKey($key, $english, 'Missing English label ' . $key);
            self::assertArrayHasKey($key, $german, 'Missing German label ' . $key);
            self::assertNotSame('', $german[$key], 'Empty German label ' . $key);
        }
    }

    /** @return array<string, string> id => target (or source for the English file) */
    private function loadIds(string $file): array
    {
        $document = new \DOMDocument();
        self::assertTrue($document->load(__DIR__ . '/../../../Resources/Private/Language/' . $file));
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('x', 'urn:oasis:names:tc:xliff:document:1.2');

        $ids = [];
        foreach ($xpath->query('//x:trans-unit') ?: [] as $unit) {
            if (!$unit instanceof \DOMElement) {
                continue;
            }
            $value = $xpath->query('./x:target', $unit)?->item(0) ?? $xpath->query('./x:source', $unit)?->item(0);
            $ids[$unit->getAttribute('id')] = trim((string)$value?->textContent);
        }

        return $ids;
    }
}
