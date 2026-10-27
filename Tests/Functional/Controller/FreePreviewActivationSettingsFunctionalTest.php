<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Contract\InstallationIdentityServiceInterface;
use Priebera\A11yQualityGate\Controller\SettingsController;
use Priebera\A11yQualityGate\FreePreview\FreePreviewActivationService;
use Priebera\A11yQualityGate\FreePreview\FreePreviewActivationStore;
use Priebera\A11yQualityGate\FreePreview\FreeTermsAcceptance;
use Priebera\A11yQualityGate\FreePreview\PublicSiteAddressClassifier;
use Priebera\A11yQualityGate\Pro\Cache\ProCacheManager;
use Priebera\A11yQualityGate\Pro\Http\AqgApiClient;
use Priebera\A11yQualityGate\Pro\Service\DomainNormalizer;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Settings › Licence › FREE Remote Preview with the real TYPO3 user, routing and registry: only administrators can
 * record or change an activation (on the server, not by hiding the form), an unticked declaration records nothing, and
 * a recorded confirmation survives new requests, logouts and cache flushes because it lives in sys_registry.
 */
final class FreePreviewActivationSettingsFunctionalTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $connection = $this->getConnectionPool()->getConnectionForTable('be_users');
        foreach ([[1, 'admin', 1], [3, 'editor', 0]] as [$uid, $username, $admin]) {
            $connection->insert('be_users', ['uid' => $uid, 'username' => $username, 'admin' => $admin, 'disable' => 0, 'deleted' => 0]);
        }
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    #[Test]
    public function theActivationRoutesArePostOnlyAndNotPublic(): void
    {
        $router = $this->get(Router::class);
        foreach (['web_a11y.settingsFreePreviewAccept', 'web_a11y.settingsFreePreviewToggle', 'web_a11y.settingsFreePreviewRefresh'] as $name) {
            $route = $router->getRoute($name);
            self::assertIsObject($route, $name);
            self::assertSame(['POST'], $route->getMethods(), $name);
            self::assertNotSame('public', $route->getOption('access'), $name);
        }
    }

    #[Test]
    public function anEditorCannotRecordOrChangeAnAcceptance(): void
    {
        $this->setUpBackendUser(3);
        $controller = $this->get(SettingsController::class);
        $body = [
            'site' => 'main', 'organisation' => 'Example GmbH', 'acceptedByName' => 'Eve', 'acceptedByEmail' => 'eve@example.org',
            'acceptTerms' => '1', 'termsVersion' => 'free-2026-11', 'enabled' => '1',
        ];

        foreach (['acceptFreePreviewAction', 'toggleFreePreviewAction', 'refreshFreePreviewAction'] as $action) {
            $response = $controller->{$action}($this->request($body, true));
            self::assertSame(403, $response->getStatusCode(), $action);
            self::assertStringContainsString('admin_only_settings_required', (string)$response->getBody(), $action);
        }
        self::assertNull($this->registry()->get('tx_a11y_quality_gate', 'freePreviewActivation'));
    }

    #[Test]
    public function anAdministratorWithoutTheDeclarationRecordsNothing(): void
    {
        $this->setUpBackendUser(1);

        $response = $this->get(SettingsController::class)->acceptFreePreviewAction($this->request([
            'site' => 'main', 'organisation' => 'Example GmbH', 'acceptedByName' => 'Ada', 'acceptedByEmail' => 'ada@example.org',
            'termsVersion' => 'free-2026-11',
        ]));

        self::assertSame(303, $response->getStatusCode());
        self::assertNull($this->registry()->get('tx_a11y_quality_gate', 'freePreviewActivation'));
    }

    #[Test]
    public function aRecordedConfirmationSurvivesNewRequestsAndCacheFlushes(): void
    {
        $store = new FreePreviewActivationStore($this->registry());
        $store->save(new FreeTermsAcceptance(
            acceptanceId: 'acc_01JABCDEF',
            status: FreeTermsAcceptance::STATUS_VALID,
            domain: 'aqg-client.de',
            siteIdentifier: 'main',
            installationId: 'installation-1',
            termsVersion: 'free-2026-11',
            documents: [],
            statementVersion: 'free-statement-2026-10',
            statementLanguage: 'en',
            statementSha256: str_repeat('a', 64),
            acceptedAt: '2026-10-20T10:15:30Z',
            acceptedAtTimestamp: 1_792_491_330,
            validUntilTimestamp: 0,
            organisationName: 'Example GmbH',
            acceptedByName: 'Ada Admin',
            acceptedByUserUid: 1,
            enabled: true,
            statusCheckedAt: 1_792_491_330,
        ));

        $this->get(CacheManager::class)->flushCaches();
        // Stored in the database, not in a cache: a new request reads it back from sys_registry.
        $stored = $this->getConnectionPool()->getConnectionForTable('sys_registry')
            ->select(['entry_value'], 'sys_registry', ['entry_namespace' => 'tx_a11y_quality_gate', 'entry_key' => 'freePreviewActivation'])
            ->fetchOne();
        self::assertStringContainsString('acc_01JABCDEF', (string)$stored);

        $service = $this->service('installation-1');
        self::assertSame('acc_01JABCDEF', $service->activeAcceptanceId('https://www.aqg-client.de/'));
        self::assertSame('2026-10-20T10:15:30Z', $service->status('https://www.aqg-client.de/')['acceptance']?->acceptedAt);
        self::assertSame(
            FreePreviewActivationService::STATE_IDENTITY_CHANGED,
            $this->service('installation-copy')->status('https://www.aqg-client.de/')['state'],
            'a copy with another installation id does not inherit it'
        );
    }

    #[Test]
    public function anEditorSeesTheFreeStatusOnlyOfSitesTheyCanRead(): void
    {
        $this->createSites(['readable' => [1, 'https://aqg-readable.example/'], 'foreign' => [2, 'https://aqg-foreign.example/']]);
        $this->setUpEditorWithMounts('1');

        $view = $this->activationView();

        self::assertSame(['aqg-readable.example'], array_column($view['sites'], 'domain'));
        self::assertSame(['readable'], array_merge(...array_column($view['sites'], 'siteIdentifiers')));
    }

    #[Test]
    public function sitesSharingADomainShareOneRowAndOneActivation(): void
    {
        $this->createSites([
            'en' => [1, 'https://aqg-client.de/en/'],
            'de' => [2, 'https://www.aqg-client.de/de/'],
            'other' => [3, 'https://aqg-other.example/'],
        ]);
        $installationId = $this->get(InstallationIdentityServiceInterface::class)->getOrCreateInstallationId();
        (new FreePreviewActivationStore($this->registry()))->save($this->acceptance('aqg-client.de', 'de', $installationId));
        $this->setUpEditorWithMounts('1,2,3');

        $rows = $this->activationView()['sites'];

        self::assertSame(['aqg-client.de', 'aqg-other.example'], array_column($rows, 'domain'));
        self::assertSame(['de', 'en'], $rows[0]['siteIdentifiers']);
        self::assertSame('de, en', $rows[0]['siteLabel']);
        self::assertSame(FreePreviewActivationService::STATE_ACTIVE, $rows[0]['state']);
        self::assertSame(FreePreviewActivationService::STATE_NOT_ACTIVATED, $rows[1]['state']);

        // One activation per domain: switching it off through one site switches it off for every site of the domain.
        $service = $this->service($installationId);
        self::assertSame('disabled', $service->setEnabled('https://www.aqg-client.de/de/', false));
        self::assertSame(FreePreviewActivationService::STATE_DISABLED, $service->status('https://aqg-client.de/en/')['state']);
    }

    /** @return array<string, mixed> */
    private function activationView(): array
    {
        $this->request([]);
        $view = (new \ReflectionMethod(SettingsController::class, 'buildFreePreviewActivationView'))
            ->invoke($this->get(SettingsController::class), 'licence', false, null, false);
        self::assertIsArray($view);

        return $view;
    }

    /** @param array<string, array{0:int, 1:string}> $sites identifier => [root page uid, base] */
    private function createSites(array $sites): void
    {
        $pages = $this->getConnectionPool()->getConnectionForTable('pages');
        foreach ($sites as $identifier => [$rootPageId, $base]) {
            $pages->insert('pages', [
                'uid' => $rootPageId, 'pid' => 0, 'title' => 'Root ' . $identifier, 'slug' => '/', 'doktype' => 1,
                'is_siteroot' => 1, 'perms_everybody' => 31, 'deleted' => 0, 'hidden' => 0,
            ]);
            $this->get(SiteWriter::class)->createNewBasicSite($identifier, $rootPageId, $base);
        }
        $this->get(SiteFinder::class)->getAllSites(false);
    }

    private function setUpEditorWithMounts(string $mounts): void
    {
        $this->getConnectionPool()->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid' => 30, 'pid' => 0, 'title' => 'AQG editors', 'groupMods' => 'web_a11y', 'tables_select' => 'pages',
            'db_mountpoints' => $mounts, 'deleted' => 0, 'hidden' => 0,
        ]);
        $this->getConnectionPool()->getConnectionForTable('be_users')
            ->update('be_users', ['usergroup' => '30', 'options' => 3, 'workspace_perms' => 1], ['uid' => 3]);
        $this->setUpBackendUser(3);
    }

    private function acceptance(string $domain, string $siteIdentifier, string $installationId): FreeTermsAcceptance
    {
        return new FreeTermsAcceptance(
            acceptanceId: 'acc_01JSHARED',
            status: FreeTermsAcceptance::STATUS_VALID,
            domain: $domain,
            siteIdentifier: $siteIdentifier,
            installationId: $installationId,
            termsVersion: 'free-2026-11',
            documents: [],
            statementVersion: 'free-statement-2026-10',
            statementLanguage: 'en',
            statementSha256: str_repeat('a', 64),
            acceptedAt: '2026-10-20T10:15:30Z',
            acceptedAtTimestamp: 1_792_491_330,
            validUntilTimestamp: 0,
            organisationName: 'Example GmbH',
            acceptedByName: 'Ada Admin',
            acceptedByUserUid: 1,
            enabled: true,
            statusCheckedAt: 1_792_491_330,
        );
    }

    private function service(string $installationId): FreePreviewActivationService
    {
        $identity = $this->createMock(InstallationIdentityServiceInterface::class);
        $identity->method('getOrCreateInstallationId')->willReturn($installationId);
        $factory = $this->createMock(RequestFactory::class);
        $factory->expects(self::never())->method('request');

        return new FreePreviewActivationService(
            new AqgApiClient($factory),
            new FreePreviewActivationStore($this->registry()),
            $identity,
            new DomainNormalizer(),
            $this->get(ProCacheManager::class),
            new PublicSiteAddressClassifier(),
        );
    }

    private function registry(): Registry
    {
        return $this->get(Registry::class);
    }

    /** @param array<string, string> $body */
    private function request(array $body, bool $json = false): ServerRequest
    {
        $request = (new ServerRequest('https://typo3-testing.local/typo3/module/web/a11y/settings/free-preview', 'POST'))
            ->withParsedBody($body)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams(['HTTP_HOST' => 'typo3-testing.local', 'HTTPS' => 'on']));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $json ? $request->withHeader('Accept', 'application/json') : $request;
    }
}
