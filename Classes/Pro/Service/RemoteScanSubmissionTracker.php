<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Service;

use TYPO3\CMS\Core\Registry;

/**
 * A paid frontend scan that is being submitted and has no scan record yet.
 *
 * The record exists only once the AQG service has answered the submit. An editor who starts "Scan this page" and
 * opens the Page module meanwhile got a panel rendered from the previous result: not running, so the indicator had
 * nothing to follow and kept that result until the Page module was reloaded. The submit request records what it
 * starts here while it holds the site's submit lock, and the panel renders the scan as starting and follows it.
 */
final class RemoteScanSubmissionTracker
{
    private const REGISTRY_NAMESPACE = 'a11y_quality_gate';
    private const REGISTRY_KEY_PREFIX = 'remote_submit_';

    /**
     * Longer than a submit takes (licence, token and submit requests, ProConstants::REQUEST_TIMEOUT each). An entry
     * left behind by a request that died stops counting after this.
     */
    private const MAX_AGE_SECONDS = 120;

    public function __construct(
        private readonly Registry $registry,
    ) {
    }

    public function begin(string $siteIdentifier, string $scope, int $pageUid, int $languageUid): void
    {
        if ($siteIdentifier === '') {
            return;
        }

        $this->registry->set(self::REGISTRY_NAMESPACE, $this->key($siteIdentifier), [
            'scope' => $scope,
            'pageUid' => max(0, $pageUid),
            'languageUid' => $languageUid,
            'startedAt' => time(),
        ]);
    }

    public function end(string $siteIdentifier): void
    {
        if ($siteIdentifier === '') {
            return;
        }

        $this->registry->remove(self::REGISTRY_NAMESPACE, $this->key($siteIdentifier));
    }

    /**
     * @return array{scope:string,pageUid:int,languageUid:int,startedAt:int}|null
     */
    public function findPending(string $siteIdentifier): ?array
    {
        if ($siteIdentifier === '') {
            return null;
        }

        $entry = $this->registry->get(self::REGISTRY_NAMESPACE, $this->key($siteIdentifier));
        if (!is_array($entry)) {
            return null;
        }

        $startedAt = (int)($entry['startedAt'] ?? 0);
        if ($startedAt <= 0 || time() - $startedAt > self::MAX_AGE_SECONDS) {
            return null;
        }

        return [
            'scope' => (string)($entry['scope'] ?? ''),
            'pageUid' => (int)($entry['pageUid'] ?? 0),
            'languageUid' => (int)($entry['languageUid'] ?? -1),
            'startedAt' => $startedAt,
        ];
    }

    private function key(string $siteIdentifier): string
    {
        return self::REGISTRY_KEY_PREFIX . sha1($siteIdentifier);
    }
}
