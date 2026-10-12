<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\ViewModel;

/**
 * Why frontend findings of a page are not linked to TYPO3 records.
 *
 * TYPO3 marks content records for the crawler only when the scan sends a Scanner Token the site accepts
 * (ScannerAccessTokenService). Without an effective token for the site, unmapped findings have a known cause and a
 * fix; with one, an unmapped finding simply lies outside any content element (page template, layout).
 */
final class RecordMappingNotice
{
    /**
     * Counts only what a Scanner Token could change: occurrences inside page content. Page-level nodes (`html`, `body`:
     * a missing main landmark, a page without h1) belong to the page template and are never linked to a record.
     *
     * @param list<array<string, mixed>> $issues issues with their prepared `nodes` (`hasRecordMapping`)
     * @return array{show: bool, scannerTokenMissing: bool, unmappedNodes: int, linkedNodes: int, pageLevelNodes: int, totalNodes: int, allUnmapped: bool, settingsUrl: string}
     */
    public static function build(array $issues, bool $siteHasScannerToken, bool $isFreePreview, string $settingsUrl): array
    {
        $unmappedNodes = 0;
        $pageLevelNodes = 0;
        $totalNodes = 0;
        foreach ($issues as $issue) {
            foreach (is_array($issue['nodes'] ?? null) ? $issue['nodes'] : [] as $node) {
                if (!is_array($node)) {
                    continue;
                }
                $totalNodes++;
                if ((bool)($node['hasRecordMapping'] ?? false)) {
                    continue;
                }
                if (self::isPageLevelNode($node)) {
                    $pageLevelNodes++;
                } else {
                    $unmappedNodes++;
                }
            }
        }

        $scannerTokenMissing = !$isFreePreview && !$siteHasScannerToken;
        $linkedNodes = $totalNodes - $unmappedNodes - $pageLevelNodes;

        return [
            'show' => $scannerTokenMissing && $unmappedNodes > 0,
            'scannerTokenMissing' => $scannerTokenMissing,
            'unmappedNodes' => $unmappedNodes,
            'linkedNodes' => $linkedNodes,
            'pageLevelNodes' => $pageLevelNodes,
            'totalNodes' => $totalNodes,
            // The headline only says "none" when no occurrence is linked; otherwise it names the share.
            'allUnmapped' => $unmappedNodes > 0 && $linkedNodes === 0,
            'settingsUrl' => $settingsUrl,
        ];
    }

    /**
     * @param array<string, mixed> $node
     */
    public static function isPageLevelNode(array $node): bool
    {
        $target = json_decode((string)($node['target_json'] ?? ''), true);
        if (is_array($target) && count($target) === 1 && is_string($target[0] ?? null)
            && in_array(strtolower(trim($target[0])), ['html', 'body'], true)) {
            return true;
        }

        return preg_match('/^\s*<(html|body)[\s>]/i', (string)($node['html_snippet'] ?? '')) === 1;
    }
}
