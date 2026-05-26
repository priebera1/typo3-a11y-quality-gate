<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\EventListener;

use Priebera\A11yQualityGate\Service\LanguageUidResolver;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\RteCKEditor\Form\Element\Event\BeforePrepareConfigurationForEditorEvent;

final class RteConfigurationListener
{
    public function __construct(
        private readonly PageRenderer $pageRenderer,
        private readonly LanguageUidResolver $languageUidResolver,
    ) {
    }

    public function __invoke(BeforePrepareConfigurationForEditorEvent $event): void
    {
        $data = $event->getData();
        $configuration = $event->getConfiguration();

        $table = (string)($data['tableName'] ?? '');
        $field = (string)($data['fieldName'] ?? '');
        $uid = (int)($data['vanillaUid'] ?? $data['uid'] ?? 0);

        if ($table !== 'tt_content' || $uid <= 0 || $field === '') {
            return;
        }

        $configuration['importModules'] ??= [];
        $configuration['contentsCss'] ??= [];

        $pluginModule = [
            'module' => '@priebera/a11y-quality-gate/ckeditor/a11y-plugin.js',
            'exports' => ['default'],
        ];

        $alreadyImported = false;
        foreach ($configuration['importModules'] as $module) {
            if (($module['module'] ?? '') === $pluginModule['module']) {
                $alreadyImported = true;
                break;
            }
        }

        if (!$alreadyImported) {
            $configuration['importModules'][] = $pluginModule;
        }

        $cssFile = 'EXT:a11y_quality_gate/Resources/Public/Css/ckeditor.css';

        if (!in_array($cssFile, $configuration['contentsCss'], true)) {
            $configuration['contentsCss'][] = $cssFile;
        }

        $this->pageRenderer->addCssFile($cssFile);

        $languageUid = $this->languageUidResolver->fromRteData($data);
        $pageUid = $this->resolvePageUid($data);

        $configuration['a11yQualityGate'] = [
            'recordUid' => $uid,
            'fieldName' => $field,
            'languageUid' => $languageUid,
            'pageUid' => $pageUid,
        ];

        $event->setConfiguration($configuration);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resolvePageUid(array $data): int
    {
        $candidates = [
            $data['effectivePid'] ?? null,
            $data['pid'] ?? null,
            $data['databaseRow']['pid'] ?? null,
            $data['parentPageRow']['uid'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $pageUid = $this->normalizePageUid($candidate);
            if ($pageUid > 0) {
                return $pageUid;
            }
        }

        $recordUid = (int)($data['vanillaUid'] ?? $data['uid'] ?? 0);
        if ($recordUid > 0) {
            $record = BackendUtility::getRecord('tt_content', $recordUid, 'uid,pid,deleted,t3ver_oid');
            if (is_array($record) && (int)($record['deleted'] ?? 0) !== 1) {
                return $this->resolvePageUidFromRecord($record);
            }
        }

        return 0;
    }

    private function normalizePageUid(mixed $value): int
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        $pageUid = (int)$value;
        if ($pageUid > 0) {
            return $pageUid;
        }

        if ($pageUid < -1) {
            $anchorRecord = BackendUtility::getRecord('tt_content', abs($pageUid), 'uid,pid,deleted,t3ver_oid');
            if (is_array($anchorRecord) && (int)($anchorRecord['deleted'] ?? 0) !== 1) {
                return $this->resolvePageUidFromRecord($anchorRecord);
            }
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function resolvePageUidFromRecord(array $record): int
    {
        $pid = (int)($record['pid'] ?? 0);
        if ($pid > 0) {
            return $pid;
        }

        $versionedOriginalUid = (int)($record['t3ver_oid'] ?? 0);
        if ($pid === -1 && $versionedOriginalUid > 0) {
            $original = BackendUtility::getRecord('tt_content', $versionedOriginalUid, 'uid,pid,deleted,t3ver_oid');
            if (is_array($original) && (int)($original['deleted'] ?? 0) !== 1) {
                return $this->resolvePageUidFromRecord($original);
            }
        }

        if ($pid < -1) {
            $anchorRecord = BackendUtility::getRecord('tt_content', abs($pid), 'uid,pid,deleted,t3ver_oid');
            if (is_array($anchorRecord) && (int)($anchorRecord['deleted'] ?? 0) !== 1) {
                return $this->resolvePageUidFromRecord($anchorRecord);
            }
        }

        return 0;
    }
}

