<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Scheduler;

use Priebera\A11yQualityGate\Monitoring\InvalidMonitoringTargetException;
use Priebera\A11yQualityGate\Monitoring\MonitoringTargetResolver;
use Priebera\A11yQualityGate\Monitoring\MonitoringTaskValidator;
use Priebera\A11yQualityGate\Service\RemoteMonitoringService;
use Priebera\A11yQualityGate\Utility\BackendLabelUtility;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Task\AbstractTask;

/**
 * Scheduled frontend monitoring of one site language (PRO and Agency), configured with the site and
 * language selectors of the Scheduler form. It runs the same monitoring as `a11y:monitor`; Agency
 * installations create one task per site.
 *
 * The task stores plain values only (TYPO3 13 serializes task objects): the site and language are resolved
 * again on every run, so a removed site or language fails the run with a clear message instead of scanning
 * a different page set.
 */
final class MonitoringTask extends AbstractTask
{
    public const PARAM_SITE = 'tx_a11yqualitygate_monitor_site';
    public const PARAM_LANGUAGE = 'tx_a11yqualitygate_monitor_language';
    public const PARAM_RECIPIENTS = 'tx_a11yqualitygate_monitor_recipients';
    public const PARAM_MAX_PAGES = 'tx_a11yqualitygate_monitor_max_pages';
    public const PARAM_MAX_WAIT = 'tx_a11yqualitygate_monitor_max_wait';
    public const PARAM_BACKEND_URL = 'tx_a11yqualitygate_monitor_backend_url';

    public string $siteIdentifier = '';
    public int $languageUid = 0;
    public string $recipients = '';
    public int $maxPages = MonitoringTaskValidator::MAX_PAGES_DEFAULT;
    public int $maxWait = MonitoringTaskValidator::MAX_WAIT_DEFAULT;
    public string $backendUrl = '';

