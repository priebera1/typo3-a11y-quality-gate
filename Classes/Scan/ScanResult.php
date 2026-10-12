<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Scan;

final class ScanResult
{
    public const RENDERED_SKIPPED_LIST_LIMIT = 50;

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

    /** Pages whose rendered HTML was fetched and checked. */
    public int $renderedChecked = 0;

    /**
     * Pages whose rendered check did not run, once per page, with the HTTP status (0 when there was no answer) and,
     * when visitors are not shown the page, why (RenderedPageTypeGuard::RESTRICTION_*; '' for a failed fetch).
     *
     * @var list<array{pageUid:int,statusCode:int,message:string,reason:string}>
     */
    public array $renderedSkipped = [];

    /** @var array<int, true> */
    private array $renderedRecordedPages = [];

    public function __construct(
        public readonly int $scanUid,
    ) {
    }

    /**
     * A page type without rendered output (folder, shortcut) is not recorded: its rendered check does not apply.
     */
    public function recordRenderedCheck(int $pageUid, bool $completed, int $statusCode = 0, string $message = '', string $reason = ''): void
    {
        if (isset($this->renderedRecordedPages[$pageUid])) {
            return;
        }
        $this->renderedRecordedPages[$pageUid] = true;

        if ($completed) {
            $this->renderedChecked++;
            return;
        }

        $this->renderedSkipped[] = [
            'pageUid' => $pageUid,
            'statusCode' => max(0, $statusCode),
            'message' => trim($message),
            'reason' => $reason,
        ];
    }

    /**
     * `notPublic` counts the skipped pages visitors are not shown (hidden, scheduled, restricted): their rendered HTML
     * cannot be checked by design. The others could not be fetched although they should be public.
     *
     * @return array{checked:int,skipped:int,notPublic:int,skippedPages:list<array{pageUid:int,statusCode:int,message:string,reason:string}>}
     */
    public function renderedCoverage(): array
    {
        return [
            'checked' => $this->renderedChecked,
            'skipped' => count($this->renderedSkipped),
            'notPublic' => count(array_filter($this->renderedSkipped, static fn (array $entry): bool => $entry['reason'] !== '')),
            'skippedPages' => array_slice($this->renderedSkipped, 0, self::RENDERED_SKIPPED_LIST_LIMIT),
        ];
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
