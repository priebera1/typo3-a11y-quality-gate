<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Scan;

final class ScanResult
{
    public int $pagesScanned = 0;
    public int $recordsScanned = 0;
    public int $recordsSkipped = 0;
    public int $issuesNew = 0;
    public int $issuesResolved = 0;
    public int $issuesIgnored = 0;

    /**
     * @var list<array{code:string,message:string,context:array<string, int|string|bool>}>
     */
    public array $warnings = [];

    public function __construct(
        public readonly int $scanUid,
    ) {
    }

    /**
     * @param array<string, int|string|bool> $context
     */
    public function addWarning(string $code, string $message, array $context = []): void
    {
        $message = trim($message);
        if ($code === '' || $message === '') {
            return;
        }

        $this->warnings[] = [
            'code' => $code,
            'message' => $message,
            'context' => $context,
        ];
    }

    public function toSummaryString(): string
    {
        $skippedPart = $this->recordsSkipped > 0
            ? sprintf(', skipped (unchanged): %d', $this->recordsSkipped)
            : '';

        return sprintf(
            'Scan #%d complete — pages: %d, records: %d%s, new issues: %d, resolved: %d, ignored/protected: %d',
            $this->scanUid,
            $this->pagesScanned,
            $this->recordsScanned,
            $skippedPart,
            $this->issuesNew,
            $this->issuesResolved,
            $this->issuesIgnored,
        );
    }
}
