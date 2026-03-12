<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\EventListener;

use TYPO3\CMS\RteCKEditor\Form\Element\Event\BeforePrepareConfigurationForEditorEvent;

final class RteConfigurationListener
{
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

        $configuration['a11yQualityGate'] = [
            'recordUid' => $uid,
            'fieldName' => $field,
        ];

        $event->setConfiguration($configuration);
    }
}
