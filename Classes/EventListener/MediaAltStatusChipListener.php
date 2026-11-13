<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\EventListener;

use Priebera\A11yQualityGate\Ai\Media\FileMetadataRecordResolver;
use Priebera\A11yQualityGate\Ai\Media\MediaAltStatus;
use Priebera\A11yQualityGate\Database\Tables;
use TYPO3\CMS\Backend\Form\Event\ModifyFileReferenceControlsEvent;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Page\PageRenderer;

/**
 * The ALT status chip on the header of an image in a content element (UX Atlas 05: "ALT missing / ALT own / ALT
 * inherited / Decorative"), also while the image is collapsed. It describes the stored record; the open form keeps it
 * current (media-alt-status.js, loaded by MediaAltStatusWizard; the AI wand while it is on the field). Read-only:
 * nothing is written, and it does not depend on AI being available.
 */
#[AsEventListener(identifier: 'a11y-quality-gate/media-alt-status-chip')]
final class MediaAltStatusChipListener
{
    private const IMAGE_FILE_TYPE = 2;

    private bool $assetsLoaded = false;

    public function __construct(
        private readonly FileMetadataRecordResolver $metadataResolver,
        private readonly PageRenderer $pageRenderer,
    ) {}

    public function __invoke(ModifyFileReferenceControlsEvent $event): void
    {
        $data = $event->getElementData();
        if (($data['inlineParentTableName'] ?? '') !== Tables::TT_CONTENT
            || !isset($GLOBALS['TCA'][Tables::SYS_FILE_REFERENCE]['columns']['tx_a11y_is_decorative'])) {
            return;
        }
        $record = $event->getRecord();
        $file = $record['uid_local'][0]['row'] ?? null;
        if (!is_array($file) || (int)($file['type'] ?? 0) !== self::IMAGE_FILE_TYPE || (int)($file['uid'] ?? 0) <= 0) {
            return;
        }

        try {
            $languageUid = max(0, (int)$this->scalar($record['sys_language_uid'] ?? 0));
            $metadata = $this->metadataResolver->find((int)$file['uid'], $languageUid)
                ?? ($languageUid > 0 ? $this->metadataResolver->find((int)$file['uid'], 0) : null);
        } catch (\Throwable) {
            return;
        }
        $fileDefault = (string)($metadata['values']['alternative'] ?? '');
        $status = MediaAltStatus::of(
            $record['alternative'] ?? null,
            (bool)$this->scalar($record['tx_a11y_is_decorative'] ?? 0),
            $fileDefault,
        );
        // FormEngine's input name of this use's alternative text: the open form keeps the chip current with it.
        $referenceUid = $this->scalar($record['uid'] ?? '');
        $inputName = is_scalar($referenceUid) && (string)$referenceUid !== ''
            ? 'data[' . Tables::SYS_FILE_REFERENCE . '][' . $referenceUid . '][alternative]'
            : '';

        $this->loadAssets();
        $event->setControl('aqgAltStatus', MediaAltStatus::chipHtml($status, $inputName, trim($fileDefault) !== ''));
    }

    private function loadAssets(): void
    {
        if ($this->assetsLoaded) {
            return;
        }
        $this->assetsLoaded = true;
        $this->pageRenderer->addCssFile('EXT:a11y_quality_gate/Resources/Public/Css/media-ai.css');
    }

    private function scalar(mixed $value): mixed
    {
        while (is_array($value)) {
            $value = reset($value);
        }

        return $value === false ? 0 : $value;
    }
}
