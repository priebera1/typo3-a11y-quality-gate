<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Controller\SettingsController;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Repository\RulesetRepository;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;

/**
 * Rules, scan fields and the rendered-check switches (including "allow private hosts") apply to every site of the
 * installation, so only TYPO3 administrators change them. An editor with module access (and the default
 * showSettings = 1) is refused for every form of the Settings save route and for the field refresh, also with
 * forged bodies that combine several forms; nothing is written. Administrators keep the full behaviour.
 */
final class GlobalSettingsAuthorizationFunctionalTest extends AbstractFunctionalTestCase
{
    private const ADMIN = 1;
    private const EDITOR = 3;

    protected function setUp(): void
    {
        parent::setUp();
        $pool = $this->getConnectionPool();
        $pool->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid' => 7,
            'title' => 'Accessibility editors',
            'groupMods' => 'web_layout,web_a11y',
            'db_mountpoints' => '1',
            'tables_modify' => 'tt_content,pages',
            'TSconfig' => 'options.a11y_quality_gate.showSettings = 1',
        ]);
        $users = $pool->getConnectionForTable('be_users');
        $users->insert('be_users', ['uid' => self::ADMIN, 'username' => 'admin', 'admin' => 1, 'disable' => 0, 'deleted' => 0]);
        $users->insert('be_users', ['uid' => self::EDITOR, 'username' => 'editor', 'admin' => 0, 'usergroup' => '7', 'disable' => 0, 'deleted' => 0]);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');

        $this->get(RulesetRepository::class)->findOrCreateDefault();
        $fields = $pool->getConnectionForTable(Tables::FIELD_CONFIG);
        foreach ([[11, 'tt_content', 'bodytext'], [12, 'tt_content', 'header'], [13, 'tx_gone', 'text']] as [$uid, $table, $field]) {
            $fields->insert(Tables::FIELD_CONFIG, [
                'uid' => $uid, 'table_name' => $table, 'field_name' => $field, 'field_type' => 'text', 'is_enabled' => 1,
            ]);
        }
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function forgedSaveBodies(): iterable
    {
        yield 'rules form' => [['tab' => 'rules', 'rulesManagementFormSubmitted' => '1', 'enabledRules' => []]];
        yield 'private hosts only' => [[
            'tab' => 'rules',
            'rulesManagementFormSubmitted' => '1',
            'enabledRules' => ['rte.img_alt_missing'],
            'renderedCheckSettings' => ['enabled' => '0', 'allowPrivateHosts' => '1'],
        ]];
        yield 'scan fields form' => [['tab' => 'fields', 'fieldsFormSubmitted' => '1', 'enabledFields' => []]];
        yield 'forms combined, licence tab' => [[
            'tab' => 'licence',
            'fieldsFormSubmitted' => '1',
            'enabledFields' => [],
            'rulesManagementFormSubmitted' => '1',
            'enabledRules' => [],
            'renderedCheckSettings' => ['allowPrivateHosts' => '1'],
        ]];
        yield 'site ruleset selected' => [['tab' => 'rules', 'rulesetSite' => 'main', 'rulesManagementFormSubmitted' => '1', 'enabledRules' => []]];
    }

    #[Test]
    #[DataProvider('forgedSaveBodies')]
    public function anEditorWithModuleAccessCannotChangeGlobalSettings(array $body): void
    {
        $rulesBefore = $this->defaultRulesJson();
        $fieldsBefore = $this->fieldStates();
        $this->setUpBackendUser(self::EDITOR);
        $controller = $this->get(SettingsController::class);

        $json = $controller->saveAction($this->request('/settings/save', $body, true));
        $form = $controller->saveAction($this->request('/settings/save', $body));

        self::assertSame($rulesBefore, $this->defaultRulesJson());
        self::assertSame($fieldsBefore, $this->fieldStates());
        self::assertFalse($this->renderedCheckAllowsPrivateHosts());
        self::assertSame(403, $json->getStatusCode());
        self::assertStringContainsString('admin_only_settings_required', (string)$json->getBody());
        self::assertSame(303, $form->getStatusCode(), 'a form post is answered with the refusal redirect');
        self::assertStringContainsString('tab=', $form->getHeaderLine('Location'));
    }

    #[Test]
    public function anEditorCannotRediscoverScanFields(): void
    {
        $fieldsBefore = $this->fieldStates();
        $this->setUpBackendUser(self::EDITOR);

        $response = $this->get(SettingsController::class)->refreshAction($this->request('/settings/refresh', ['tab' => 'fields'], true));

        self::assertSame($fieldsBefore, $this->fieldStates(), 'the row of a table that no longer exists stays as it was');
        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function anAdministratorStillChangesRulesFieldsAndRenderedCheckSettings(): void
    {
        $this->setUpBackendUser(self::ADMIN);
        $controller = $this->get(SettingsController::class);

        $controller->saveAction($this->request('/settings/save', [
            'tab' => 'rules',
            'rulesManagementFormSubmitted' => '1',
            'enabledRules' => ['rte.img_alt_missing'],
            'renderedCheckSettings' => ['enabled' => '1', 'allowPrivateHosts' => '1'],
        ]));
        self::assertTrue($this->renderedCheckAllowsPrivateHosts());
        self::assertContains('rte.empty_link', $this->disabledRules());
        self::assertNotContains('rte.img_alt_missing', $this->disabledRules());

        $controller->saveAction($this->request('/settings/save', [
            'tab' => 'fields',
            'fieldsFormSubmitted' => '1',
            'enabledFields' => ['tt_content' => ['bodytext']],
        ]));
        self::assertSame([11 => 1, 12 => 0, 13 => 0], $this->fieldStates());

        $controller->refreshAction($this->request('/settings/refresh', ['tab' => 'fields']));
        self::assertSame(10, $this->fieldStatesIncludingHidden()[13] ?? 10, 'discovery hid the field of a table that no longer exists');
    }

    private function request(string $path, array $body, bool $json = false): ServerRequest
    {
        $request = (new ServerRequest('https://typo3-testing.local/typo3/module/web/a11y' . $path, 'POST'))
            ->withParsedBody($body)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams(['HTTP_HOST' => 'typo3-testing.local', 'HTTPS' => 'on']));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $json ? $request->withHeader('Accept', 'application/json') : $request;
    }

    private function defaultRulesJson(): string
    {
        return (string)($this->get(RulesetRepository::class)->findOrCreateDefault()['rules_json'] ?? '');
    }

    private function renderedCheckAllowsPrivateHosts(): bool
    {
        $rules = json_decode($this->defaultRulesJson(), true);

        return (bool)($rules['renderedCheck']['allowPrivateHosts'] ?? $rules['rendered_check']['allowPrivateHosts'] ?? false);
    }

    /** @return list<string> */
    private function disabledRules(): array
    {
        $rules = json_decode($this->defaultRulesJson(), true);

        return array_values(array_map('strval', (array)($rules['disabledRules'] ?? $rules['disabled_rules'] ?? [])));
    }

    /** @return array<int, int> uid => is_enabled of visible rows */
    private function fieldStates(): array
    {
        $rows = $this->getConnectionPool()->getConnectionForTable(Tables::FIELD_CONFIG)
            ->select(['uid', 'is_enabled'], Tables::FIELD_CONFIG, ['deleted' => 0, 'hidden' => 0], [], ['uid' => 'ASC'])
            ->fetchAllAssociative();

        return array_combine(array_map(static fn (array $row): int => (int)$row['uid'], $rows), array_map(static fn (array $row): int => (int)$row['is_enabled'], $rows));
    }

    /** @return array<int, int> */
    private function fieldStatesIncludingHidden(): array
    {
        $rows = $this->getConnectionPool()->getConnectionForTable(Tables::FIELD_CONFIG)
            ->select(['uid', 'is_enabled', 'hidden'], Tables::FIELD_CONFIG, [], [], ['uid' => 'ASC'])
            ->fetchAllAssociative();

        return array_combine(array_map(static fn (array $row): int => (int)$row['uid'], $rows), array_map(static fn (array $row): int => (int)$row['is_enabled'] + 10 * (int)$row['hidden'], $rows));
    }
}
