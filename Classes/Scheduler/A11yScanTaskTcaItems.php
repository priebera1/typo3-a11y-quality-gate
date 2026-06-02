<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Scheduler;

use TYPO3\CMS\Core\Schema\Struct\SelectItem;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class A11yScanTaskTcaItems
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function addRootPageItems(array &$parameters): void
    {
        $items = $parameters['items'] ?? [];

        foreach ($this->fetchSelectableRootPages() as $page) {
            $items[] = SelectItem::fromTcaItemArray([
                'label' => sprintf(
                    '%s (%s), Root Page ID: %d',
                    $page['siteTitle'],
                    $page['siteIdentifier'],
                    $page['uid']
                ),
                'value' => $page['uid'],
            ]);
        }

        $parameters['items'] = $items;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function addLanguageItems(array &$parameters): void
    {
        $items = $parameters['items'] ?? [];
        $row = is_array($parameters['row'] ?? null) ? $parameters['row'] : [];
        $pageUid = $this->readRowInteger($row, A11yScanTask::PARAM_PAGE_UID);
        $rootPid = $this->readRowInteger($row, A11yScanTask::PARAM_ROOT_PID);
        $candidatePid = $pageUid > 0 ? $pageUid : $rootPid;

        if ($candidatePid <= 0) {
            $parameters['items'] = $items;
            return;
        }

        try {
            $site = $this->getSiteFinder()->getSiteByPageId($candidatePid);
        } catch (\Throwable) {
            $parameters['items'] = $items;
            return;
        }

        $languageItems = [];
        foreach ($site->getLanguages() as $language) {
            $languageId = $language->getLanguageId();
            if ($languageId === -1 || $languageId === 0) {
                continue;
            }

            $label = (string)$language->getTitle();
            if ($label === '') {
                $label = 'Language ' . $languageId;
            }

            $languageItems[] = [
                'label' => sprintf('%s (%d)', $label, $languageId),
                'value' => $languageId,
            ];
        }

        usort(
            $languageItems,
            static fn(array $a, array $b): int => (int)$a['value'] <=> (int)$b['value']
        );

        foreach ($languageItems as $languageItem) {
            $items[] = SelectItem::fromTcaItemArray($languageItem);
        }

        $parameters['items'] = $items;
    }

    /**
     * @return array<int, array{uid:int,siteIdentifier:string,siteTitle:string}>
     */
    private function fetchSelectableRootPages(): array
    {
        $result = [];
        foreach ($this->getSiteFinder()->getAllSites() as $site) {
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
     * @param array<string, mixed> $row
     */
    private function readRowInteger(array $row, string $fieldName): int
    {
        $value = $row[$fieldName] ?? 0;
        if (is_array($value)) {
            $value = reset($value);
        }

        return (int)$value;
    }

    private function getSiteFinder(): SiteFinder
    {
        return GeneralUtility::makeInstance(SiteFinder::class);
    }
}
