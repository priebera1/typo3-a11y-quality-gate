<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Media;

use Priebera\A11yQualityGate\Ai\Editor\EditorAiAccessService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;

/**
 * Whether AI media controls appear at all for the current user, decided once per request. Blocked states remove the
 * controls instead of disabling them (UX Atlas, Filelist F): no licence, no permission or no configured site means no
 * AQG entry. Every request is checked again server-side, so this only decides what is rendered.
 */
final class MediaAiAvailability
{
    /** @var array<string,array<string,mixed>> */
    private array $siteConfigurations = [];
    private ?bool $filelistAvailable = null;

    public function __construct(
        private readonly EditorAiAccessService $accessService,
        private readonly MediaAiSiteResolver $siteResolver,
        private readonly SiteResolutionService $siteResolutionService,
    ) {}

    /** At least one site the user can read has image AI ready for them. */
    public function forFilelist(): bool
    {
        if ($this->filelistAvailable === null) {
            $this->filelistAvailable = $this->accessService->userAllows(EditorAiAccessService::FLAG_IMAGES)
                && array_filter($this->siteResolver->candidates(), static fn (array $site): bool => $site['state'] === EditorAiAccessService::STATE_READY) !== [];
        }

        return $this->filelistAvailable;
    }

    /**
     * The editor-side configuration of the site a record belongs to (state, provider and model names, privacy).
     *
     * @return array<string,mixed>
     */
    public function forPage(int $pageUid): array
    {
        $siteIdentifier = $pageUid > 0 ? $this->siteResolutionService->resolveSiteIdentifierForPageId($pageUid, '') : '';
        if (!isset($this->siteConfigurations[$siteIdentifier])) {
            $configuration = $this->accessService->editorConfiguration($siteIdentifier);
            if (!$this->accessService->userAllows(EditorAiAccessService::FLAG_IMAGES) && $configuration['state'] === EditorAiAccessService::STATE_READY) {
                $configuration['state'] = EditorAiAccessService::STATE_NO_PERMISSION;
            }
            $this->siteConfigurations[$siteIdentifier] = $configuration + ['site' => $siteIdentifier];
        }

        return $this->siteConfigurations[$siteIdentifier];
    }
}
