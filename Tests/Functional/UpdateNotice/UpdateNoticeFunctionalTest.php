<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\UpdateNotice;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Controller\UpdateNoticeAjaxController;
use Priebera\A11yQualityGate\Pro\Cache\ProCacheManager;
use Priebera\A11yQualityGate\Service\ExtensionContextService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use Priebera\A11yQualityGate\UpdateNotice\LatestReleaseProvider;
use Priebera\A11yQualityGate\UpdateNotice\SemanticVersion;
use Priebera\A11yQualityGate\UpdateNotice\UpdateNoticeDismissalStore;
use Priebera\A11yQualityGate\UpdateNotice\UpdateNoticeService;
use TYPO3\CMS\Backend\Http\RouteDispatcher;
use TYPO3\CMS\Backend\Routing\Exception\InvalidRequestTokenException;
use TYPO3\CMS\Backend\Routing\Exception\MissingRequestTokenException;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\ServerRequest;

/**
 * The update notice with the real TYPO3 user, permission, cache and routing stack: dismissal is stored in the
 * backend user's own record, per release, and the dismiss route only runs with TYPO3's route token.
 */
final class UpdateNoticeFunctionalTest extends AbstractFunctionalTestCase
{
    private const ROUTE = 'ajax_a11y_update_notice_dismiss';

    protected function setUp(): void
    {
        parent::setUp();

        $connection = $this->getConnectionPool()->getConnectionForTable('be_users');
        foreach ([[1, 'admin-one', 1], [2, 'admin-two', 1], [3, 'editor', 0]] as [$uid, $username, $admin]) {
            $connection->insert('be_users', ['uid' => $uid, 'username' => $username, 'admin' => $admin, 'disable' => 0, 'deleted' => 0]);
        }
    }

    #[Test]
    public function aDismissalIsStoredInTheBackendUserRecordForThatReleaseOnly(): void
    {
        $this->setUpBackendUser(1);

        $response = $this->get(UpdateNoticeAjaxController::class)->dismissAction($this->dismissRequest('1.9.9'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['1.9.9'], $this->storedDismissals(1));
        self::assertSame([], $this->storedDismissals(2));

        // A fresh login reads the user configuration back from the database.
        $store = $this->get(UpdateNoticeDismissalStore::class);
        self::assertTrue($store->isDismissed($this->setUpBackendUser(1), $this->version('1.9.9')));
        self::assertFalse($store->isDismissed($this->setUpBackendUser(1), $this->version('1.10.0')));
        self::assertFalse($store->isDismissed($this->setUpBackendUser(2), $this->version('1.9.9')));
    }

    #[Test]
    public function anEditorWhoCannotUpdateExtensionsCannotDismiss(): void
    {
        $this->setUpBackendUser(3);

        $response = $this->get(UpdateNoticeAjaxController::class)->dismissAction($this->dismissRequest('1.9.9'));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([], $this->storedDismissals(3));
    }

    #[Test]
    public function theNoticeFollowsTheReleaseTheInstalledVersionAndEachUsersDismissal(): void
    {
        $installed = SemanticVersion::tryParse($this->get(ExtensionContextService::class)->getExtensionVersion());
        if ($installed === null) {
            self::markTestSkipped('The installed AQG package reports no stable version in this test project.');
        }
        $nextPatch = $installed->major . '.' . $installed->minor . '.' . ($installed->patch + 1);
        $nextMinor = $installed->major . '.' . ($installed->minor + 1) . '.0';
        $service = $this->get(UpdateNoticeService::class);

        $this->publishLatestRelease($installed->toString());
        $this->setUpBackendUser(1);
        self::assertNull($service->buildForCurrentUser(), 'The installed version is the latest.');

        $this->publishLatestRelease($nextPatch);
        self::assertSame($nextPatch, $service->buildForCurrentUser()['version'] ?? null);
        self::assertSame($installed->toString(), $service->buildForCurrentUser()['installedVersion'] ?? null);

        $this->get(UpdateNoticeAjaxController::class)->dismissAction($this->dismissRequest($nextPatch));
        $this->setUpBackendUser(1);
        self::assertNull($service->buildForCurrentUser(), 'The dismissed release stays hidden for this user.');

        $this->setUpBackendUser(2);
        self::assertSame($nextPatch, $service->buildForCurrentUser()['version'] ?? null, 'Another administrator still sees it.');

        $this->setUpBackendUser(3);
        self::assertNull($service->buildForCurrentUser(), 'Editors who cannot update extensions see no notice.');

        $this->publishLatestRelease($nextMinor);
        $this->setUpBackendUser(1);
        self::assertSame($nextMinor, $service->buildForCurrentUser()['version'] ?? null, 'A later release appears again.');
    }

    #[Test]
    public function theDismissRouteRunsOnlyWithTheRouteTokenOfThisRoute(): void
    {
        $this->setUpBackendUser(1);
        $router = $this->get(Router::class);
        $registered = $router->getRoute(self::ROUTE);
        self::assertIsObject($registered);
        self::assertSame(['POST'], $registered->getMethods());
        self::assertNotSame('public', $registered->getOption('access'));
        self::assertSame('web_a11y', $registered->getOption('inheritAccessFromModule'));

        // The route as the backend request handler resolves it for the dispatcher, with its identifier for the token.
        $route = $router->match($registered->getPath());
        self::assertSame(self::ROUTE, $route->getOption('_identifier'));
        $dispatcher = $this->get(RouteDispatcher::class);
        $request = $this->dismissRequest('1.9.9')->withAttribute('route', $route);

        try {
            $dispatcher->dispatch($request);
            self::fail('A request without token must be refused.');
        } catch (MissingRequestTokenException) {
        }

        $formProtection = $this->get(FormProtectionFactory::class)->createForType('backend');
        try {
            $dispatcher->dispatch($request->withParsedBody([
                'version' => '1.9.9',
                'token' => $formProtection->generateToken('route', 'ajax_a11y_ignore'),
            ]));
            self::fail('A token of another route must be refused.');
        } catch (InvalidRequestTokenException) {
        }
        self::assertSame([], $this->storedDismissals(1));

        $response = $dispatcher->dispatch($request->withParsedBody([
            'version' => '1.9.9',
            'token' => $formProtection->generateToken('route', self::ROUTE),
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['1.9.9'], $this->storedDismissals(1));
    }

    private function publishLatestRelease(string $version): void
    {
        $this->get(ProCacheManager::class)->setDisplayPayload(
            LatestReleaseProvider::CACHE_KEY,
            ['release' => ['version' => $version, 'releaseNotesUrl' => 'https://typo3.priebera.sk/docs/changelog']],
            3600,
        );
    }

    private function dismissRequest(string $version): ServerRequest
    {
        return (new ServerRequest('https://typo3-testing.local/typo3/ajax/a11y/update-notice/dismiss', 'POST'))
            ->withParsedBody(['version' => $version]);
    }

    /**
     * @return list<string>
     */
    private function storedDismissals(int $userUid): array
    {
        $uc = $this->getConnectionPool()->getConnectionForTable('be_users')
            ->select(['uc'], 'be_users', ['uid' => $userUid])
            ->fetchOne();
        $configuration = is_string($uc) && $uc !== '' ? unserialize($uc, ['allowed_classes' => false]) : [];

        return is_array($configuration)
            ? array_values($configuration[UpdateNoticeDismissalStore::UC_KEY][UpdateNoticeDismissalStore::UC_FIELD] ?? [])
            : [];
    }

    private function version(string $value): SemanticVersion
    {
        return SemanticVersion::tryParse($value) ?? self::fail('Invalid test version ' . $value);
    }
}
