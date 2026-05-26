<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rendered;

final class RenderedHtmlIssueMapper
{
    /**
     * @return array{sourceTable:string,sourceUid:int,sourceField:string,note:string,cType:string}
     */
    public function mapElement(\DOMElement $element): array
    {
        $node = $element;
        while ($node instanceof \DOMElement) {
            $uid = trim($node->getAttribute('data-aqg-content-uid'));
            if ($uid === '') {
                $uid = trim($node->getAttribute('data-aqg-uid'));
            }

            if ($uid !== '' && ctype_digit($uid) && (int)$uid > 0) {
                return [
                    'sourceTable' => 'tt_content',
                    'sourceUid' => (int)$uid,
                    'sourceField' => '__rendered_html',
                    'note' => '',
                    'cType' => trim($node->getAttribute('data-aqg-c-type')) ?: trim($node->getAttribute('data-aqg-ctype')),
                ];
            }

            $parent = $node->parentNode;
            $node = $parent instanceof \DOMElement ? $parent : null;
        }

        return [
            'sourceTable' => 'pages',
            'sourceUid' => 0,
            'sourceField' => '__rendered_html',
            'note' => 'Likely source: template, layout, navigation or plugin output.',
            'cType' => '',
        ];
    }
}
