<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Rule\Rte;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\Rte\TableThMissingScopeRule;

final class TableThMissingScopeRuleTest extends TestCase
{
    private TableThMissingScopeRule $rule;

    protected function setUp(): void
    {
        $this->rule = new TableThMissingScopeRule();
    }

    #[Test]
    public function ruleIdIsStable(): void
    {
        self::assertSame('rte.table_th_missing_scope', $this->rule->getRuleId());
    }

    #[Test]
    public function defaultSeverityIsWarning(): void
    {
        self::assertSame(Severity::Warning, $this->rule->getDefaultSeverity());
    }

    #[Test]
    #[DataProvider('validScopeProvider')]
    public function validScopePasses(string $scope): void
    {
        $html = sprintf('<table><tr><th scope="%s">Header</th></tr><tr><td>Data</td></tr></table>', $scope);
        self::assertCount(0, $this->rule->check($this->ctx($html)));
    }

    public static function validScopeProvider(): array
    {
        return [
            'col' => ['col'],
            'row' => ['row'],
            'colgroup' => ['colgroup'],
            'rowgroup' => ['rowgroup'],
        ];
    }

    #[Test]
    public function thWithoutScopeFails(): void
    {
        $html = '<table><tr><th>Name</th><th>Age</th></tr><tr><td>Jan</td><td>30</td></tr></table>';
        $violations = $this->rule->check($this->ctx($html));

        self::assertCount(2, $violations);
        self::assertSame(Severity::Warning, $violations[0]->severity);
    }

    #[Test]
    public function invalidScopeFails(): void
    {
        self::assertCount(1, $this->rule->check($this->ctx(
            '<table><tr><th scope="invalid">Name</th></tr><tr><td>Jan</td></tr></table>'
        )));
    }

    #[Test]
    public function layoutTableWithRolePresentationIsIgnored(): void
    {
        self::assertCount(0, $this->rule->check($this->ctx(
            '<table role="presentation"><tr><th>Layout</th></tr></table>'
        )));
    }

    #[Test]
    public function layoutTableWithRoleNoneIsIgnored(): void
    {
        self::assertCount(0, $this->rule->check($this->ctx(
            '<table role="none"><tr><th>Layout</th></tr></table>'
        )));
    }

    private function ctx(string $content): CheckContext
    {
        return new CheckContext(
            siteIdentifier: 'main',
            pageUid: 1,
            sourceLangUid: 0,
            sourceTable: Tables::TT_CONTENT,
            sourceUid: 42,
            sourceField: 'bodytext',
            content: $content,
        );
    }
}
