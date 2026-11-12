<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Editor;

use Priebera\A11yQualityGate\Service\BackendUserService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Resource\ResourceFactory;

/**
 * Builds the minimal, server-derived context an editor AI request sends: the target element, the text of its block
 * and, only where the site allows it, page titles. Link targets lose query strings, fragments and credentials; e-mail
 * addresses and phone numbers are reduced to their type. Every key that leaves AQG is listed in "sent", which the
 * editor sees under "What was sent?".
 */
final class EditorAiContextBuilder
{
    public const MAX_BLOCK_TEXT = 500;
    /** E-mail addresses never leave AQG, not even inside text. */
    public const EMAIL_PATTERN = '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i';
    private const BLOCK_TAGS = ['p', 'li', 'td', 'th', 'dd', 'dt', 'figcaption', 'figure', 'blockquote', 'caption', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'section', 'article', 'form', 'nav', 'aside', 'header', 'footer', 'main', 'ul', 'ol', 'table'];

    public function __construct(
        private readonly ResourceFactory $resourceFactory,
        private readonly BackendUserService $backendUserService,
        private readonly ?SiteResolutionService $siteResolutionService = null,
    ) {}

    /**
     * @return array{context:array<string,string|int|bool>,sent:list<string>,includesPageTitle:bool}
     */
    public function forFix(EditorFindingTarget $target, EditorAiRecordContext $record, bool $allowPageTitle, string $ruleMessage): array
    {
        $element = $target->element ?? throw new \InvalidArgumentException('The target element is missing.');
        $context = [
            'kind' => $target->kind,
            'rule_id' => $target->ruleId,
            'finding' => $this->bounded($ruleMessage, 200),
            'target_locale' => $record->targetLocale,
        ];
        $sent = [];
        $includesPageTitle = false;

        switch ($target->kind) {
            case EditorAiRuleCatalog::KIND_LINK_TEXT:
                $context['current_link_text'] = $this->bounded($target->text, 200);
                [$linkContext, $linkTitle] = $this->linkTarget($element->getAttribute('href'), $allowPageTitle, $record->siteIdentifier);
                $context['link_target'] = $linkContext;
                $sent = ['linkText', 'linkTarget'];
                if ($linkTitle !== '') {
                    $context['link_target_title'] = $linkTitle;
                    $sent[] = 'linkTargetTitle';
                    $includesPageTitle = true;
                }
                $context['surrounding_text'] = $this->blockText($element);
                $sent[] = 'surroundingText';
                break;

            case EditorAiRuleCatalog::KIND_IMAGE_ALT:
                $context['current_alt'] = $this->bounded($target->currentValue, 300);
                $context['image_file_name'] = $this->bounded(basename((string)strtok($element->getAttribute('src'), '?#')), 120);
                $link = $this->closest($element, ['a']);
                if ($link !== null && $link->hasAttribute('href')) {
                    [$linkContext] = $this->linkTarget($link->getAttribute('href'), false);
                    $context['link_target'] = $linkContext;
                    $sent[] = 'linkTarget';
                }
                $figure = $this->closest($element, ['figure']);
                $caption = $figure?->getElementsByTagName('figcaption')->item(0);
                if ($caption instanceof \DOMElement) {
                    $context['caption'] = $this->bounded(EditorFindingTargetResolver::normalizeText($caption->textContent), 300);
                    $sent[] = 'caption';
                }
                $context['surrounding_text'] = $this->blockText($element);
                $sent = ['image', 'currentAlt', ...$sent, 'surroundingText'];
                break;

            case EditorAiRuleCatalog::KIND_BUTTON_LABEL:
                $context['button_context'] = $this->bounded(implode('; ', array_filter([
                    'type=' . ($element->getAttribute('type') !== '' ? $element->getAttribute('type') : 'submit'),
                    $element->getAttribute('class') !== '' ? 'class=' . $element->getAttribute('class') : '',
                    $element->getAttribute('name') !== '' ? 'name=' . $element->getAttribute('name') : '',
                    $this->iconHints($element),
                ])), 300);
                $form = $this->closest($element, ['form']);
                if ($form !== null) {
                    $context['form_context'] = $this->formContext($form);
                    $sent[] = 'formContext';
                }
                $context['surrounding_text'] = $this->blockText($element);
                $sent = ['buttonMarkup', ...$sent, 'surroundingText'];
                break;

            case EditorAiRuleCatalog::KIND_IFRAME_TITLE:
                [$source] = $this->linkTarget($element->getAttribute('src'), false);
                $context['iframe_source'] = $source;
                $context['surrounding_text'] = $this->blockText($element);
                $sent = ['iframeSource', 'surroundingText'];
                break;

            case EditorAiRuleCatalog::KIND_HEADING_TEXT:
                $context['heading_level'] = $target->headingLevel;
                $context['previous_heading'] = $this->previousHeadingText($element);
                $context['following_text'] = $this->followingText($element);
                $sent = ['headingLevel', 'previousHeading', 'followingText'];
                break;

            default:
                throw new \InvalidArgumentException('No AI fix context for this kind.');
        }

        if ($allowPageTitle) {
            $pageTitle = $this->pageTitle($record->pageUid);
            if ($pageTitle !== '') {
                $context['page_title'] = $pageTitle;
                $sent[] = 'pageTitle';
                $includesPageTitle = true;
            }
        }

        return ['context' => $context, 'sent' => array_values(array_unique($sent)), 'includesPageTitle' => $includesPageTitle];
    }

