<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\EventListener;

use Priebera\A11yQualityGate\Ai\Media\MediaAiAvailability;
use Priebera\A11yQualityGate\Ai\Media\MediaAiFileGuard;
use Priebera\A11yQualityGate\Ai\Media\MediaAiLabels;
use Priebera\A11yQualityGate\Ai\Media\MediaAiMetadataEditLink;
use Priebera\A11yQualityGate\Service\BackendJavaScriptModuleService;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Template\Components\Buttons\LinkButton;
use TYPO3\CMS\Backend\Template\Components\ModifyButtonBarEvent;
use TYPO3\CMS\Backend\Module\ModuleInterface;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Filelist\Event\ProcessFileListActionsEvent;

/**
 * Filelist integration of AI media (UX Atlas screen 04): one entry "Suggest metadata with AI" in the row's "more
 * actions" menu of image files the user may edit. It opens TYPO3's metadata form of the file, where the AQG wand
 * beside each field makes the suggestion and TYPO3 Save stores it (04 A). AQG has no write path into
 * sys_file_metadata (open decision #1), so the Filelist script offers no multi-file apply: for a selection and after
 * an upload it only says how to continue per file. TYPO3 13 and 14 dispatch different ProcessFileListActionsEvent
 * shapes; both are handled. Nothing is rendered when AI media is not available to the user.
 */
final class FilelistMediaAiListener
{
    private const FILELIST_MODULE = 'media_management';
    private const ACTION_NAME = 'aqgMediaAi';

    private bool $assetsLoaded = false;

    public function __construct(
        private readonly MediaAiAvailability $availability,
        private readonly MediaAiFileGuard $fileGuard,
        private readonly MediaAiMetadataEditLink $editLink,
        private readonly IconFactory $iconFactory,
        private readonly PageRenderer $pageRenderer,
        private readonly BackendJavaScriptModuleService $javaScriptModuleService,
    ) {}

    public function addFileAction(ProcessFileListActionsEvent $event): void
    {
        $resource = $event->getResource();
        if (!$event->isFile() || !$resource instanceof File || !$this->isImage($resource) || !$this->availability->forFilelist()) {
            return;
        }
        // The same check as the server: FAL editMeta, table modify access and the allow-listed fields' exclude flags.
        if ($this->fileGuard->editableFields($resource) === []) {
            return;
        }
        $url = $this->editLink->url($resource->getUid(), $this->returnUrl($event));
        if ($url === '') {
            return;
        }

        $this->loadAssets();
        $label = MediaAiLabels::label('rowAction');
        $button = GeneralUtility::makeInstance(LinkButton::class);
        $button->setHref($url);
        // The Filelist turns a secondary LinkButton into a dropdown item labelled by its title.
        $button->setTitle($label);
        $button->setIcon($this->iconFactory->getIcon('a11y-quality-gate-ai-wand', IconSize::SMALL));
        // setDataAttributes() exists on LinkButton in TYPO3 13 and 14; setAttributes() is TYPO3 14 only (fatal on 13).
        $button->setDataAttributes([
            'aqg-media-ai' => 'open-form',
            'aqg-file-uid' => (string)$resource->getUid(),
        ]);

        if (method_exists($event, 'setAction')) {
            // TYPO3 14: named component groups; the secondary group renders as the "more actions" dropdown.
            $event->setAction($button, self::ACTION_NAME, after: 'metadata');
            return;
        }
        // TYPO3 13: one keyed list; keys outside the primary actions become dropdown items.
        $actions = $event->getActionItems();
        $actions[self::ACTION_NAME] = $button;
        $event->setActionItems($actions);
    }

    /** Loads the Filelist script once per Filelist page, also for empty folders (upload follow-up). */
    public function loadInFilelist(ModifyButtonBarEvent $event): void
    {
        $request = method_exists($event, 'getRequest') ? $event->getRequest() : ($GLOBALS['TYPO3_REQUEST'] ?? null);
        $module = $request instanceof ServerRequestInterface ? $request->getAttribute('module') : null;
        if (!$module instanceof ModuleInterface || $module->getIdentifier() !== self::FILELIST_MODULE || !$this->availability->forFilelist()) {
            return;
        }
        $this->loadAssets();
    }

    private function loadAssets(): void
    {
        if ($this->assetsLoaded) {
            return;
        }
        $this->assetsLoaded = true;
        $this->pageRenderer->addCssFile('EXT:a11y_quality_gate/Resources/Public/Css/media-ai.css');
        $this->pageRenderer->addInlineSetting('aqgMediaAi', 'labels', MediaAiLabels::translated());
        $this->javaScriptModuleService->loadVersionedModule($this->pageRenderer, '@priebera/a11y-quality-gate/media-ai/filelist-media-ai.js');
    }

    /** Back to the Filelist view the action was rendered in (FormEngine sanitises it to a local URL). */
    private function returnUrl(ProcessFileListActionsEvent $event): string
    {
        $request = method_exists($event, 'getRequest') ? $event->getRequest() : ($GLOBALS['TYPO3_REQUEST'] ?? null);
        $normalizedParams = $request instanceof ServerRequestInterface ? $request->getAttribute('normalizedParams') : null;

        return is_object($normalizedParams) && method_exists($normalizedParams, 'getRequestUri') ? (string)$normalizedParams->getRequestUri() : '';
    }

    private function isImage(File $file): bool
    {
        return in_array(strtolower($file->getMimeType()), [...MediaAiFileGuard::RASTER_MIME_TYPES, ...MediaAiFileGuard::SVG_MIME_TYPES], true);
    }
}
