<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Contract\AccessControlServiceInterface;
use Priebera\A11yQualityGate\Contract\BackendContextServiceInterface;
use Priebera\A11yQualityGate\Controller\UpdateNoticeAjaxController;
use Priebera\A11yQualityGate\Service\ExtensionContextService;
use Priebera\A11yQualityGate\UpdateNotice\LatestReleaseProvider;
use Priebera\A11yQualityGate\UpdateNotice\UpdateNoticeDismissalStore;
use Priebera\A11yQualityGate\UpdateNotice\UpdateNoticeService;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\ServerRequest;

final class UpdateNoticeAjaxControllerTest extends TestCase
{
    #[Test]
    public function anAdministratorDismissesOneRelease(): void
    {
        $user = $this->user();
        $user->expects(self::once())->method('writeUC');

        $response = $this->controller($user, true)->dismissAction($this->post(['version' => '1.9.9']));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['success' => true, 'version' => '1.9.9'], json_decode((string)$response->getBody(), true));
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertSame(['1.9.9'], $user->uc['tx_a11y_quality_gate']['dismissedUpdateVersions']);
    }

    #[Test]
    public function withoutABackendUserNothingIsStored(): void
    {
        $response = $this->controller(null, true)->dismissAction($this->post(['version' => '1.9.9']));

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function aUserWhoDoesNotSeeUpdateNoticesCannotDismissThem(): void
    {
        $editor = $this->user();
        $editor->expects(self::never())->method('writeUC');

        $response = $this->controller($editor, false)->dismissAction($this->post(['version' => '1.9.9']));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], $editor->uc);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidBodies(): array
    {
        return [
            'no body' => [null],
            'missing version' => [['release' => '1.9.9']],
            'pre-release' => [['version' => '1.9.9-beta1']],
            'branch' => [['version' => 'dev-main']],
            'array' => [['version' => ['1.9.9']]],
            'markup' => [['version' => '<script>alert(1)</script>']],
            'table and uid instead of a version' => [['table' => 'be_users', 'uid' => 1, 'field' => 'uc']],
        ];
    }

    #[Test]
    #[DataProvider('invalidBodies')]
    public function onlyAStableReleaseVersionIsAccepted(mixed $body): void
    {
        $user = $this->user();
        $user->expects(self::never())->method('writeUC');

        $response = $this->controller($user, true)->dismissAction($this->post($body));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('invalid_version', json_decode((string)$response->getBody(), true)['code'] ?? null);
        self::assertSame([], $user->uc);
    }

    private function user(): BackendUserAuthentication
    {
        $user = $this->createMock(BackendUserAuthentication::class);
        $user->uc = [];

        return $user;
    }

    private function post(mixed $body): ServerRequest
    {
        return (new ServerRequest('https://example.test/typo3/ajax/a11y/update-notice/dismiss', 'POST'))
            ->withParsedBody(is_array($body) ? $body : null);
    }

    private function controller(?BackendUserAuthentication $user, bool $isAdmin): UpdateNoticeAjaxController
    {
        $context = $this->createMock(BackendContextServiceInterface::class);
        $context->method('getBackendUser')->willReturn($user);
        $accessControl = $this->createMock(AccessControlServiceInterface::class);
        $accessControl->method('canManageAdminOnlySettings')->willReturn($isAdmin);
        $store = new UpdateNoticeDismissalStore();

        return new UpdateNoticeAjaxController(
            new UpdateNoticeService(
                $this->createMock(LatestReleaseProvider::class),
                $store,
                $accessControl,
                $context,
                $this->createMock(ExtensionContextService::class),
            ),
            $store,
            $context,
        );
    }
}