    /**
     * @return array{context:array<string,string|int|bool>,sent:list<string>,includesPageTitle:bool}
     */
    public function forExplain(EditorFindingTarget $target, EditorAiRecordContext $record, bool $allowPageTitle, string $ruleMessage, string $ruleHint): array
    {
        $element = $target->element ?? throw new \InvalidArgumentException('The target element is missing.');
        $summary = '<' . $target->tag . '>';
        foreach (['href', 'src', 'alt', 'title', 'aria-label', 'type'] as $attribute) {
            if (array_key_exists($attribute, $target->attributes)) {
                $value = $attribute === 'href' || $attribute === 'src'
                    ? $this->linkTarget($target->attributes[$attribute], false)[0]
                    : $target->attributes[$attribute];
                $summary .= ' ' . $attribute . '="' . $this->bounded($value, 120) . '"';
            }
        }
        if ($target->text !== '') {
            $summary .= ' text="' . $this->bounded($target->text, 160) . '"';
        }
        $context = [
            'rule_id' => $target->ruleId,
            'finding' => $this->bounded($ruleMessage, 200),
            'rule_guidance' => $this->bounded($ruleHint, 300),
            'target_locale' => $record->targetLocale,
            'element_summary' => $summary,
            'surrounding_text' => $this->blockText($element),
        ];
        $sent = ['elementSummary', 'surroundingText'];
        $includesPageTitle = false;
        if ($allowPageTitle) {
            $pageTitle = $this->pageTitle($record->pageUid);
            if ($pageTitle !== '') {
                $context['page_title'] = $pageTitle;
                $sent[] = 'pageTitle';
                $includesPageTitle = true;
            }
        }

        return ['context' => $context, 'sent' => $sent, 'includesPageTitle' => $includesPageTitle];
    }

    /**
     * A link or embed target without query, fragment or credentials, with TYPO3 links described by type.
     *
     * @return array{0:string,1:string} description and, for internal page links on sites that allow it, the page title
     */
    public function linkTarget(string $href, bool $allowPageTitle, string $siteIdentifier = ''): array
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($href === '' || preg_match('/[\x00-\x1F\x7F<>]/u', $href) === 1) {
            return ['', ''];
        }
        $lower = strtolower($href);
        if (str_starts_with($lower, 'mailto:') || preg_match('/^[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}$/i', $href) === 1) {
            return ['e-mail address', ''];
        }
        if (str_starts_with($lower, 'tel:')) {
            return ['phone number', ''];
        }
        if (str_starts_with($lower, 't3://page')) {
            parse_str((string)parse_url($href, PHP_URL_QUERY), $query);
            $pageUid = (int)($query['uid'] ?? 0);
            $title = $allowPageTitle ? $this->readablePageTitle($pageUid, $siteIdentifier) : '';
            return ['internal TYPO3 page', $title];
        }
        if (str_starts_with($lower, 't3://file')) {
            parse_str((string)parse_url($href, PHP_URL_QUERY), $query);
            return ['file: ' . $this->fileName((int)($query['uid'] ?? 0)), ''];
        }
        if (str_starts_with($lower, 't3://url')) {
            parse_str((string)parse_url($href, PHP_URL_QUERY), $query);
            return $this->linkTarget((string)($query['url'] ?? ''), false);
        }
        if (str_starts_with($lower, 't3://')) {
            return ['internal TYPO3 record', ''];
        }
        if (str_starts_with($href, '#')) {
            return ['anchor on the same page', ''];
        }

        $parts = parse_url($href);
        if ($parts === false) {
            return ['', ''];
        }
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if ($scheme !== '' && !in_array($scheme, ['http', 'https'], true)) {
            return [$scheme . ' link', ''];
        }
        $host = (string)($parts['host'] ?? '');
        $path = (string)($parts['path'] ?? '');
        $url = ($host !== '' ? ($scheme !== '' ? $scheme . '://' : '//') . $host : '') . $path;

