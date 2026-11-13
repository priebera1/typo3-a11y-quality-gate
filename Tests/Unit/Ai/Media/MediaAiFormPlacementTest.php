<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai\Media;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Media\MediaAiPayload;
use Priebera\A11yQualityGate\Ai\Task\AiStructuredResponse;
use Priebera\A11yQualityGate\Ai\Usage\AiUsage;

/**
 * Atlas audit F-04 (a, f) and F-10: the wand is a TYPO3 field control (the slot to the right of the field, like the file
 * metadata form), "Decorative image" sits directly under the alternative text of an image use, and provenance names the
 * model the way AI settings do. Display only: the TCA column and the DataHandler write set stay as they were.
 */
final class MediaAiFormPlacementTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../..';

    private mixed $tcaBackup = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tcaBackup = $GLOBALS['TCA'] ?? null;
        $GLOBALS['TCA'] = [
            'sys_file_reference' => [
                'columns' => ['alternative' => ['config' => ['type' => 'text']], 'title' => ['config' => ['type' => 'input']], 'description' => ['config' => ['type' => 'text']]],
                'types' => [
                    '0' => ['showitem' => '--palette--;;basicoverlayPalette, --palette--;;filePalette'],
                    '2' => ['showitem' => '--palette--;;imageoverlayPalette, --palette--;;filePalette'],
                    '4' => ['showitem' => '--palette--;;videoOverlayPalette, --palette--;;filePalette'],
                ],
                'palettes' => [
                    'basicoverlayPalette' => ['showitem' => 'title,description'],
                    'extendedBasicOverlayPalette' => ['showitem' => 'alternative,description,--linebreak--,link,title'],
                    'imageoverlayPalette' => ['showitem' => 'alternative,description,--linebreak--,link,title,--linebreak--,crop'],
                    'videoOverlayPalette' => ['showitem' => 'title,description,--linebreak--,autoplay'],
                    'filePalette' => ['showitem' => 'uid_local, hidden, sys_language_uid, l10n_parent'],
                ],
            ],
            'sys_file_metadata' => [
                'columns' => ['alternative' => ['config' => ['type' => 'text']], 'title' => ['config' => ['type' => 'input']], 'description' => ['config' => ['type' => 'text']]],
            ],
        ];
        $this->loadOverride('sys_file_reference.php');
        $this->loadOverride('sys_file_metadata.php');
    }

    /** Runs a TCA override file without its "defined('TYPO3') || die()" guard (no TYPO3 bootstrap in unit tests). */
    private function loadOverride(string $file): void
    {
        $code = (string)file_get_contents(self::ROOT . '/Configuration/TCA/Overrides/' . $file);
        $code = (string)preg_replace(['/^<\?php/', "/defined\('TYPO3'\) \|\| die\(\);/"], '', $code);
        eval($code);
    }

    protected function tearDown(): void
    {
        $GLOBALS['TCA'] = $this->tcaBackup;
        parent::tearDown();
    }

    #[Test]
    public function theWandIsAFieldControlOfTheAlternativeTextAndOfTheMetadataFields(): void
    {
        $reference = $GLOBALS['TCA']['sys_file_reference']['columns']['alternative']['config'];
        self::assertSame('a11yMediaAiControl', $reference['fieldControl']['a11yMediaAi']['renderType'] ?? null);
        // A wizard with HTML renders as its own row; the wand belongs in the control slot. The only wizard is the ALT
        // status loader, which renders no HTML (MediaAltStatusWizardFunctionalTest).
        self::assertSame(['a11yMediaAltStatus'], array_keys($reference['fieldWizard'] ?? []), 'A wizard renders as its own row; the wand belongs in the control slot.');
        self::assertSame('a11yMediaAltStatus', $reference['fieldWizard']['a11yMediaAltStatus']['renderType'] ?? null);
        foreach (['alternative', 'title', 'description'] as $field) {
            self::assertSame('a11yMediaAiControl', $GLOBALS['TCA']['sys_file_metadata']['columns'][$field]['config']['fieldControl']['a11yMediaAi']['renderType'] ?? null);
        }

        $control = (string)file_get_contents(self::ROOT . '/Classes/FormEngine/FieldControl/MediaAiFieldControl.php');
        self::assertStringContainsString("\$result['linkAttributes']", $control);
        self::assertStringNotContainsString('aqg-media-wand-slot', $control);
        $script = (string)file_get_contents(self::ROOT . '/Resources/Public/JavaScript/media-ai/media-ai-field.js');
        self::assertStringNotContainsString('aqg-media-wand-slot', $script, 'The wand is never moved into a row above the field.');
    }

    #[Test]
    public function decorativeSitsDirectlyUnderTheAlternativeTextOfAnImageUse(): void
    {
        $palettes = $GLOBALS['TCA']['sys_file_reference']['palettes'];
        foreach (['imageoverlayPalette', 'extendedBasicOverlayPalette'] as $palette) {
            $items = array_map('trim', explode(',', $palettes[$palette]['showitem']));
            $position = array_search('alternative', $items, true);
            self::assertSame(['alternative', '--linebreak--', 'tx_a11y_is_decorative', '--linebreak--'], array_slice($items, (int)$position, 4), $palette);
        }
        self::assertStringNotContainsString('tx_a11y_is_decorative', $GLOBALS['TCA']['sys_file_reference']['types']['2']['showitem'], 'No second switch at the end of the image form.');
        self::assertStringContainsString('tx_a11y_is_decorative', $GLOBALS['TCA']['sys_file_reference']['types']['0']['showitem'], 'Types without the alternative text keep the switch as before.');
        self::assertStringContainsString('tx_a11y_is_decorative', $GLOBALS['TCA']['sys_file_reference']['types']['4']['showitem']);
        self::assertSame('check', $GLOBALS['TCA']['sys_file_reference']['columns']['tx_a11y_is_decorative']['config']['type']);
    }

    #[Test]
    public function provenanceNamesTheModelLikeAiSettings(): void
    {
        $known = MediaAiPayload::provenance(new AiStructuredResponse([], new AiUsage(5, 1, true), 'anthropic', 'claude-haiku-5-5'), 'v1', []);
        self::assertSame('claude-haiku-5-5', $known['model']);
        self::assertSame('Claude Haiku 5.5', $known['modelLabel']);

        $unknown = MediaAiPayload::provenance(new AiStructuredResponse([], new AiUsage(5, 1, true), 'openai', 'qa-mock-model'), 'v1', []);
        self::assertSame('qa-mock-model', $unknown['modelLabel'], 'An unknown model keeps its id rather than an invented name.');
    }
}
