<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Ai\Media;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Backend\Form\NodeFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Regression (3.0 editor-first audit): the ALT chip followed the form only through the AI wand, which FormEngine
 * renders only when image AI is ready. The status wizard on sys_file_reference.alternative loads the status module for
 * every image use in a content element and needs no AI, licence or provider (none is configured here).
 */
final class MediaAltStatusWizardFunctionalTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => 1, 'username' => 'admin', 'admin' => 1, 'disable' => 0, 'deleted' => 0]);
        $this->setUpBackendUser(1);
    }

    #[Test]
    public function theWizardIsConfiguredOnTheAlternativeTextOfAnImageUse(): void
    {
        self::assertSame(
            'a11yMediaAltStatus',
            $GLOBALS['TCA']['sys_file_reference']['columns']['alternative']['config']['fieldWizard']['a11yMediaAltStatus']['renderType'] ?? null,
        );
    }

    #[Test]
    public function withoutAiItLoadsTheStatusModuleAndRendersNoRow(): void
    {
        $result = $this->render();

        // No HTML: FormEngine renders no wizard row under the field (the chip on the header is the indicator).
        self::assertSame('', $result['html']);
        self::assertSame(['@priebera/a11y-quality-gate/media-ai/media-alt-status.js'], $this->modules($result));
    }

    #[Test]
    public function nothingOutsideImageUsesOfContentElements(): void
    {
        self::assertSame([], $this->modules($this->render(parentTable: 'pages')));
        self::assertSame([], $this->modules($this->render(fileType: 4)));
        self::assertSame([], $this->modules($this->render(field: 'title')));
    }

    /** @param array<string, mixed> $result @return list<string> */
    private function modules(array $result): array
    {
        return array_map(static fn (object $instruction): string => $instruction->getName(), $result['javaScriptModules'] ?? []);
    }

    /** @return array<string, mixed> */
    private function render(string $parentTable = 'tt_content', int $fileType = 2, string $field = 'alternative'): array
    {
        return $this->get(NodeFactory::class)->create([
            'renderType' => 'a11yMediaAltStatus',
            'tableName' => 'sys_file_reference',
            'fieldName' => $field,
            'inlineParentTableName' => $parentTable,
            'inlineParentFieldName' => 'image',
            'databaseRow' => [
                'uid' => 20,
                'sys_language_uid' => [0],
                'alternative' => null,
                'uid_local' => [['uid' => 5, 'table' => 'sys_file', 'row' => ['uid' => 5, 'type' => $fileType]]],
            ],
            'parameterArray' => ['itemFormElName' => 'data[sys_file_reference][20][' . $field . ']', 'fieldConf' => ['config' => []]],
        ])->render();
    }
}
