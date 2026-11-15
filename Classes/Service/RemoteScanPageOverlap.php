<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

/**
 * Which pages two stored frontend scans both loaded (D-1, AQG 3.0).
 *
 * New and resolved findings are only meaningful on pages both scans checked: a page only the later scan visited has no
 * earlier state, so its findings are not "new", and a page only the earlier scan visited was not re-checked, so its
 * findings are not "resolved". On production a 4-page budgeted scan as baseline of a 19-page scan reported 297 "new"
 * findings that way. This compares stored facts (the loaded page URLs), never scan settings: what a scan was allowed
 * to visit is the AQG service's verdict (`/crawl/compare` → `compatibility`).
 */
final class RemoteScanPageOverlap
{
    /**
     * @param array<string, true> $common
     */
    private function __construct(
        public readonly array $common,
        public readonly int $onlyInEarlier,
        public readonly int $onlyInLater,
    ) {
    }

    /**
     * @param array<string, true> $earlierPages page key => true (RemoteScanRepository::findLoadedPageKeysForScan())
     * @param array<string, true> $laterPages
     */
    public static function of(array $earlierPages, array $laterPages): self
    {
        $common = array_intersect_key($laterPages, $earlierPages);

        return new self($common, count($earlierPages) - count($common), count($laterPages) - count($common));
    }

    public function isSamePages(): bool
    {
        return $this->onlyInEarlier === 0 && $this->onlyInLater === 0;
    }

    public function comparedPages(): int
    {
        return count($this->common);
    }
}
