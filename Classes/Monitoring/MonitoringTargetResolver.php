<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Monitoring;

use Priebera\A11yQualityGate\Service\SiteLanguageService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Priebera\A11yQualityGate\Utility\BackendLabelUtility;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * The configured TYPO3 sites and site languages a monitoring run can target.
 *
 * The same source feeds the Scheduler form (TYPO3 13 field provider, TYPO3 14 TCA items) and resolves the
 * stored or given target when a run starts, so a site or language removed after the task was saved is
 * reported instead of scanning something else. Only enabled languages count: a disabled language has no
 * public frontend to scan.
 */
final class MonitoringTargetResolver
{
    public function __construct(
        private readonly SiteResolutionService $siteResolutionService,
        private readonly SiteLanguageService $siteLanguageService,
    ) {}

    /**
     * @return list<array{identifier:string,title:string,rootPageId:int,label:string}>
     */
    public function siteChoices(): array
    {
        $choices = [];
        foreach ($this->siteResolutionService->getAllSites() as $site) {
            $title = trim((string)($site->getConfiguration()['websiteTitle'] ?? ''));
            $title = $title !== '' ? $title : $site->getIdentifier();
            $choices[] = [
                'identifier' => $site->getIdentifier(),
                'title' => $title,
                'rootPageId' => $site->getRootPageId(),
                'label' => sprintf(
                    BackendLabelUtility::translate('scheduler.monitoring.site.option', '%1$s (%2$s), root page %3$d'),
                    $title,
                    $site->getIdentifier(),
                    $site->getRootPageId()
                ),
            ];
        }

        usort(
            $choices,
            static fn(array $a, array $b): int => [$a['title'], $a['identifier']] <=> [$b['title'], $b['identifier']]
        );

        return $choices;
    }

    /**
     * @return list<array{languageId:int,label:string}>
     */
    public function languageChoices(Site $site): array
    {
        $defaultLanguageId = $this->defaultLanguageId($site);
        $choices = [];
        foreach ($this->siteLanguageService->getLanguagesForSiteObject($site) as $language) {
            $languageId = (int)$language['languageId'];
            $label = $language['locale'] !== ''
                ? sprintf('%s (%s)', $language['title'], $language['locale'])
                : $language['title'];
            if ($languageId === $defaultLanguageId) {
                $label = sprintf(BackendLabelUtility::translate('scheduler.monitoring.language.default', '%s – default language'), $label);
            }
            $choices[] = ['languageId' => $languageId, 'label' => $label];
        }

        return $choices;
    }

    public function findSite(string $siteIdentifier): ?Site
    {
        return $this->siteResolutionService->resolveSiteByIdentifier($siteIdentifier);
    }

    public function defaultLanguageId(Site $site): int
    {
        try {
            return $site->getDefaultLanguage()->getLanguageId();
        } catch (\Throwable) {
            return 0;
        }
    }

    public function isLanguageOfSite(Site $site, int $languageUid): bool
    {
        foreach ($this->siteLanguageService->getLanguagesForSiteObject($site) as $language) {
            if ((int)$language['languageId'] === $languageUid) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws InvalidMonitoringTargetException when the site is not configured or the language is not enabled on it
     */
    public function resolve(string $siteIdentifier, int $languageUid): Site
    {
        $siteIdentifier = trim($siteIdentifier);
        if ($siteIdentifier === '') {
            throw new InvalidMonitoringTargetException(
                BackendLabelUtility::translate('scheduler.monitoring.validation.siteRequired', 'Choose the site to monitor.'),
                InvalidMonitoringTargetException::SITE_REQUIRED
            );
        }

        $site = $this->findSite($siteIdentifier);
        if (!$site instanceof Site) {
            throw new InvalidMonitoringTargetException(
                sprintf(
                    BackendLabelUtility::translate('scheduler.monitoring.validation.siteMissing', 'The site "%s" is not configured in this TYPO3 installation. Choose a configured site.'),
                    $siteIdentifier
                ),
                InvalidMonitoringTargetException::SITE_MISSING
            );
        }

        if (!$this->isLanguageOfSite($site, $languageUid)) {
            throw new InvalidMonitoringTargetException(
                sprintf(
                    BackendLabelUtility::translate('scheduler.monitoring.validation.languageMissing', 'Language %1$d is not an enabled language of the site "%2$s". Choose one of the site\'s languages.'),
                    $languageUid,
                    $siteIdentifier
                ),
                InvalidMonitoringTargetException::LANGUAGE_MISSING
            );
        }

        return $site;
    }
}