        return [$this->bounded($url, 300), ''];
    }

    private function blockText(\DOMElement $element): string
    {
        $block = $this->closest($element, self::BLOCK_TAGS) ?? $element;
        $text = (string)preg_replace(self::EMAIL_PATTERN, '[e-mail address]', EditorFindingTargetResolver::normalizeText($block->textContent));
        if (mb_strlen($text) <= self::MAX_BLOCK_TEXT) {
            return $text;
        }
        // Keep a window around the target so long blocks still show the sentence it is in.
        $needle = (string)preg_replace(self::EMAIL_PATTERN, '[e-mail address]', EditorFindingTargetResolver::normalizeText($element->textContent));
        $position = $needle !== '' ? mb_strpos($text, $needle) : false;
        $start = $position === false ? 0 : max(0, $position - (int)(self::MAX_BLOCK_TEXT / 2));

        return mb_substr($text, $start, self::MAX_BLOCK_TEXT);
    }

    /** @param list<string> $tags */
    private function closest(\DOMElement $element, array $tags): ?\DOMElement
    {
        $node = $element->parentNode;
        while ($node instanceof \DOMElement && strtolower($node->tagName) !== 'body') {
            if (in_array(strtolower($node->tagName), $tags, true)) {
                return $node;
            }
            $node = $node->parentNode;
        }

        return null;
    }

    private function iconHints(\DOMElement $button): string
    {
        $hints = [];
        foreach ($button->getElementsByTagName('*') as $child) {
            if ($child instanceof \DOMElement) {
                foreach (['class', 'data-icon', 'title'] as $attribute) {
                    if (trim($child->getAttribute($attribute)) !== '') {
                        $hints[] = strtolower($child->tagName) . ' ' . $attribute . '=' . trim($child->getAttribute($attribute));
                    }
                }
            }
        }

        return $hints === [] ? '' : 'inner: ' . implode(', ', array_slice($hints, 0, 5));
    }

    private function formContext(\DOMElement $form): string
    {
        [$action] = $this->linkTarget($form->getAttribute('action'), false);
        $fields = [];
        foreach (['input', 'select', 'textarea', 'label'] as $tag) {
            foreach ($form->getElementsByTagName($tag) as $field) {
                if (!$field instanceof \DOMElement) {
                    continue;
                }
                $fields[] = $tag === 'label'
                    ? 'label "' . EditorFindingTargetResolver::normalizeText($field->textContent) . '"'
                    : $tag . ' ' . implode(' ', array_filter([
                        $field->getAttribute('type') !== '' ? 'type=' . $field->getAttribute('type') : '',
                        $field->getAttribute('name') !== '' ? 'name=' . $field->getAttribute('name') : '',
                        $field->getAttribute('placeholder') !== '' ? 'placeholder=' . $field->getAttribute('placeholder') : '',
                    ]));
            }
        }

        return $this->bounded(trim(($action !== '' ? 'action=' . $action . '; ' : '') . implode('; ', array_slice($fields, 0, 8))), 400);
    }

    private function previousHeadingText(\DOMElement $heading): string
    {
        $xpath = new \DOMXPath($heading->ownerDocument);
        $previous = '';
        foreach ($xpath->query('//h1|//h2|//h3|//h4|//h5|//h6') ?: [] as $node) {
            if ($node === $heading) {
                return $previous;
            }
            $previous = $this->bounded(EditorFindingTargetResolver::normalizeText((string)$node->textContent), 150);
        }

        return '';
    }

    /** Text after the heading up to the next heading, as the basis of the heading text. */
    private function followingText(\DOMElement $heading): string
    {
        $text = '';
        $node = $heading;
        while ($node !== null && mb_strlen($text) < self::MAX_BLOCK_TEXT) {
            $sibling = $node->nextSibling;
            while ($sibling === null && $node->parentNode instanceof \DOMElement && strtolower($node->parentNode->nodeName) !== 'body') {
                $node = $node->parentNode;
                $sibling = $node->nextSibling;
            }
            if ($sibling === null) {
                break;
            }
            if ($sibling instanceof \DOMElement && preg_match('/^h[1-6]$/', strtolower($sibling->tagName)) === 1) {
                break;
            }
            $text .= ' ' . $sibling->textContent;
            $node = $sibling;
        }

        return $this->bounded(EditorFindingTargetResolver::normalizeText($text), self::MAX_BLOCK_TEXT);
    }

    private function pageTitle(int $pageUid): string
    {
        if ($pageUid <= 0) {
            return '';
        }
        $page = BackendUtility::getRecordWSOL('pages', $pageUid);

        return is_array($page) ? $this->bounded(trim((string)($page['title'] ?? '')), 200) : '';
    }

    /**
     * The title of a linked page, only when this user may see that page and it belongs to the same site (whose "Allow
     * page titles" switch was checked).
     */
    private function readablePageTitle(int $pageUid, string $siteIdentifier): string
    {
        $backendUser = $this->backendUserService->getBackendUser();
        if ($pageUid <= 0 || $backendUser === null || $siteIdentifier === ''
            || $this->siteResolutionService?->resolveSiteIdentifierForPageId($pageUid, '') !== $siteIdentifier) {
            return '';
        }
        $page = BackendUtility::readPageAccess($pageUid, $backendUser->getPagePermsClause(1));

        return is_array($page) ? $this->bounded(trim((string)($page['title'] ?? '')), 200) : '';
    }

    private function fileName(int $fileUid): string
    {
        if ($fileUid <= 0) {
            return '';
        }
        try {
            return $this->bounded($this->resourceFactory->getFileObject($fileUid)->getName(), 120);
        } catch (\Throwable) {
            return '';
        }
    }

    private function bounded(string $value, int $length): string
    {
        $value = trim((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value));
        $value = (string)preg_replace(self::EMAIL_PATTERN, '[e-mail address]', $value);

        return mb_substr($value, 0, $length);
    }
}
