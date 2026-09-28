<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Scheduler;

use Priebera\A11yQualityGate\Monitoring\MonitoringTargetResolver;
use Priebera\A11yQualityGate\Monitoring\MonitoringTaskValidator;
use Priebera\A11yQualityGate\Utility\BackendLabelUtility;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Scheduler\AbstractAdditionalFieldProvider;
use TYPO3\CMS\Scheduler\Controller\SchedulerModuleController;
use TYPO3\CMS\Scheduler\Task\AbstractTask;

/**
 * TYPO3 13 Scheduler form of the monitoring task. TYPO3 14 uses the TCA of
 * Configuration/TCA/Overrides/scheduler_a11y_monitoring_task.php instead.
 *
 * The classic Scheduler form cannot reload when the site changes, so the language select holds one group per
 * configured site; scheduler-monitoring-task.js shows only the group of the selected site, and the submitted
 * pair is validated against the site configuration on save.
 */
final class MonitoringTaskAdditionalFieldProvider extends AbstractAdditionalFieldProvider
{
    public const FIELD_SITE = 'aqg_monitor_site';
    public const FIELD_LANGUAGE = 'aqg_monitor_language';
    public const FIELD_RECIPIENTS = 'aqg_monitor_recipients';
    public const FIELD_MAX_PAGES = 'aqg_monitor_max_pages';
    public const FIELD_MAX_WAIT = 'aqg_monitor_max_wait';
    public const FIELD_BACKEND_URL = 'aqg_monitor_backend_url';

    private const LABEL_PREFIX = 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:';

    public function __construct(
        private readonly MonitoringTargetResolver $monitoringTargetResolver,
        private readonly MonitoringTaskValidator $monitoringTaskValidator,
        private readonly PageRenderer $pageRenderer,
    ) {}

    /**
     * @param array<string, mixed> $taskInfo
     * @return array<string, array<string, string>>
     */
    public function getAdditionalFields(array &$taskInfo, $task, SchedulerModuleController $schedulerModule): array
    {
        // Values submitted with a failed save win over the stored task, so the form keeps what was entered.
        $stored = $task instanceof MonitoringTask ? $task : null;
        $taskInfo[self::FIELD_SITE] ??= $stored?->siteIdentifier ?? $this->singleSiteIdentifier();
        $taskInfo[self::FIELD_LANGUAGE] ??= $stored?->languageUid ?? $this->defaultLanguageOf((string)$taskInfo[self::FIELD_SITE]);
        $taskInfo[self::FIELD_RECIPIENTS] ??= $stored?->recipients ?? '';
        $taskInfo[self::FIELD_MAX_PAGES] ??= $stored?->maxPages ?? MonitoringTaskValidator::MAX_PAGES_DEFAULT;
        $taskInfo[self::FIELD_MAX_WAIT] ??= $stored?->maxWait ?? MonitoringTaskValidator::MAX_WAIT_DEFAULT;
        $taskInfo[self::FIELD_BACKEND_URL] ??= $stored?->backendUrl ?? '';

        $this->pageRenderer->loadJavaScriptModule('@priebera/a11y-quality-gate/backend/scheduler-monitoring-task.js');

        $siteIdentifier = trim((string)$taskInfo[self::FIELD_SITE]);

        return [
            self::FIELD_SITE => $this->field('select', 'scheduler.monitoring.field.site', $this->renderSiteSelect($siteIdentifier)),
            self::FIELD_LANGUAGE => $this->field('select', 'scheduler.monitoring.field.language', $this->renderLanguageSelect($siteIdentifier, (int)$taskInfo[self::FIELD_LANGUAGE])),
            self::FIELD_RECIPIENTS => $this->field('input', 'scheduler.monitoring.field.recipients', sprintf(
                '<input class="form-control" type="text" id="%1$s" name="tx_scheduler[%1$s]" value="%2$s" placeholder="web@example.org, qa@example.org" autocomplete="off" required="required" />',
                self::FIELD_RECIPIENTS,
                htmlspecialchars((string)$taskInfo[self::FIELD_RECIPIENTS])
            )),
            self::FIELD_MAX_PAGES => $this->field('input', 'scheduler.monitoring.field.maxPages', sprintf(
                '<input class="form-control" type="number" id="%1$s" name="tx_scheduler[%1$s]" value="%2$d" min="%3$d" max="%4$d" required="required" />',
                self::FIELD_MAX_PAGES,
                (int)$taskInfo[self::FIELD_MAX_PAGES],
                MonitoringTaskValidator::MAX_PAGES_MIN,
                MonitoringTaskValidator::MAX_PAGES_MAX
            )),
            self::FIELD_MAX_WAIT => $this->field('input', 'scheduler.monitoring.field.maxWait', sprintf(
                '<input class="form-control" type="number" id="%1$s" name="tx_scheduler[%1$s]" value="%2$d" min="0" max="%3$d" required="required" />',
                self::FIELD_MAX_WAIT,
                (int)$taskInfo[self::FIELD_MAX_WAIT],
                MonitoringTaskValidator::MAX_WAIT_MAX
            )),
            self::FIELD_BACKEND_URL => $this->field('input', 'scheduler.monitoring.field.backendUrl', sprintf(
                '<input class="form-control" type="url" id="%1$s" name="tx_scheduler[%1$s]" value="%2$s" placeholder="https://cms.example.org" />',
                self::FIELD_BACKEND_URL,
                htmlspecialchars((string)$taskInfo[self::FIELD_BACKEND_URL])
            )),
        ];
    }

