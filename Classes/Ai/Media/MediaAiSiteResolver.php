<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Media;

use Priebera\A11yQualityGate\Ai\Editor\EditorAiAccessService;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiException;
use Priebera\A11yQualityGate\Service\ScopeAccessService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Files belong to storages, not to sites, but AI configuration (provider, key, model, privacy, licence) is per site.
 * A Filelist request therefore runs under one site the user chooses from the sites that are usable for them: the user
 * can read the site's root page and AI is ready there for this user. Sites where the file is used come first; with
 * exactly one usable site no choice is shown. The site a request names is always checked against this list again.
 */
final class MediaAiSiteResolver
{
    public function __construct(
        private readonly SiteResolutionService $siteResolutionService,
        private readonly ScopeAccessService $scopeAccessService,
        private readonly EditorAiAccessService $accessService,
    ) {}

    /**
     * @param list<int> $usePageUids pages that show the file (preferred sites)
     * @return list<array{identifier:string,label:string,state:string,provider:string,providerLabel:string,model:string,modelLabel:string,allowImages:bool,usesFile:bool}>
     */
    public function candidates(array $usePageUids = []): array
    {
        $preferred = [];
        foreach (array_unique($usePageUids) as $pageUid) {
            $site = $this->siteResolutionService->resolveSiteByPageId((int)$pageUid);
            if ($site instanceof Site) {
                $preferred[$site->getIdentifier()] = true;
            }
        }

        $sites = [];
        foreach ($this->siteResolutionService->getAllSites() as $site) {
            if (!$site instanceof Site || !$this->scopeAccessService->canReadSite($site)) {
                continue;
            }
            $configuration = $this->accessService->editorConfiguration($site->getIdentifier());
            $sites[] = [
                'identifier' => $site->getIdentifier(),
                'label' => $this->label($site),
                'state' => (string)$configuration['state'],
                'provider' => (string)$configuration['provider'],
                'providerLabel' => (string)$configuration['providerLabel'],
                'model' => (string)$configuration['model'],
                'modelLabel' => (string)$configuration['modelLabel'],
                'allowImages' => (bool)$configuration['allowImages'],
                'usesFile' => isset($preferred[$site->getIdentifier()]),
            ];
        }
        usort($sites, static fn (array $a, array $b): int => [$b['usesFile'], $a['label']] <=> [$a['usesFile'], $b['label']]);

        return $sites;
    }

    /**
     * The site a request runs under: the named one when it is usable, else the only usable one.
     *
     * @param list<array<string,mixed>>|null $candidates
     */
    public function resolve(string $requested, ?array $candidates = null): Site
    {
        $ready = array_values(array_filter(
            $candidates ?? $this->candidates(),
            static fn (array $site): bool => $site['state'] === EditorAiAccessService::STATE_READY,
        ));
        $identifier = '';
        if ($requested !== '') {
            foreach ($ready as $site) {
                if ($site['identifier'] === $requested) {
                    $identifier = $requested;
                }
            }
            if ($identifier === '') {
                throw new EditorAiException('site_not_available', 'AI is not available on this site for you.', 403);
            }
        } elseif (count($ready) === 1) {
            $identifier = (string)$ready[0]['identifier'];
        } elseif ($ready === []) {
            throw new EditorAiException(EditorAiAccessService::STATE_NOT_CONFIGURED, 'AI is not set up on any site you can use.', 422);
        } else {
            throw new EditorAiException('site_required', 'Choose the site whose AI settings apply.', 422);
        }

        $site = $this->siteResolutionService->resolveSiteByIdentifier($identifier);
        if (!$site instanceof Site) {
            throw new EditorAiException('site_not_available', 'AI is not available on this site for you.', 403);
        }

        return $site;
    }

    /** @return array{0:string,1:string} BCP 47 locale and title of a site language, or ['', ''] when the site lacks it */
    public function locale(Site $site, int $languageUid): array
    {
        try {
            $language = $site->getLanguageById($languageUid);
        } catch (\Throwable) {
            return ['', ''];
        }
        $locale = str_replace('_', '-', trim($language->getLocale()->getName()));
        if (preg_match('/^[A-Za-z]{2,8}(?:-[A-Za-z0-9]{2,8})*$/D', $locale) !== 1) {
            $code = strtolower(trim($language->getLocale()->getLanguageCode()));
            $locale = preg_match('/^[a-z]{2,8}$/D', $code) === 1 ? $code : 'en';
        }

        return [$locale, $language->getTitle()];
    }

    /** @return list<array{languageUid:int,title:string,locale:string}> */
    public function languages(Site $site): array
    {
        $languages = [];
        foreach ($site->getLanguages() as $language) {
            [$locale, $title] = $this->locale($site, $language->getLanguageId());
            $languages[] = ['languageUid' => $language->getLanguageId(), 'title' => $title, 'locale' => $locale];
        }

        return $languages;
    }

    private function label(Site $site): string
    {
        $title = trim((string)($site->getConfiguration()['websiteTitle'] ?? ''));
        if ($title === '') {
            $title = trim($site->getDefaultLanguage()->getWebsiteTitle());
        }

        return $title !== '' ? $title . ' (' . $site->getIdentifier() . ')' : $site->getIdentifier();
    }
}
