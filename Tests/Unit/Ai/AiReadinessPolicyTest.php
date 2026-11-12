<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiAccessService;
use Priebera\A11yQualityGate\Ai\Service\AiConfigurationResolver;
use Priebera\A11yQualityGate\Ai\Service\AiReadinessPolicy;
use Priebera\A11yQualityGate\Ai\Usage\AiBudgetService;
use Priebera\A11yQualityGate\Ai\Usage\AiBudgetStatus;

/** The one readiness rule shared by resolve(), the editor/media availability and the Settings readiness line. */
final class AiReadinessPolicyTest extends TestCase
{
    #[Test]
    public function aSiteIsReadyOnlyWhenEveryItemHolds(): void
    {
        $readiness = AiReadinessPolicy::evaluate(true, true, true, true, true, AiBudgetStatus::none());

        self::assertTrue($readiness->isReady());
        self::assertSame(AiReadinessPolicy::ITEMS, array_keys($readiness->items));
    }

    /** @return iterable<string,array{int,string}> */
    public static function missingItems(): iterable
    {
        yield 'licence' => [0, AiReadinessPolicy::ITEM_LICENCE];
        yield 'provider' => [1, AiReadinessPolicy::ITEM_PROVIDER];
        yield 'key' => [2, AiReadinessPolicy::ITEM_KEY];
        yield 'verified model' => [3, AiReadinessPolicy::ITEM_MODEL];
        yield 'acknowledgement' => [4, AiReadinessPolicy::ITEM_ACKNOWLEDGEMENT];
    }

    #[Test]
    #[DataProvider('missingItems')]
    public function eachMissingItemBlocks(int $position, string $item): void
    {
        $facts = [true, true, true, true, true];
        $facts[$position] = false;

        $readiness = AiReadinessPolicy::evaluate(...[...$facts, AiBudgetStatus::none()]);

        self::assertFalse($readiness->isReady());
        self::assertSame($item, $readiness->firstMissing());
        self::assertSame(AiReadinessPolicy::MISSING, $readiness->state($item));
    }

    #[Test]
    public function onlyABlockingBudgetBlocks(): void
    {
        $budget = static fn (string $state, bool $hardStop): AiBudgetStatus => new AiBudgetStatus($state, 50_000_000, 80, $hardStop, 0, 0, $state !== AiBudgetStatus::STATE_NO_PRICE, 0, 0);
        $evaluate = static fn (AiBudgetStatus $status) => AiReadinessPolicy::evaluate(true, true, true, true, true, $status);

        self::assertSame(AiReadinessPolicy::OK, $evaluate($budget(AiBudgetStatus::STATE_UNDER, true))->state(AiReadinessPolicy::ITEM_BUDGET));
        self::assertSame(AiReadinessPolicy::WARN, $evaluate($budget(AiBudgetStatus::STATE_WARNING, true))->state(AiReadinessPolicy::ITEM_BUDGET));
        self::assertTrue($evaluate($budget(AiBudgetStatus::STATE_WARNING, true))->isReady());
        self::assertSame(AiReadinessPolicy::WARN, $evaluate($budget(AiBudgetStatus::STATE_REACHED, false))->state(AiReadinessPolicy::ITEM_BUDGET));
        self::assertTrue($evaluate($budget(AiBudgetStatus::STATE_NO_PRICE, false))->isReady());
        self::assertSame(AiReadinessPolicy::ITEM_BUDGET, $evaluate($budget(AiBudgetStatus::STATE_REACHED, true))->firstMissing());
        self::assertSame(AiReadinessPolicy::ITEM_BUDGET, $evaluate($budget(AiBudgetStatus::STATE_NO_PRICE, true))->firstMissing());
    }

    #[Test]
    public function editorsSeeTheStateOfTheResolverCode(): void
    {
        self::assertSame(EditorAiAccessService::STATE_PAUSED, EditorAiAccessService::stateForConfigurationCode(AiConfigurationResolver::CODE_NOT_VERIFIED));
        self::assertSame(EditorAiAccessService::STATE_PAUSED, EditorAiAccessService::stateForConfigurationCode(AiConfigurationResolver::CODE_ACKNOWLEDGEMENT_MISSING));
        self::assertSame(EditorAiAccessService::STATE_PAUSED, EditorAiAccessService::stateForConfigurationCode(AiBudgetService::CODE_BUDGET_UNENFORCEABLE));
        self::assertSame(EditorAiAccessService::STATE_PAUSED, EditorAiAccessService::stateForConfigurationCode(1771002005));
        self::assertSame(EditorAiAccessService::STATE_BUDGET_REACHED, EditorAiAccessService::stateForConfigurationCode(AiBudgetService::CODE_BUDGET_REACHED));
        self::assertSame(EditorAiAccessService::STATE_NOT_CONFIGURED, EditorAiAccessService::stateForConfigurationCode(1771002001));
        self::assertSame(EditorAiAccessService::STATE_NOT_CONFIGURED, EditorAiAccessService::stateForConfigurationCode(0), 'A refusal is never "ready".');
    }

    #[Test]
    public function thePausedReasonTellsEditorsWhatTheAdministratorCanDo(): void
    {
        self::assertSame(EditorAiAccessService::PAUSED_NOT_VERIFIED, EditorAiAccessService::pausedReasonForCode(AiConfigurationResolver::CODE_NOT_VERIFIED));
        self::assertSame(EditorAiAccessService::PAUSED_NOT_VERIFIED, EditorAiAccessService::pausedReasonForCode(1771002005));
        self::assertSame(EditorAiAccessService::PAUSED_ACKNOWLEDGEMENT, EditorAiAccessService::pausedReasonForCode(AiConfigurationResolver::CODE_ACKNOWLEDGEMENT_MISSING));
        self::assertSame(EditorAiAccessService::PAUSED_BUDGET_UNENFORCEABLE, EditorAiAccessService::pausedReasonForCode(AiBudgetService::CODE_BUDGET_UNENFORCEABLE));
        self::assertSame('', EditorAiAccessService::pausedReasonForCode(AiBudgetService::CODE_BUDGET_REACHED));
    }
}
