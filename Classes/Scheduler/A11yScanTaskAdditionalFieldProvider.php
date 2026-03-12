<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Scheduler;

use Priebera\A11yQualityGate\Database\Tables;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\AbstractAdditionalFieldProvider;
use TYPO3\CMS\Scheduler\Controller\SchedulerModuleController;
use TYPO3\CMS\Scheduler\Task\AbstractTask;

final class A11yScanTaskAdditionalFieldProvider extends AbstractAdditionalFieldProvider
{
    private const FIELD_PAGE_UID = 'task_a11y_pageUid';
    private const FIELD_ROOT_PID = 'task_a11y_rootPid';
    private const FIELD_DEPTH = 'task_a11y_depth';
    private const FIELD_LANGUAGE_UID = 'task_a11y_languageUid';
    private const FIELD_CHANGED_ONLY = 'task_a11y_changedOnly';

    /**
     * @param array<string, mixed> $taskInfo
     * @return array<string, array<string, string>>
     */
    public function getAdditionalFields(array &$taskInfo, $task, SchedulerModuleController $schedulerModule): array
    {
        if ($task instanceof A11yScanTask) {
            $taskInfo[self::FIELD_PAGE_UID] = $task->pageUid;
            $taskInfo[self::FIELD_ROOT_PID] = $task->rootPid;
            $taskInfo[self::FIELD_DEPTH] = $task->depth;
            $taskInfo[self::FIELD_LANGUAGE_UID] = $task->languageUid;
            $taskInfo[self::FIELD_CHANGED_ONLY] = (int)$task->changedOnly;
        } else {
            $taskInfo[self::FIELD_PAGE_UID] ??= 0;
            $taskInfo[self::FIELD_ROOT_PID] ??= 0;
            $taskInfo[self::FIELD_DEPTH] ??= 99;
            $taskInfo[self::FIELD_LANGUAGE_UID] ??= -1;
            $taskInfo[self::FIELD_CHANGED_ONLY] ??= 0;
        }

        return [
            self::FIELD_PAGE_UID => [
                'code' => $this->renderPageUidField((int)$taskInfo[self::FIELD_PAGE_UID]),
                'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:scheduler.field.pageUid',
                'cshKey' => '',
                'cshLabel' => '',
            ],
            self::FIELD_ROOT_PID => [
                'code' => $this->renderRootPidSelect((int)$taskInfo[self::FIELD_ROOT_PID]),
                'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:scheduler.field.rootPid',
                'cshKey' => '',
                'cshLabel' => '',
            ],
            self::FIELD_DEPTH => [
                'code' => $this->renderDepthField((int)$taskInfo[self::FIELD_DEPTH]),
                'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:scheduler.field.depth',
                'cshKey' => '',
                'cshLabel' => '',
            ],
            self::FIELD_LANGUAGE_UID => [
                'code' => $this->renderLanguageUidSelect(
                    (int)$taskInfo[self::FIELD_LANGUAGE_UID],
                    (int)$taskInfo[self::FIELD_ROOT_PID],
                    (int)$taskInfo[self::FIELD_PAGE_UID],
                ),
                'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:scheduler.field.languageUid',
                'cshKey' => '',
                'cshLabel' => '',
            ],
            self::FIELD_CHANGED_ONLY => [
                'code' => $this->renderChangedOnlyField((bool)$taskInfo[self::FIELD_CHANGED_ONLY]),
                'label' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:scheduler.field.changedOnly',
                'cshKey' => '',
                'cshLabel' => '',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $submittedData
     */
    public function validateAdditionalFields(array &$submittedData, SchedulerModuleController $schedulerModule): bool
    {
        $pageUid = (int)($submittedData[self::FIELD_PAGE_UID] ?? 0);
        $rootPid = (int)($submittedData[self::FIELD_ROOT_PID] ?? 0);
        $depth = (int)($submittedData[self::FIELD_DEPTH] ?? 99);

        if ($pageUid <= 0 && $rootPid <= 0) {
            $this->addMessage(
                $this->getLabel('scheduler.validation.pageOrRootRequired'),
                ContextualFeedbackSeverity::ERROR
            );

            return false;
        }

        if ($depth < 1) {
            $this->addMessage(
                $this->getLabel('scheduler.validation.depthPositive'),
                ContextualFeedbackSeverity::ERROR
            );

            return false;
        }

        if ($pageUid > 0) {
            if (!$this->pageExists($pageUid)) {
                $this->addMessage(
                    sprintf($this->getLabel('scheduler.validation.pageNotFound'), $pageUid),
                    ContextualFeedbackSeverity::ERROR
                );

                return false;
            }

            return true;
        }

        if ($rootPid > 0 && !$this->pageExists($rootPid)) {
            $this->addMessage(
                sprintf($this->getLabel('scheduler.validation.rootNotFound'), $rootPid),
                ContextualFeedbackSeverity::ERROR
            );

            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $submittedData
     */
    public function saveAdditionalFields(array $submittedData, AbstractTask $task): void
    {
        if (!$task instanceof A11yScanTask) {
            return;
        }

        $task->pageUid = (int)($submittedData[self::FIELD_PAGE_UID] ?? 0);
        $task->rootPid = (int)($submittedData[self::FIELD_ROOT_PID] ?? 0);
        $task->depth = (int)($submittedData[self::FIELD_DEPTH] ?? 99);
        $task->languageUid = (int)($submittedData[self::FIELD_LANGUAGE_UID] ?? -1);
        $task->changedOnly = (bool)($submittedData[self::FIELD_CHANGED_ONLY] ?? false);
    }

    private function renderPageUidField(int $pageUid): string
    {
        $hint = htmlspecialchars($this->getLabel('scheduler.field.pageUid.help'));

        return sprintf(
            '<div>
                <input class="form-control" type="number" min="0" name="tx_scheduler[%1$s]" value="%2$d" />
                <div class="form-text text-muted">%3$s</div>
            </div>',
            self::FIELD_PAGE_UID,
            $pageUid,
            $hint
        );
    }

    private function renderRootPidSelect(int $selectedRootPid): string
    {
        $options = [
            sprintf(
                '<option value="0"%s>%s</option>',
                $selectedRootPid === 0 ? ' selected="selected"' : '',
                htmlspecialchars($this->getLabel('scheduler.field.rootPid.placeholder'))
            ),
        ];

        foreach ($this->fetchSelectableRootPages() as $page) {
            $label = sprintf(
                '%s (%s), Root Page ID: %d',
                $page['siteTitle'],
                $page['siteIdentifier'],
                $page['uid']
            );

            $options[] = sprintf(
                '<option value="%d"%s>%s</option>',
                $page['uid'],
                $selectedRootPid === $page['uid'] ? ' selected="selected"' : '',
                htmlspecialchars($label)
            );
        }

        return sprintf(
            '<select class="form-select" name="tx_scheduler[%s]">%s</select>',
            self::FIELD_ROOT_PID,
            implode('', $options)
        );
    }

    private function renderDepthField(int $depth): string
    {
        return sprintf(
            '<input class="form-control" type="number" min="1" name="tx_scheduler[%s]" value="%d" />',
            self::FIELD_DEPTH,
            $depth
        );
    }

    private function renderLanguageUidSelect(int $selectedLanguageUid, int $rootPid, int $pageUid): string
    {
        $hint = htmlspecialchars($this->getLabel('scheduler.field.languageUid.help'));
        $options = [];

        $options[] = sprintf(
            '<option value="-1"%s>%s</option>',
            $selectedLanguageUid === -1 ? ' selected="selected"' : '',
            htmlspecialchars('All languages (-1)')
        );

        foreach ($this->fetchSelectableLanguages($rootPid, $pageUid) as $language) {
            $options[] = sprintf(
                '<option value="%d"%s>%s</option>',
                $language['languageUid'],
                $selectedLanguageUid === $language['languageUid'] ? ' selected="selected"' : '',
                htmlspecialchars($language['label'])
            );
        }

        return sprintf(
            '<div>
                <select class="form-select" name="tx_scheduler[%1$s]">%2$s</select>
                <div class="form-text text-muted">%3$s</div>
            </div>',
            self::FIELD_LANGUAGE_UID,
            implode('', $options),
            $hint
        );
    }

    private function renderChangedOnlyField(bool $changedOnly): string
    {
        $checked = $changedOnly ? ' checked="checked"' : '';
        $checkboxLabel = htmlspecialchars($this->getLabel('scheduler.field.changedOnly.checkboxLabel'));
        $hint = htmlspecialchars($this->getLabel('scheduler.field.changedOnly.help'));

        return sprintf(
            '<div>
                <div class="form-check">
                    <input class="form-check-input" id="%1$s" type="checkbox" name="tx_scheduler[%1$s]" value="1"%2$s />
                    <label class="form-check-label" for="%1$s">%3$s</label>
                </div>
                <div class="form-text text-muted">%4$s</div>
            </div>',
            self::FIELD_CHANGED_ONLY,
            $checked,
            $checkboxLabel,
            $hint
        );
    }

    /**
     * @return array<int, array{uid:int,siteIdentifier:string,siteTitle:string}>
     */
    private function fetchSelectableRootPages(): array
    {
        $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);

        $result = [];
        foreach ($siteFinder->getAllSites() as $site) {
            $result[] = [
                'uid' => $site->getRootPageId(),
                'siteIdentifier' => $site->getIdentifier(),
                'siteTitle' => (string)($site->getConfiguration()['websiteTitle'] ?? $site->getIdentifier()),
            ];
        }

        usort(
            $result,
            static fn(array $a, array $b): int => strcmp($a['siteTitle'], $b['siteTitle'])
        );

        return $result;
    }

    /**
     * @return array<int, array{languageUid:int,label:string}>
     */
    private function fetchSelectableLanguages(int $rootPid, int $pageUid): array
    {
        $siteFinder = GeneralUtility::makeInstance(SiteFinder::class);

        $site = null;
        $candidatePid = $pageUid > 0 ? $pageUid : $rootPid;

        if ($candidatePid > 0) {
            try {
                $site = $siteFinder->getSiteByPageId($candidatePid);
            } catch (\Throwable) {
                $site = null;
            }
        }

        if ($site === null) {
            return [];
        }

        $result = [];
        foreach ($site->getLanguages() as $language) {
            $label = (string)$language->getTitle();
            if ($label === '') {
                $label = 'Language ' . $language->getLanguageId();
            }

            $result[] = [
                'languageUid' => $language->getLanguageId(),
                'label' => sprintf('%s (%d)', $label, $language->getLanguageId()),
            ];
        }

        usort(
            $result,
            static fn(array $a, array $b): int => $a['languageUid'] <=> $b['languageUid']
        );

        return $result;
    }

    private function pageExists(int $pageUid): bool
    {
        return is_array(BackendUtility::getRecord(Tables::PAGES, $pageUid, 'uid', '', false));
    }

    private function getLabel(string $key): string
    {
        return $this->getLanguageService()->sL(
            'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:' . $key
        ) ?: $key;
    }

    private function getLanguageService(): LanguageService
    {
        if (!$GLOBALS['LANG'] instanceof LanguageService) {
            throw new \RuntimeException('LanguageService is not available.', 1741430001);
        }

        return $GLOBALS['LANG'];
    }
}
