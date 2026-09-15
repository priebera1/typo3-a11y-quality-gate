<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Service\AccessibilityStatementService;

/**
 * The server rejects over-long draft text instead of cutting a published sentence off, so the form
 * must not accept more than the server does: every limited field carries the server limit as
 * maxlength, and both statement dates are date inputs with the bounds the server enforces.
 */
final class StatementFormTemplateTest extends TestCase
{
    private const FIELD_CLASSES = [
        'websiteName' => 'js-aqg-statement-website-name',
        'organisation' => 'js-aqg-statement-organisation',
        'commitmentText' => 'js-aqg-statement-commitment',
        'customAccessibilityStandard' => 'js-aqg-statement-standard-custom',
        'customMeasure' => 'js-aqg-statement-custom-measure',
        'remediationNote' => 'js-aqg-statement-remediation',
        'contactEmail' => 'js-aqg-statement-contact-email',
        'phone' => 'js-aqg-statement-phone',
        'postalAddress' => 'js-aqg-statement-address',
        'responseTime' => 'js-aqg-statement-response-time',
        'responseNote' => 'js-aqg-statement-response-note',
        'compatibleEnvironments' => 'js-aqg-statement-compatible',
        'incompatibleEnvironments' => 'js-aqg-statement-incompatible',
        'evaluationReportUrl' => 'js-aqg-statement-evaluation-url',
        'approvalOrganisation' => 'js-aqg-statement-approval-organisation',
        'approvalPerson' => 'js-aqg-statement-approval-person',
        'approvalRole' => 'js-aqg-statement-approval-role',
        'customEnforcementText' => 'js-aqg-statement-enforcement-custom',
    ];

    #[Test]
    public function everyServerLimitHasAFormField(): void
    {
        self::assertEqualsCanonicalizing(
            array_keys(AccessibilityStatementService::DRAFT_TEXT_LIMITS),
            array_keys(self::FIELD_CLASSES),
        );
    }

    #[Test]
    public function everyLimitedDraftFieldCarriesTheServerLimitAsMaxlength(): void
    {
        $template = $this->template();

        foreach (self::FIELD_CLASSES as $field => $class) {
            $limit = AccessibilityStatementService::DRAFT_TEXT_LIMITS[$field];
            self::assertMatchesRegularExpression(
                '/\smaxlength="' . $limit . '"[\s\/>]/',
                $this->controlTag($template, $class),
                sprintf('%s must carry maxlength="%d".', $class, $limit)
            );
        }
    }

    #[Test]
    public function statementDatesAreDateInputsWithTheServerBounds(): void
    {
        $template = $this->template();

        foreach (['js-aqg-statement-created-date', 'js-aqg-statement-approval-date'] as $class) {
            $tag = $this->controlTag($template, $class);
            self::assertStringContainsString('type="date"', $tag);
            self::assertStringContainsString('min="2000-01-01"', $tag);
            self::assertStringContainsString("max=\"{f:format.date(date: 'now', format: 'Y-m-d')}\"", $tag);
        }
    }

    private function template(): string
    {
        $template = file_get_contents(__DIR__ . '/../../../Resources/Private/Partials/Settings/TabStatement.html');
        self::assertIsString($template);

        return $template;
    }

    private function controlTag(string $template, string $class): string
    {
        $pattern = '/<(?:input|textarea)\b[^>]*\sclass="(?:[^"]*\s)?' . preg_quote($class, '/') . '(?:\s[^"]*)?"[^>]*>/';
        self::assertSame(1, preg_match_all($pattern, $template, $matches), sprintf('Expected exactly one form control with class %s.', $class));

        return $matches[0][0];
    }
}