    /**
     * @param array<string, mixed> $submittedData
     */
    public function validateAdditionalFields(array &$submittedData, SchedulerModuleController $schedulerModule): bool
    {
        $errors = $this->monitoringTaskValidator->validate(
            (string)($submittedData[self::FIELD_SITE] ?? ''),
            (int)($submittedData[self::FIELD_LANGUAGE] ?? 0),
            (string)($submittedData[self::FIELD_RECIPIENTS] ?? ''),
            (int)($submittedData[self::FIELD_MAX_PAGES] ?? MonitoringTaskValidator::MAX_PAGES_DEFAULT),
            (int)($submittedData[self::FIELD_MAX_WAIT] ?? MonitoringTaskValidator::MAX_WAIT_DEFAULT),
            (string)($submittedData[self::FIELD_BACKEND_URL] ?? ''),
            true,
        );
        foreach ($errors as $error) {
            $this->addMessage($error, ContextualFeedbackSeverity::ERROR);
        }

        return $errors === [];
    }

    /**
     * @param array<string, mixed> $submittedData
     */
    public function saveAdditionalFields(array $submittedData, AbstractTask $task): void
    {
        if (!$task instanceof MonitoringTask) {
            return;
        }

        $task->setTaskParameters([
            MonitoringTask::PARAM_SITE => $submittedData[self::FIELD_SITE] ?? '',
            MonitoringTask::PARAM_LANGUAGE => $submittedData[self::FIELD_LANGUAGE] ?? 0,
            MonitoringTask::PARAM_RECIPIENTS => implode(', ', MonitoringTaskValidator::parseRecipients((string)($submittedData[self::FIELD_RECIPIENTS] ?? ''))),
            MonitoringTask::PARAM_MAX_PAGES => $submittedData[self::FIELD_MAX_PAGES] ?? MonitoringTaskValidator::MAX_PAGES_DEFAULT,
            MonitoringTask::PARAM_MAX_WAIT => $submittedData[self::FIELD_MAX_WAIT] ?? MonitoringTaskValidator::MAX_WAIT_DEFAULT,
            MonitoringTask::PARAM_BACKEND_URL => $submittedData[self::FIELD_BACKEND_URL] ?? '',
        ]);
    }

    private function renderSiteSelect(string $selected): string
    {
        $options = [sprintf(
            '<option value=""%s>%s</option>',
            $selected === '' ? ' selected="selected"' : '',
            htmlspecialchars($this->label('scheduler.monitoring.field.site.placeholder', 'Choose a site'))
        )];
        foreach ($this->monitoringTargetResolver->siteChoices() as $choice) {
            $options[] = sprintf(
                '<option value="%s"%s>%s</option>',
                htmlspecialchars($choice['identifier']),
                $choice['identifier'] === $selected ? ' selected="selected"' : '',
                htmlspecialchars($choice['label'])
            );
        }

        return sprintf(
            '<select class="form-select" id="%1$s" name="tx_scheduler[%1$s]" required="required" data-aqg-monitoring-site>%2$s</select>',
            self::FIELD_SITE,
            implode('', $options)
        );
    }

    /**
     * One group per site, each option tagged with its site: the script keeps only the selected site's group.
     */
    private function renderLanguageSelect(string $selectedSite, int $selectedLanguage): string
    {
        $groups = [];
        foreach ($this->monitoringTargetResolver->siteChoices() as $choice) {
            $site = $this->monitoringTargetResolver->findSite($choice['identifier']);
            if (!$site instanceof Site) {
                continue;
            }
            $defaultLanguageId = $this->monitoringTargetResolver->defaultLanguageId($site);
            $options = [];
            foreach ($this->monitoringTargetResolver->languageChoices($site) as $language) {
                $options[] = sprintf(
                    '<option value="%d" data-site="%s"%s%s>%s</option>',
                    $language['languageId'],
                    htmlspecialchars($choice['identifier']),
                    $language['languageId'] === $defaultLanguageId ? ' data-default="1"' : '',
                    $choice['identifier'] === $selectedSite && $language['languageId'] === $selectedLanguage ? ' selected="selected"' : '',
                    htmlspecialchars($language['label'])
                );
            }
            $groups[] = sprintf(
                '<optgroup label="%s" data-site="%s">%s</optgroup>',
                htmlspecialchars($choice['title']),
                htmlspecialchars($choice['identifier']),
                implode('', $options)
            );
        }

        return sprintf(
            '<select class="form-select" id="%1$s" name="tx_scheduler[%1$s]" data-aqg-monitoring-language data-placeholder="%2$s">%3$s</select>',
            self::FIELD_LANGUAGE,
            htmlspecialchars($this->label('scheduler.monitoring.field.language.placeholder', 'Choose a site first')),
            implode('', $groups)
        );
    }

    /**
     * @return array{code:string,label:string,description:string,type:string,cshKey:string,cshLabel:string}
     */
    private function field(string $type, string $labelKey, string $code): array
    {
        return [
            'code' => $code,
            'label' => self::LABEL_PREFIX . $labelKey,
            'description' => $this->label($labelKey . '.help', ''),
            'type' => $type,
            'cshKey' => '',
            'cshLabel' => '',
        ];
    }

    /**
     * A new task starts on the only site when there is just one.
     */
    private function singleSiteIdentifier(): string
    {
        $choices = $this->monitoringTargetResolver->siteChoices();

        return count($choices) === 1 ? $choices[0]['identifier'] : '';
    }

    private function defaultLanguageOf(string $siteIdentifier): int
    {
        $site = $this->monitoringTargetResolver->findSite($siteIdentifier);

        return $site instanceof Site ? $this->monitoringTargetResolver->defaultLanguageId($site) : 0;
    }

    private function label(string $key, string $fallback): string
    {
        return BackendLabelUtility::translate($key, $fallback);
    }
}
