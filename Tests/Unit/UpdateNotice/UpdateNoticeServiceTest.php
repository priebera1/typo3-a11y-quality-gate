<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\UpdateNotice;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Contract\AccessControlServiceInterface;
use Priebera\A11yQualityGate\Contract\BackendContextServiceInterface;
use Priebera\A11yQualityGate\Service\ExtensionContextService;
use Priebera\A11yQualityGate\UpdateNotice\LatestRelease;
use Priebera\A11yQualityGate\UpdateNotice\LatestReleaseProvider;
use Priebera\A11yQualityGate\UpdateNotice\SemanticVersion;
use Priebera\A11yQualityGate\UpdateNotice\UpdateNoticeDismissalStore;
use Priebera\A11yQualityGate\UpdateNotice\UpdateNoticeService;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

final class UpdateNoticeServiceTest extends TestCase
{
    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function installedAndLatest(): array
    {
        return [
            'installed is the latest' => ['1.9.8', '1.9.8', false],
            'installed is newer than reported' => ['1.9.9', '1.9.8', false],
            'installed is a development build' => ['dev-main', '1.9.9', false],
            'installed version unknown' => ['unknown', '1.9.9', false],
            'newer patch' => ['1.9.8', '1.9.9', true],
            'two-digit patch' => ['1.9.9', '1.9.10', true],
            'newer minor' => ['1.9.10', '1.10.0', true],
        ];
    }

    #[Test]
    #[DataProvider('installedAndLatest')]
    public function aNoticeAppearsOnlyForANewerStableRelease(string $installed, string $latest, bool $expectNotice): void
    {
        $notice = $this->service($this->admin(), $installed, $latest)->buildForCurrentUser();

        if (!$expectNotice) {
            self::assertNull($notice);
            return;
        }

        self::assertSame([
            'version' => $latest,
            'installedVersion' => $installed,
            'releaseNotesUrl' => 'https://typo3.priebera.sk/docs/changelog#v' . str_replace('.', '', $latest),
            'updateInstructionsUrl' => LatestRelease::DEFAULT_UPDATE_INSTRUCTIONS_URL,
        ], $notice);
    }

    #[Test]
    public function aDismissedReleaseStaysHiddenAndTheNextOneAppears(): void
    {
        $admin = $this->admin();
        (new UpdateNoticeDismissalStore())->dismiss($admin, SemanticVersion::tryParse('1.9.9') ?? self::fail());

        self::assertNull($this->service($admin, '1.9.8', '1.9.9')->buildForCurrentUser());
        self::assertSame('1.10.0', $this->service($admin, '1.9.8', '1.10.0')->buildForCurrentUser()['version'] ?? null);
    }

    #[Test]
    public function anotherBackendUserStillSeesADismissedRelease(): void
    {
        $first = $this->admin();
        $second = $this->admin();
        (new UpdateNoticeDismissalStore())->dismiss($first, SemanticVersion::tryParse('1.9.9') ?? self::fail());

        self::assertNull($this->service($first, '1.9.8', '1.9.9')->buildForCurrentUser());
        self::assertSame('1.9.9', $this->service($second, '1.9.8', '1.9.9')->buildForCurrentUser()['version'] ?? null);
    }

    #[Test]
    public function usersWhoCannotUpdateExtensionsNeverTriggerTheCheck(): void
    {
        $provider = $this->createMock(LatestReleaseProvider::class);
        $provider->expects(self::never())->method('getLatestRelease');

        $editor = $this->createMock(BackendUserAuthentication::class);
        $editor->uc = [];

        self::assertNull($this->service($editor, '1.9.8', '1.9.9', false, $provider)->buildForCurrentUser());
        self::assertNull($this->service(null, '1.9.8', '1.9.9', true, $provider)->buildForCurrentUser());
    }

    #[Test]
    public function anUnavailableReleaseCheckShowsNothing(): void
    {
        $unavailable = $this->createMock(LatestReleaseProvider::class);
        $unavailable->method('getLatestRelease')->willReturn(null);
        self::assertNull($this->service($this->admin(), '1.9.8', '1.9.9', true, $unavailable)->buildForCurrentUser());

        $broken = $this->createMock(LatestReleaseProvider::class);
        $broken->method('getLatestRelease')->willThrowException(new \RuntimeException('cache backend down'));
        self::assertNull($this->service($this->admin(), '1.9.8', '1.9.9', true, $broken)->buildForCurrentUser());
    }

    private function admin(): BackendUserAuthentication
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->uc = [];

        return $user;
    }

    private function service(
        ?BackendUserAuthentication $user,
        string $installed,
        string $latest,
        bool $isAdmin = true,
        ?LatestReleaseProvider $provider = null,
    ): UpdateNoticeService {
        if ($provider === null) {
            $provider = $this->createMock(LatestReleaseProvider::class);
            $provider->method('getLatestRelease')->willReturn(LatestRelease::fromArray([
                'version' => $latest,
                'releaseNotesUrl' => 'https://typo3.priebera.sk/docs/changelog#v' . str_replace('.', '', $latest),
            ]));
        }

        $accessControl = $this->createMock(AccessControlServiceInterface::class);
        $accessControl->method('canManageAdminOnlySettings')->willReturn($isAdmin);
        $context = $this->createMock(BackendContextServiceInterface::class);
        $context->method('getBackendUser')->willReturn($user);
        $extension = $this->createMock(ExtensionContextService::class);
        $extension->method('getExtensionVersion')->willReturn($installed);

        return new UpdateNoticeService($provider, new UpdateNoticeDismissalStore(), $accessControl, $context, $extension);
    }
}
