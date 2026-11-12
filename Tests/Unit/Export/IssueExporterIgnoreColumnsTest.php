<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Export;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Export\IssueExporter;

/**
 * Product acceptance: the CSV said "Ignored By = 1" (a backend user uid) and kept the old reason on a finding whose
 * ignore had expired and that was open again.
 */
final class IssueExporterIgnoreColumnsTest extends TestCase
{
    private function columns(array $issue): array
    {
        return (new \ReflectionMethod(IssueExporter::class, 'ignoreColumns'))->invoke(null, $issue);
    }

    #[Test]
    public function anIgnoredFindingNamesThePersonAndTheReason(): void
    {
        self::assertSame(['Pat Tester', 'Design decision'], $this->columns(['status' => 2, 'ignored_by' => 1, 'ignored_by_name' => 'Pat Tester', 'ignored_by_username' => 'pat', 'ignored_reason' => 'Design decision']));
        self::assertSame(['pat', 'x'], $this->columns(['status' => 2, 'ignored_by' => 1, 'ignored_by_name' => '', 'ignored_by_username' => 'pat', 'ignored_reason' => 'x']));
        self::assertSame(['Backend user #7', 'x'], $this->columns(['status' => 2, 'ignored_by' => 7, 'ignored_reason' => 'x']));
    }

    #[Test]
    public function aReopenedOrOpenFindingCarriesNoStaleIgnoreData(): void
    {
        self::assertSame(['', ''], $this->columns(['status' => 0, 'ignored_by' => 1, 'ignored_by_name' => 'Pat Tester', 'ignored_reason' => 'Design decision', 'ignored_reopened_at' => 1794485974]));
        self::assertSame(['', ''], $this->columns(['status' => 1, 'ignored_by' => 1, 'ignored_reason' => 'old']));
    }
}
