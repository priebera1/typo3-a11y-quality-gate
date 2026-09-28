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
