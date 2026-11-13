<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Scheduler;

use Priebera\A11yQualityGate\Monitoring\MonitoringTargetResolver;
use TYPO3\CMS\Core\Schema\Struct\SelectItem;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * TYPO3 14 FormEngine items for the monitoring task: the configured sites, and the enabled languages of the
 * site selected in the form (the site field reloads the form, so the language list follows it).
 */
final class MonitoringTaskTcaItems
{
    public function __construct(
        private readonly MonitoringTargetResolver $monitoringTargetResolver,
    ) {}

    /**
     * @param array<string, mixed> $parameters
     */
    public function addSiteItems(array &$parameters): void
    {
        $items = $parameters['items'] ?? [];
        foreach ($this->monitoringTargetResolver->siteChoices() as $choice) {
            $items[] = SelectItem::fromTcaItemArray([
                'label' => $choice['label'],
                'value' => $choice['identifier'],
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
        $site = $this->monitoringTargetResolver->findSite($this->readRowString($row, MonitoringTask::PARAM_SITE));
        if ($site instanceof Site) {
            foreach ($this->monitoringTargetResolver->languageChoices($site) as $choice) {
                $items[] = SelectItem::fromTcaItemArray([
                    'label' => $choice['label'],
                    'value' => $choice['languageId'],
                ]);
            }
        } else {
            // No site in the form yet (a new task): every site's languages, labelled with their sites, so the form needs
            // no reload. Saving validates that the language belongs to the chosen site (MonitoringTaskValidator).
            // N-15: one option per language id, its sites grouped by language name ("English (main, shop) · Deutsch (blog)")
            // instead of a long run of "label · site / label · site". The save checks the pair against the chosen site.
            $byLanguage = [];
            foreach ($this->monitoringTargetResolver->siteChoices() as $siteChoice) {
                $candidate = $this->monitoringTargetResolver->findSite((string)$siteChoice['identifier']);
                if (!$candidate instanceof Site) {
                    continue;
                }
                foreach ($this->monitoringTargetResolver->languageChoices($candidate) as $choice) {
                    $name = trim((string)preg_replace('/\s+–\s+.*$/u', '', (string)$choice['label']));
                    $byLanguage[(int)$choice['languageId']][$name][] = (string)$siteChoice['identifier'];
                }
            }
            ksort($byLanguage);
            foreach ($byLanguage as $languageId => $names) {
                $parts = [];
                foreach ($names as $name => $sites) {
                    $parts[] = sprintf('%s (%s)', $name, implode(', ', $sites));
                }
                $items[] = SelectItem::fromTcaItemArray([
                    'label' => sprintf('%d: %s', $languageId, implode(' · ', $parts)),
                    'value' => $languageId,
                ]);
            }
        }

        $parameters['items'] = $items;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function readRowString(array $row, string $fieldName): string
    {
        $value = $row[$fieldName] ?? '';
        if (is_array($value)) {
            $value = reset($value);
        }

        return is_scalar($value) ? trim((string)$value) : '';
    }
}
