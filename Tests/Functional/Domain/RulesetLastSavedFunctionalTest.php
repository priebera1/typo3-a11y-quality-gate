<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Domain;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Domain\Repository\RulesetRepository;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;

/**
 * U-10 (2026-11-05 company acceptance): Settings showed "Last saved 05.11.2026 · 16:02" on a fresh installation that had
 * never saved anything — opening Settings created the default ruleset with the current time. The label is the ruleset's
 * tstamp, so a row Settings only created to have defaults must not carry a save time.
 */
final class RulesetLastSavedFunctionalTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function theDefaultRulesetCreatedOnFirstViewHasNoSaveTime(): void
    {
        $ruleset = $this->get(RulesetRepository::class)->findOrCreateDefault();

        self::assertIsArray($ruleset);
        self::assertSame(0, (int)$ruleset['tstamp'], 'Opening Settings is not a save.');
        self::assertGreaterThan(0, (int)$ruleset['crdate']);
    }

    #[Test]
    public function aRealSaveRecordsItsTime(): void
    {
        $repository = $this->get(RulesetRepository::class);
        $repository->findOrCreateDefault();
        $saved = $repository->saveForSiteOrDefault('', 1, 0, -1);

        self::assertGreaterThan(0, (int)($saved['tstamp'] ?? 0));
    }
}
