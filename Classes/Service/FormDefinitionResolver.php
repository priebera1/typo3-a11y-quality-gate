<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class FormDefinitionResolver
{
    private const MAX_FLEXFORM_XML_BYTES = 262144;

    /**
     * @return array<string, mixed>|null
     */
    public function resolveFromFlexForm(string $flexFormXml): ?array
    {
        $persistenceIdentifier = $this->extractPersistenceIdentifier($flexFormXml);
        if ($persistenceIdentifier === '') {
            return null;
        }

        return $this->loadFormDefinition($persistenceIdentifier);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function flattenFormElements(array $formDefinition): array
    {
        $elements = [];
        $this->collectElements($formDefinition, $elements);

        return $elements;
    }

    private function extractPersistenceIdentifier(string $flexFormXml): string
    {
        if (trim($flexFormXml) === '' || strlen($flexFormXml) > self::MAX_FLEXFORM_XML_BYTES) {
            return '';
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($flexFormXml);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (!$xml instanceof \SimpleXMLElement) {
            return '';
        }

        try {
            $nodes = $xml->xpath('//*[local-name()="field" and @index="settings.persistenceIdentifier"]//*[local-name()="value"]');
        } catch (\Throwable) {
            return '';
        }
        if (is_array($nodes) && isset($nodes[0])) {
            return trim((string)$nodes[0]);
        }

        try {
            $nodes = $xml->xpath('//*[local-name()="field" and @index="persistenceIdentifier"]//*[local-name()="value"]');
        } catch (\Throwable) {
            return '';
        }
        if (is_array($nodes) && isset($nodes[0])) {
            return trim((string)$nodes[0]);
        }

        return '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadFormDefinition(string $persistenceIdentifier): ?array
    {
        $filePath = GeneralUtility::getFileAbsFileName($persistenceIdentifier);
        if ($filePath === '' || !is_file($filePath) || !is_readable($filePath) || filesize($filePath) === 0) {
            return null;
        }

        try {
            $definition = Yaml::parseFile($filePath);
        } catch (\Throwable) {
            return null;
        }

        return is_array($definition) ? $definition : null;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<int, array<string, mixed>> $elements
     */
    private function collectElements(array $node, array &$elements): void
    {
        if (isset($node['identifier']) || isset($node['type'])) {
            $elements[] = $node;
        }

        foreach (['renderables', 'elements'] as $childrenKey) {
            $children = $node[$childrenKey] ?? null;
            if (!is_array($children)) {
                continue;
            }

            foreach ($children as $child) {
                if (is_array($child)) {
                    $this->collectElements($child, $elements);
                }
            }
        }
    }
}
