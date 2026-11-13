<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * M-02/M-04 (2026-11-05 company acceptance): editors without AI rights read "AI fixes are part of AQG PRO and Agency"
 * although PRO was active, and an exhausted budget or a paused site read "AI suggestions are not set up". Each reason
 * has its own state; the plan message is shown only when no site of the scope has the licence.
 */
final class AiAvailabilityReasonTemplateTest extends TestCase
{
    #[Test]
    public function theQueueNamesEachReasonAiIsNotAvailable(): void
    {
        $template = (string)file_get_contents(dirname(__DIR__, 3) . '/Resources/Private/Templates/Remediation/Index.html');

        foreach (["{notReadyReason} == 'no_permission'", "{notReadyReason} == 'budget_reached'", "{notReadyReason} == 'paused'", "{lockedReason} == 'no_site'"] as $condition) {
            self::assertStringContainsString($condition, $template);
        }
        foreach (['remediation.noPermission.title', 'remediation.budgetReached.title', 'remediation.paused.title', 'remediation.noSite.title'] as $key) {
            self::assertStringContainsString($key, $template);
        }
        self::assertLessThan(
            strpos($template, 'remediation.free.title'),
            strpos($template, "{lockedReason} == 'no_site'"),
            'The plan message is the fallback, not the first answer.'
        );
    }

    #[Test]
    public function theControllerDerivesTheReasonFromTheEditorAiStates(): void
    {
        $controller = (string)file_get_contents(dirname(__DIR__, 3) . '/Classes/Controller/RemediationQueueController.php');

        self::assertStringContainsString("'notReadyReason' => \$notReadyReason", $controller);
        self::assertStringContainsString('STATE_NO_PERMISSION', $controller);
        self::assertStringContainsString('canReadPage($pageUid)', $controller, 'Page-mount editors get their page\'s site.');
    }
}
