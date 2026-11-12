<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Editor;

use Priebera\A11yQualityGate\Service\SiteResolutionService;

/**
 * The AI part of the CKEditor plugin and plain HTML wizard configuration, rendered with the form: whether the wand
 * appears, which provider and model the editor is told about, and the privacy switches that decide which actions
 * are offered. The server checks everything again on each request.
 */
final class EditorAiEditorConfiguration
{
    public function __construct(
        private readonly EditorAiAccessService $accessService,
        private readonly SiteResolutionService $siteResolutionService,
    ) {}

    /**
     * A field TYPO3 renders read-only (TCA or TCEFORM readOnly) gets no AI or rule-based action: TYPO3 still submits it
     * and DataHandler does not enforce readOnly, so an applied change would be saved. The live feedback stays.
     *
     * @return array<string,mixed>
     */
    public static function forReadOnlyField(): array
    {
        return ['state' => 'read_only', 'isAdmin' => false, 'rules' => []];
    }

    /** @return array<string,mixed> */
    public function forRecord(int $pageUid, int $languageUid, int $translationParentUid): array
    {
        try {
            $siteIdentifier = $pageUid > 0 ? $this->siteResolutionService->resolveSiteIdentifierForPageId($pageUid, '') : '';

            return $this->accessService->editorConfiguration($siteIdentifier, $languageUid > 0 && $translationParentUid > 0)
                + ['rules' => EditorAiRuleCatalog::ruleIds()];
        } catch (\Throwable) {
            return ['state' => EditorAiAccessService::STATE_NOT_CONFIGURED, 'isAdmin' => false, 'rules' => []];
        }
    }
}