    /**
     * @return array<string, string|int>
     */
    public function getTaskParameters(): array
    {
        return [
            self::PARAM_SITE => $this->siteIdentifier,
            self::PARAM_LANGUAGE => $this->languageUid,
            self::PARAM_RECIPIENTS => $this->recipients,
            self::PARAM_MAX_PAGES => $this->maxPages,
            self::PARAM_MAX_WAIT => $this->maxWait,
            self::PARAM_BACKEND_URL => $this->backendUrl,
        ];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function setTaskParameters(array $parameters): void
    {
        $this->siteIdentifier = trim(self::scalar($parameters, self::PARAM_SITE, $this->siteIdentifier));
        $this->languageUid = (int)self::scalar($parameters, self::PARAM_LANGUAGE, (string)$this->languageUid);
        $this->recipients = trim(self::scalar($parameters, self::PARAM_RECIPIENTS, $this->recipients));
        $this->maxPages = (int)self::scalar($parameters, self::PARAM_MAX_PAGES, (string)$this->maxPages);
        $this->maxWait = (int)self::scalar($parameters, self::PARAM_MAX_WAIT, (string)$this->maxWait);
        $this->backendUrl = trim(self::scalar($parameters, self::PARAM_BACKEND_URL, $this->backendUrl));
    }

    /**
     * TYPO3 14 save validation, called on the stored task (or a new one) with the submitted fields. It also runs
     * when switching the site reloads the form, so it only checks what was entered (see
     * MonitoringTaskValidator); FormEngine requires the site and recipients on the final save.
     *
     * @param array<string, mixed> $parameters
     */
    public function validateTaskParameters(array $parameters): bool
    {
        if (array_intersect_key($parameters, $this->getTaskParameters()) === []) {
            // Choosing the task type reloads the form before these fields exist.
            return true;
        }

        // Submitted values over the stored ones: a partial update is checked as the configuration it produces.
        $candidate = clone $this;
        $candidate->setTaskParameters($parameters);
        $errors = self::getContainerService(MonitoringTaskValidator::class)->validate(
            $candidate->siteIdentifier,
            $candidate->languageUid,
            $candidate->recipients,
            $candidate->maxPages,
            $candidate->maxWait,
            $candidate->backendUrl,
            false,
        );
        if ($errors === []) {
            return true;
        }

        $queue = GeneralUtility::makeInstance(FlashMessageService::class)->getMessageQueueByIdentifier();
        foreach ($errors as $error) {
            $queue->enqueue(new FlashMessage($error, '', ContextualFeedbackSeverity::ERROR));
        }

        return false;
    }

    public function execute(): bool
    {
        $monitoringTargetResolver = self::getContainerService(MonitoringTargetResolver::class);
        try {
            $site = $monitoringTargetResolver->resolve($this->siteIdentifier, $this->languageUid);
        } catch (InvalidMonitoringTargetException $exception) {
            // Stored as the task's last execution failure: the Scheduler list shows why nothing was scanned.
            $this->logger?->error('AQG monitoring task skipped: ' . $exception->getMessage(), $this->logContext());
            throw $exception;
        }

        $result = self::getContainerService(RemoteMonitoringService::class)->run(
            $site,
            $this->languageUid,
            max(MonitoringTaskValidator::MAX_PAGES_MIN, min(MonitoringTaskValidator::MAX_PAGES_MAX, $this->maxPages)),
            max(0, min(MonitoringTaskValidator::MAX_WAIT_MAX, $this->maxWait)),
            MonitoringTaskValidator::parseRecipients($this->recipients),
            $this->backendUrl,
        );

        $this->logger?->info('AQG monitoring task finished', $this->logContext() + [
            'outcome' => $result['outcome'],
            'notified' => $result['notified'],
        ]);

        if ($result['outcome'] === RemoteMonitoringService::OUTCOME_NOT_ENTITLED) {
            throw new \RuntimeException(
                sprintf(
                    BackendLabelUtility::translate('scheduler.monitoring.error.notEntitled', 'Monitoring needs a valid AQG PRO or Agency licence for the site "%s". Nothing was scanned.'),
                    $this->siteIdentifier
                ),
                1790000010
            );
        }

        return true;
    }

    public function getAdditionalInformation(): string
    {
        if ($this->siteIdentifier === '') {
            return BackendLabelUtility::translate('scheduler.monitoring.info.noSite', 'No site selected');
        }

        $monitoringTargetResolver = self::getContainerService(MonitoringTargetResolver::class);
        $site = $monitoringTargetResolver->findSite($this->siteIdentifier);
        if (!$site instanceof Site) {
            return sprintf(
                BackendLabelUtility::translate('scheduler.monitoring.info.siteMissing', 'Site "%s" is no longer configured'),
                $this->siteIdentifier
            );
        }

        $languageLabel = sprintf(
            BackendLabelUtility::translate('scheduler.monitoring.info.languageMissing', 'language %d is not enabled'),
            $this->languageUid
        );
        foreach ($monitoringTargetResolver->languageChoices($site) as $choice) {
            if ($choice['languageId'] === $this->languageUid) {
                $languageLabel = $choice['label'];
                break;
            }
        }

        return sprintf(
            BackendLabelUtility::translate('scheduler.monitoring.info.summary', '%1$s · %2$s · %3$d recipient(s)'),
            $this->siteIdentifier,
            $languageLabel,
            count(MonitoringTaskValidator::parseRecipients($this->recipients))
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function logContext(): array
    {
        return [
            'site' => $this->siteIdentifier,
            'languageUid' => $this->languageUid,
            'maxPages' => $this->maxPages,
            'maxWait' => $this->maxWait,
        ];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private static function scalar(array $parameters, string $key, string $default): string
    {
        $value = $parameters[$key] ?? $default;
        if (is_array($value)) {
            $value = reset($value);
        }

        return is_scalar($value) ? (string)$value : $default;
    }

    /**
     * @template T of object
     * @param class-string<T> $className
     * @return T
     */
    private static function getContainerService(string $className): object
    {
        /** @var T $service */
        $service = GeneralUtility::getContainer()->get($className);

        return $service;
    }
}
