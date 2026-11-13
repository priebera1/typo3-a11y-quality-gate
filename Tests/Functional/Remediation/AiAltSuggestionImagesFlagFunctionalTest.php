<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Remediation;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Controller\AiAltSuggestionAjaxController;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Http\ServerRequest;

/**
 * User TSconfig options.a11y_quality_gate.ai.images = 0 means "no AI for images and image metadata"
 * (Documentation/Configuration/Permissions.rst). The alt-text suggestion endpoint refused nothing for it: an editor
 * who may edit the image reference got the AI suggestion offered (Page Detail, toolbar assistant) and accepted.
 */
final class AiAltSuggestionImagesFlagFunctionalTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $pool = $this->getConnectionPool();
        foreach ([10 => 'options.a11y_quality_gate.allowImageRemediation = 1' . "\n" . 'options.a11y_quality_gate.ai.images = 0', 11 => 'options.a11y_quality_gate.allowImageRemediation = 1'] as $uid => $tsConfig) {
            $pool->getConnectionForTable('be_groups')->insert('be_groups', [
                'explicit_allowdeny' => 'tt_content:CType:textmedia,tt_content:CType:image',
                'uid' => $uid, 'title' => 'Image editors ' . $uid, 'groupMods' => 'web,web_a11y',
                'tables_select' => 'pages,tt_content,sys_file_reference', 'tables_modify' => 'tt_content,sys_file_reference',
                'non_exclude_fields' => 'sys_file_reference:alternative,sys_file_reference:tx_a11y_is_decorative',
                'db_mountpoints' => '1', 'TSconfig' => $tsConfig, 'deleted' => 0, 'hidden' => 0,
            ]);
            $pool->getConnectionForTable('be_users')->insert('be_users', [
                'uid' => $uid - 8, 'username' => 'editor-' . $uid, 'admin' => 0, 'usergroup' => (string)$uid,
                'workspace_perms' => '1', 'disable' => 0, 'deleted' => 0,
            ]);
        }
        $pool->getConnectionForTable('pages')->insert('pages', [
            'uid' => 1, 'pid' => 0, 'title' => 'Root', 'doktype' => 1, 'is_siteroot' => 1, 'perms_everybody' => 31,
            'deleted' => 0, 'hidden' => 0,
        ]);
        $pool->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => 10, 'pid' => 1, 'CType' => 'textmedia', 'header' => 'Image fixture', 'sys_language_uid' => 0, 'deleted' => 0, 'hidden' => 0,
        ]);
        // A real image file: TYPO3 shows alternative text only on references to images (Sol QA-01 review, form layout).
        $this->getConnectionPool()->getConnectionForTable('sys_file')->insert('sys_file', ['uid' => 1, 'pid' => 0, 'storage' => 1, 'type' => 2, 'name' => 'fixture.png', 'identifier' => '/fixture-1.png', 'mime_type' => 'image/png']);
        $pool->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', [
            'uid' => 20, 'pid' => 1, 'uid_local' => 1, 'uid_foreign' => 10, 'tablenames' => 'tt_content', 'fieldname' => 'assets',
            'alternative' => '', 'tx_a11y_is_decorative' => 0, 'sys_language_uid' => 0, 't3ver_wsid' => 0, 'deleted' => 0, 'hidden' => 0,
        ]);
        $this->insertIssue([
            'uid' => 100, 'site_identifier' => 'main', 'page_uid' => 1, 'source_table' => 'tt_content', 'source_uid' => 10,
            'source_field' => 'assets', 'source_type' => 'structured', 'rule_id' => 'structured.file_reference_alt',
            'context_path' => 'Page:1 > tt_content:10 > assets > ref:20', 'context_snippet' => '',
        ]);
        $this->get(SiteWriter::class)->createNewBasicSite('main', 1, 'https://example.test/');
    }

    #[Test]
    public function anEditorWhoseGroupDisablesImageAiIsRefused(): void
    {
        $this->setUpBackendUser(2);

        $payload = $this->suggest();

        self::assertSame([403, 'permission_denied'], $payload);
    }

    #[Test]
    public function withImageAiAllowedTheRequestReachesTheLicenceCheck(): void
    {
        $this->setUpBackendUser(3);

        // No licence in this fixture: the next gate answers. The user's switch did not refuse.
        self::assertSame([403, 'ai_unavailable'], $this->suggest());
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function suggest(): array
    {
        $response = $this->get(AiAltSuggestionAjaxController::class)->suggestAction(
            (new ServerRequest('https://example.test/', 'POST'))->withParsedBody(['findingId' => 100])
        );
        $payload = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);

        return [$response->getStatusCode(), (string)($payload['code'] ?? '')];
    }
}
