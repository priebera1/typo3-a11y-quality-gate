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
        // U-06: every site's translations, labelled with their site, from the first render: the page fields no longer
        // reload the form ("Refresh required"). The task refuses a language its site does not have when it runs.
        $items = $parameters['items'] ?? [];
        $byLanguage = [];
        $sites = $this->getSiteFinder()->getAllSites();
        ksort($sites);
        foreach ($sites as $site) {
            foreach ($site->getLanguages() as $language) {
                $languageId = $language->getLanguageId();
                if ($languageId <= 0) {
                    continue;
                }
                $label = (string)$language->getTitle();
                $byLanguage[$languageId][] = sprintf('%s (%d) · %s', $label !== '' ? $label : 'Language ' . $languageId, $languageId, $site->getIdentifier());
            }
        }
        ksort($byLanguage);
        foreach ($byLanguage as $languageId => $labels) {
            $items[] = SelectItem::fromTcaItemArray(['label' => implode(' / ', $labels), 'value' => $languageId]);
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

    private function getSiteFinder(): SiteFinder
    {
        return GeneralUtility::makeInstance(SiteFinder::class);
    }
}
