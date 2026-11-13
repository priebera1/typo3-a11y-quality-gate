<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\ContextMenu;

use Priebera\A11yQualityGate\Ai\Media\MediaAiAvailability;
use Priebera\A11yQualityGate\Ai\Media\MediaAiFileGuard;
use Priebera\A11yQualityGate\Ai\Media\MediaAiLabels;
use Priebera\A11yQualityGate\Ai\Media\MediaAiMetadataEditLink;
use TYPO3\CMS\Backend\ContextMenu\ItemProviders\AbstractProvider;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * "Suggest metadata with AI" in TYPO3's context menu of an image file (UX Atlas 04 A: the documented way to add a
 * row menu entry). The Filelist's tile view offers row actions only through this menu; the list view's "more
 * actions" entry comes from ProcessFileListActionsEvent. Like that entry it opens TYPO3's metadata form of the file,
 * where the AQG wand suggests per field and TYPO3 Save stores the values. Shown only for image files with a metadata
 * record the user may edit while AI media is available to them; every suggestion is checked again server-side.
 */
final class MediaAiFileItemProvider extends AbstractProvider
{
    protected $itemsConfiguration = [
        'aqgMediaAiDivider' => [
            'type' => 'divider',
        ],
        'aqgMediaAi' => [
            'type' => 'item',
            'label' => '',
            'iconIdentifier' => 'a11y-quality-gate-ai-wand',
            'callbackAction' => 'openMetadataForm',
        ],
    ];

    private ?File $file = null;
    private string $editUrl = '';

    public function __construct(
        private readonly MediaAiAvailability $availability,
        private readonly MediaAiFileGuard $fileGuard,
        private readonly ResourceFactory $resourceFactory,
        private readonly MediaAiMetadataEditLink $editLink,
    ) {
        parent::__construct();
        $this->itemsConfiguration['aqgMediaAi']['label'] = MediaAiLabels::label('rowAction');
    }

    public function getPriority(): int
    {
        return 50;
    }

    public function canHandle(): bool
    {
        if ($this->table !== 'sys_file' || $this->identifier === '') {
            return false;
        }
        try {
            $resource = $this->resourceFactory->retrieveFileOrFolderObject($this->identifier);
        } catch (\Throwable) {
            return false;
        }
        if (!$resource instanceof File || $resource->isDeleted()
            || !in_array(strtolower($resource->getMimeType()), [...MediaAiFileGuard::RASTER_MIME_TYPES, ...MediaAiFileGuard::SVG_MIME_TYPES], true)) {
            return false;
        }
        if ($this->fileGuard->editableFields($resource) === [] || !$this->availability->forFilelist()) {
            return false;
        }
        $editUrl = $this->editLink->url($resource->getUid());
        if ($editUrl === '') {
            return false;
        }
        $this->file = $resource;
        $this->editUrl = $editUrl;

        return true;
    }

    protected function canRender(string $itemName, string $type): bool
    {
        return $this->file instanceof File && !in_array($itemName, $this->disabledItems, true);
    }

    protected function getAdditionalAttributes(string $itemName): array
    {
        return [
            'data-callback-module' => '@priebera/a11y-quality-gate/media-ai/context-menu-actions',
            'data-file-uid' => (string)($this->file?->getUid() ?? 0),
            // The browser adds the return URL of the frame the menu was opened in, as TYPO3's own "Edit metadata" does.
            'data-edit-url' => $this->editUrl,
        ];
    }
}
