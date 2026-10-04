<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\UpdateNotice;

use Priebera\A11yQualityGate\Contract\AccessControlServiceInterface;
use Priebera\A11yQualityGate\Contract\BackendContextServiceInterface;
use Priebera\A11yQualityGate\Service\ExtensionContextService;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * The "new AQG version available" notice of the AQG backend modules.
 *
 * Only administrators see it: they are the users who can update an extension. For everybody else no release
 * check runs at all. The notice appears when the AQG service reports a stable release newer than the installed
 * one and this user has not dismissed that release. Every failure — unknown installed version, unreachable
 * service, malformed answer, broken cache — ends in "no notice", never in an error on the module page.
 */
final class UpdateNoticeService
{
    public function __construct(
        private readonly LatestReleaseProvider $latestReleaseProvider,
        private readonly UpdateNoticeDismissalStore $dismissalStore,
        private readonly AccessControlServiceInterface $accessControlService,
        private readonly BackendContextServiceInterface $backendContextService,
        private readonly ExtensionContextService $extensionContextService,
    ) {
    }

    public function canSeeUpdateNotices(?BackendUserAuthentication $backendUser): bool
    {
        return $backendUser instanceof BackendUserAuthentication
            && $this->accessControlService->canManageAdminOnlySettings($backendUser);
    }

    /**
     * @return array{version:string, installedVersion:string, releaseNotesUrl:string, updateInstructionsUrl:string}|null
     */
    public function buildForCurrentUser(): ?array
    {
        try {
            $backendUser = $this->backendContextService->getBackendUser();
            if (!$backendUser instanceof BackendUserAuthentication || !$this->canSeeUpdateNotices($backendUser)) {
                return null;
            }

            $installed = SemanticVersion::tryParse($this->extensionContextService->getExtensionVersion());
            if ($installed === null) {
                return null;
            }

            $latest = $this->latestReleaseProvider->getLatestRelease();
            if ($latest === null
                || !$latest->version->isNewerThan($installed)
                || $this->dismissalStore->isDismissed($backendUser, $latest->version)
            ) {
                return null;
            }

            return [
                'version' => $latest->version->toString(),
                'installedVersion' => $installed->toString(),
                'releaseNotesUrl' => $latest->releaseNotesUrl,
                'updateInstructionsUrl' => $latest->updateInstructionsUrl,
            ];
        } catch (\Throwable) {
            return null;
        }
    }
}
