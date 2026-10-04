<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\UpdateNotice;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\UpdateNotice\SemanticVersion;
use Priebera\A11yQualityGate\UpdateNotice\UpdateNoticeDismissalStore;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

final class UpdateNoticeDismissalStoreTest extends TestCase
{
    #[Test]
    public function dismissingWritesTheReleaseToTheUsersOwnConfiguration(): void
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->uc = ['moduleData' => ['web_layout' => ['function' => 1]], 'lang' => 'de'];
        $user->expects(self::once())->method('writeUC');

        (new UpdateNoticeDismissalStore())->dismiss($user, $this->version('1.9.9'));

        self::assertSame(['1.9.9'], $user->uc['tx_a11y_quality_gate']['dismissedUpdateVersions']);
        // The rest of the user configuration is untouched.
        self::assertSame(['web_layout' => ['function' => 1]], $user->uc['moduleData']);
        self::assertSame('de', $user->uc['lang']);
    }

    #[Test]
    public function eachReleaseIsDismissedOnItsOwn(): void
    {
        $store = new UpdateNoticeDismissalStore();
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->uc = [];

        $store->dismiss($user, $this->version('1.9.9'));

        self::assertTrue($store->isDismissed($user, $this->version('1.9.9')));
        self::assertFalse($store->isDismissed($user, $this->version('1.9.10')));
        self::assertFalse($store->isDismissed($user, $this->version('1.10.0')));
    }

    #[Test]
    public function theListStaysShortAndFreeOfDuplicates(): void
    {
        $store = new UpdateNoticeDismissalStore();
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->uc = [];

        for ($patch = 0; $patch < 15; $patch++) {
            $store->dismiss($user, $this->version('1.9.' . $patch));
        }
        $store->dismiss($user, $this->version('1.9.14'));

        $stored = $user->uc['tx_a11y_quality_gate']['dismissedUpdateVersions'];
        self::assertCount(10, $stored);
        self::assertSame('1.9.14', end($stored));
        self::assertSame(array_values(array_unique($stored)), $stored);
        self::assertFalse($store->isDismissed($user, $this->version('1.9.0')));
        self::assertTrue($store->isDismissed($user, $this->version('1.9.5')));
    }

    #[Test]
    public function aDamagedConfigurationReadsAsNothingDismissed(): void
    {
        $store = new UpdateNoticeDismissalStore();
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->uc = ['tx_a11y_quality_gate' => ['dismissedUpdateVersions' => ['<b>1.9.9</b>', 42, null, ['1.9.9']]]];

        self::assertFalse($store->isDismissed($user, $this->version('1.9.9')));

        $user->uc = ['tx_a11y_quality_gate' => 'garbage'];
        self::assertFalse($store->isDismissed($user, $this->version('1.9.9')));
        $store->dismiss($user, $this->version('1.9.9'));
        self::assertTrue($store->isDismissed($user, $this->version('1.9.9')));
    }

    private function version(string $value): SemanticVersion
    {
        return SemanticVersion::tryParse($value) ?? self::fail('Invalid test version ' . $value);
    }
}
