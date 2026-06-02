<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Scheduler;

use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Scan\ScanOrchestrator;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Task\AbstractTask;

final class A11yScanTask extends AbstractTask
{
    public const PARAM_PAGE_UID = 'tx_a11yqualitygate_page_uid';
    public const PARAM_ROOT_PID = 'tx_a11yqualitygate_root_pid';
    public const PARAM_DEPTH = 'tx_a11yqualitygate_depth';
    public const PARAM_LANGUAGE_UID = 'tx_a11yqualitygate_language_uid';
    public const PARAM_CHANGED_ONLY = 'tx_a11yqualitygate_changed_only';

    /**
     * Field names used in early TYPO3 14 compatibility builds. Keep reading
     * them so installations that tested those builds do not lose Scheduler
     * task settings when switching to the final 1.4.0 field names.
     */
    private const COMPAT_PARAM_PAGE_UID = 'a11y_quality_gate_page_uid';
    private const COMPAT_PARAM_ROOT_PID = 'a11y_quality_gate_root_pid';
    private const COMPAT_PARAM_DEPTH = 'a11y_quality_gate_depth';
    private const COMPAT_PARAM_LANGUAGE_UID = 'a11y_quality_gate_language_uid';
    private const COMPAT_PARAM_CHANGED_ONLY = 'a11y_quality_gate_changed_only';

    private const LEGACY_PARAM_PAGE_UID = 'task_a11y_pageUid';
    private const LEGACY_PARAM_ROOT_PID = 'task_a11y_rootPid';
    private const LEGACY_PARAM_DEPTH = 'task_a11y_depth';
    private const LEGACY_PARAM_LANGUAGE_UID = 'task_a11y_languageUid';
    private const LEGACY_PARAM_CHANGED_ONLY = 'task_a11y_changedOnly';

    public int $pageUid = 0;
    public int $rootPid = 0;
    public int $depth = 99;
    public int $languageUid = -1;
    public bool $changedOnly = false;

    /**
     * @return array<string, int|bool>
     */
    public function getTaskParameters(): array
    {
        return [
            self::PARAM_PAGE_UID => $this->pageUid,
            self::PARAM_ROOT_PID => $this->rootPid,
            self::PARAM_DEPTH => $this->depth,
            self::PARAM_LANGUAGE_UID => $this->languageUid,
            self::PARAM_CHANGED_ONLY => $this->changedOnly,
        ];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function setTaskParameters(array $parameters): void
    {
        $this->pageUid = $this->resolveIntParameter(
            $parameters,
            self::PARAM_PAGE_UID,
            self::COMPAT_PARAM_PAGE_UID,
            self::LEGACY_PARAM_PAGE_UID,
            $this->pageUid
        );
        $this->rootPid = $this->resolveIntParameter(
            $parameters,
            self::PARAM_ROOT_PID,
            self::COMPAT_PARAM_ROOT_PID,
            self::LEGACY_PARAM_ROOT_PID,
            $this->rootPid
        );
        $this->depth = $this->resolveIntParameter(
            $parameters,
            self::PARAM_DEPTH,
            self::COMPAT_PARAM_DEPTH,
            self::LEGACY_PARAM_DEPTH,
            $this->depth
        );
        $this->languageUid = $this->resolveIntParameter(
            $parameters,
            self::PARAM_LANGUAGE_UID,
            self::COMPAT_PARAM_LANGUAGE_UID,
            self::LEGACY_PARAM_LANGUAGE_UID,
            $this->languageUid
        );
        $this->changedOnly = $this->resolveBoolParameter(
            $parameters,
            self::PARAM_CHANGED_ONLY,
            self::COMPAT_PARAM_CHANGED_ONLY,
            self::LEGACY_PARAM_CHANGED_ONLY,
            $this->changedOnly
        );
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function validateTaskParameters(array $parameters): bool
    {
        // Keep this method deliberately non-blocking. TYPO3 14 uses the
        // Scheduler tasktype field as the record type. Returning false here
        // during the initial tasktype reload/save flow can make FormEngine
        // re-render the task as an empty/invalid type. Basic field validation
        // is handled by TCA; runtime misconfiguration is handled safely in
        // execute() so existing tasks can still be opened and repaired.
        return true;
    }

    public function execute(): bool
    {
        if ($this->pageUid <= 0 && $this->rootPid <= 0) {
            $this->logger?->warning('A11yScanTask skipped because neither pageUid nor rootPid is configured.');

            return true;
        }

        try {
            $container = GeneralUtility::getContainer();

            /** @var ScanOrchestrator $orchestrator */
            $orchestrator = $container->get(ScanOrchestrator::class);

            /** @var SiteResolutionService $siteResolutionService */
            $siteResolutionService = $container->get(SiteResolutionService::class);

            if ($this->pageUid > 0) {
                $siteIdentifier = $siteResolutionService->resolveSiteIdentifierFromPageId($this->pageUid);

                $result = $orchestrator->scanPage(
                    siteIdentifier: $siteIdentifier,
                    pageUid: $this->pageUid,
                    languageUid: $this->languageUid,
                    changedOnly: $this->changedOnly,
                );
            } else {
                $siteIdentifier = $siteResolutionService->resolveSiteIdentifierFromPageId($this->rootPid);

                $result = $orchestrator->scanSubtree(
                    siteIdentifier: $siteIdentifier,
                    rootPid: $this->rootPid,
                    depth: $this->depth,
                    languageUid: $this->languageUid,
                    changedOnly: $this->changedOnly,
                );
            }

            $this->logger?->info('A11yScanTask completed', $this->buildLogContext([
                'summary' => $result->toSummaryString(),
            ]));

            return true;
        } catch (\Throwable $e) {
            $this->logger?->error('A11yScanTask failed', $this->buildLogContext([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]));

            throw $e;
        }
    }

    public function getAdditionalInformation(): string
    {
        $mode = $this->changedOnly ? 'changed-only' : 'full';
        $language = $this->languageUid === -1 ? 'all' : (string)$this->languageUid;

        if ($this->pageUid > 0) {
            return sprintf(
                'Single page | Page UID: %d | Language: %s | Mode: %s',
                $this->pageUid,
                $language,
                $mode
            );
        }

        return sprintf(
            'Subtree | Root PID: %d | Depth: %d | Language: %s | Mode: %s',
            $this->rootPid,
            $this->depth,
            $language,
            $mode
        );
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function resolveIntParameter(array $parameters, string $nativeKey, string $compatKey, string $legacyKey, int $default): int
    {
        $value = $parameters[$nativeKey]
            ?? $parameters[$compatKey]
            ?? $parameters[$legacyKey]
            ?? $default;

        if (is_array($value)) {
            $value = reset($value);
        }

        return (int)$value;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function resolveBoolParameter(array $parameters, string $nativeKey, string $compatKey, string $legacyKey, bool $default): bool
    {
        $value = $parameters[$nativeKey]
            ?? $parameters[$compatKey]
            ?? $parameters[$legacyKey]
            ?? $default;

        if (is_array($value)) {
            $value = reset($value);
        }

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }

    private function pageExists(int $pageUid): bool
    {
        return is_array(BackendUtility::getRecord(Tables::PAGES, $pageUid, 'uid'));
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function buildLogContext(array $extra = []): array
    {
        return $extra + [
                'pageUid' => $this->pageUid,
                'rootPid' => $this->rootPid,
                'depth' => $this->depth,
                'languageUid' => $this->languageUid,
                'changedOnly' => $this->changedOnly,
            ];
    }
}
