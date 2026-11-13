<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Editor;

use Priebera\A11yQualityGate\Ai\Provider\AiProviderCatalog;
use Priebera\A11yQualityGate\Ai\Service\AiModelCompatibilityRegistry;
use Priebera\A11yQualityGate\Ai\Service\AiPromptDefinition;
use Priebera\A11yQualityGate\Ai\Settings\AiSiteSettingsService;
use Priebera\A11yQualityGate\Ai\Task\AiImageInput;
use Priebera\A11yQualityGate\Ai\Task\AiStructuredRequest;
use Priebera\A11yQualityGate\Ai\Task\AiStructuredResponse;
use Priebera\A11yQualityGate\Ai\Task\AiStructuredTaskRunner;
use Priebera\A11yQualityGate\Ai\Task\AiTask;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleInterface;
use Priebera\A11yQualityGate\Rule\RuleRegistry;
use Priebera\A11yQualityGate\Rule\RuleViolation;
use Priebera\A11yQualityGate\Rule\Rte\LiveValidationHtml;
use Priebera\A11yQualityGate\Rule\Rte\RteDomLoader;
use Priebera\A11yQualityGate\Service\RuleConfigurationService;

/**
 * Editor AI for one live finding: Explain and Fix (a value for the finding's exact target), the rule-based check of a
 * proposed value, the verification of saved content, and the optional writing aids.
 *
 * The editor sends its current field content because unsaved content exists only there. AQG re-runs the finding's
 * own rule on that content, re-derives the exact element (EditorFindingTargetResolver) and builds the provider
 * context from it server-side. Nothing here writes content: a suggestion goes back to the editor, which applies it
 * after review and saves with TYPO3 Save.
 */
final class EditorAiRemediationService
{
    public const MAX_HTML_SIZE = 500_000;
    public const MAX_WRITING_INPUT = 1500;
    public const FIX_MAX_OUTPUT_TOKENS = 400;
    public const EXPLAIN_MAX_OUTPUT_TOKENS = 700;
    private const WRITING_MAX_OUTPUT_TOKENS = 1200;
    private const MAX_EXPLANATION_LENGTH = 1200;

    /** What editor AI never sends, listed under "What was sent?". */
    public const NOT_SENT = ['otherFields', 'userNames', 'drafts'];

    public function __construct(
        private readonly EditorAiRecordContextResolver $recordContextResolver,
        private readonly EditorAiAccessService $accessService,
        private readonly EditorFindingTargetResolver $targetResolver,
        private readonly EditorAiContextBuilder $contextBuilder,
        private readonly EditorAiValueValidator $valueValidator,
        private readonly EditorAiImageResolver $imageResolver,
        private readonly AiStructuredTaskRunner $taskRunner,
        private readonly AiSiteSettingsService $siteSettingsService,
        private readonly RuleRegistry $ruleRegistry,
        private readonly RuleConfigurationService $ruleConfigurationService,
        private readonly AiModelCompatibilityRegistry $modelRegistry = new AiModelCompatibilityRegistry(),
    ) {}

    /**
     * @param array{table:string,uid:int,field:string,html:string,ruleId:string,contextPath:string,occurrence:int} $input
     * @return array<string,mixed>
     */
    public function fix(array $input): array
    {
        [$record, $target, $violation, $html] = $this->prepare($input);
        if (!EditorAiRuleCatalog::hasAiFix($target->ruleId)) {
            throw new EditorAiException('rule_based', 'This finding has a rule-based fix without AI.', 422);
        }
        $settings = $this->siteSettingsService->get($record->siteIdentifier);
        $this->assertTextSuggestionsAllowed($record, $target);

        $image = null;
        if ($target->kind === EditorAiRuleCatalog::KIND_IMAGE_ALT) {
            if (!$settings->allowImageInput) {
                throw new EditorAiException('privacy_images', 'This site does not allow sending images to AI providers.', 422);
            }
            $image = $this->imageInput($target, $record);
        }

        $prepared = $this->contextBuilder->forFix($target, $record, $settings->allowPageTitle, $violation->message);
        $response = $this->taskRunner->run($record->siteIdentifier, $this->request(
            EditorAiRuleCatalog::task($target->kind),
            EditorAiPrompts::FIX_PROMPT_VERSION,
            EditorAiPrompts::fixInstructions($target->kind),
            $prepared['context'],
            EditorAiPrompts::fixOutputFormat(),
            self::FIX_MAX_OUTPUT_TOKENS,
            $prepared['includesPageTitle'],
            $image,
        ));

        $payload = $this->basePayload($response, EditorAiPrompts::FIX_PROMPT_VERSION, $prepared['sent']) + [
            'target' => $target->toArray(),
            'original' => $target->currentValue,
            'value' => '',
            'reason' => $this->plain((string)($response->data['reason'] ?? ''), 400),
            'warnings' => [],
            'resolvesFinding' => null,
        ];
        if (($response->data['status'] ?? '') !== EditorAiPrompts::STATUS_SUGGESTION) {
            return ['status' => EditorAiPrompts::STATUS_NEEDS_REVIEW] + $payload;
        }

        [$value, $rejection] = $this->valueValidator->validate($target->kind, (string)($response->data['value'] ?? ''), $target->currentValue);
        if ($rejection !== EditorAiValueValidator::VALID) {
            return ['status' => 'invalid_response', 'rejection' => $rejection, 'reason' => ''] + $payload;
        }

        $resolves = $this->resolvesFinding($html, $record, $target, $value);
        $payload['value'] = $value;
        $payload['resolvesFinding'] = $resolves;
        if ($resolves === false) {
            $payload['warnings'][] = 'still_reported';
        }

        return ['status' => EditorAiPrompts::STATUS_SUGGESTION] + $payload;
    }

    /**
     * @param array{table:string,uid:int,field:string,html:string,ruleId:string,contextPath:string,occurrence:int} $input
     * @return array<string,mixed>
     */
    public function explain(array $input): array
    {
        [$record, $target, $violation] = $this->prepare($input, false);
        $this->assertTextSuggestionsAllowed($record, $target);
        $settings = $this->siteSettingsService->get($record->siteIdentifier);
        $prepared = $this->contextBuilder->forExplain($target, $record, $settings->allowPageTitle, $violation->message, $violation->hint);
        $response = $this->taskRunner->run($record->siteIdentifier, $this->request(
            AiTask::EXPLAIN,
            EditorAiPrompts::EXPLAIN_PROMPT_VERSION,
            EditorAiPrompts::explainInstructions(),
            $prepared['context'],
            EditorAiPrompts::explainOutputFormat(),
            self::EXPLAIN_MAX_OUTPUT_TOKENS,
            $prepared['includesPageTitle'],
        ));

        $explanation = $this->plain((string)($response->data['explanation'] ?? ''), self::MAX_EXPLANATION_LENGTH);
        $howToFix = $this->plain((string)($response->data['how_to_fix'] ?? ''), 400);
        $status = ($response->data['status'] ?? '') === EditorAiPrompts::STATUS_EXPLANATION ? EditorAiPrompts::STATUS_EXPLANATION : EditorAiPrompts::STATUS_NEEDS_REVIEW;
        if ($explanation === '') {
            $status = 'invalid_response';
        }

        return ['status' => $status, 'explanation' => $explanation, 'howToFix' => $howToFix, 'target' => $target->toArray()]
            + $this->basePayload($response, EditorAiPrompts::EXPLAIN_PROMPT_VERSION, $prepared['sent']);
    }

    /**
     * Writing aids on the editor's selection (no finding): clarity, plain language, or a translation draft inside a
     * translated record. Gated by options.a11y_quality_gate.ai.writing.
     *
     * @return array<string,mixed>
     */
    public function write(string $table, int $uid, string $field, string $mode, string $text): array
    {
        $record = $this->recordContextResolver->resolve($table, $uid, $field);
        $this->accessService->assertReady($record->siteIdentifier, EditorAiAccessService::FLAG_WRITING);
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));
        if ($text === '' || mb_strlen($text) > self::MAX_WRITING_INPUT || preg_match('/[<>]/', $text) === 1) {
            throw new EditorAiException('invalid_input', 'Select plain text of up to 1500 characters.', 422);
        }
        if (!in_array($mode, ['clarity', 'plain_language', 'translation'], true)) {
            throw new EditorAiException('invalid_input', 'Unknown writing mode.', 422);
        }
        if ($mode === 'translation' && !$record->isTranslation) {
            throw new EditorAiException('not_translation', 'Translation drafts are only available inside a translated record.', 422);
        }

        $translation = $mode === 'translation';
        $context = ['mode' => $mode, 'target_locale' => $record->targetLocale, 'text' => $text];
        if ($translation) {
            $context['source_locale'] = $record->defaultLocale;
        }
        $response = $this->taskRunner->run($record->siteIdentifier, $this->request(
            $translation ? AiTask::TRANSLATION : AiTask::WRITING,
            $translation ? EditorAiPrompts::TRANSLATION_PROMPT_VERSION : EditorAiPrompts::WRITING_PROMPT_VERSION,
            $translation ? EditorAiPrompts::translationInstructions() : EditorAiPrompts::writingInstructions(),
            $context,
            EditorAiPrompts::writingOutputFormat(),
            self::WRITING_MAX_OUTPUT_TOKENS,
            false,
        ));

        $payload = $this->basePayload($response, $translation ? EditorAiPrompts::TRANSLATION_PROMPT_VERSION : EditorAiPrompts::WRITING_PROMPT_VERSION, ['selectedText'])
            + ['mode' => $mode, 'original' => $text, 'targetLanguage' => $record->languageTitle, 'notes' => $this->plain((string)($response->data['notes'] ?? ''), 400), 'text' => ''];
        if (($response->data['status'] ?? '') !== EditorAiPrompts::STATUS_SUGGESTION) {
            return ['status' => EditorAiPrompts::STATUS_NEEDS_REVIEW] + $payload;
        }
        [$value, $rejection] = $this->valueValidator->validateText((string)($response->data['text'] ?? ''), self::MAX_WRITING_INPUT * 2);
        if ($rejection !== EditorAiValueValidator::VALID || $value === $text) {
            return ['status' => $rejection === EditorAiValueValidator::VALID ? EditorAiPrompts::STATUS_NEEDS_REVIEW : 'invalid_response'] + $payload;
        }

        return ['status' => EditorAiPrompts::STATUS_SUGGESTION, 'text' => $value] + $payload;
    }

    /**
     * Checks the saved record per applied change: was the record saved after the change was suggested (the server time
     * the editor received with the suggestion or live check, so no client clock is involved), does it contain the
     * applied element, and does the finding's rule still report that element? Only "verified" may be shown as
     * Verified.
     *
     * @param list<array{ruleId:string,tag:string,attributes:array<string,string>,text:string,suggestedAt:int,occurrence?:int,total?:int}> $items
     * @return array{saved:bool,results:list<string>}
     */
    public function verify(string $table, int $uid, string $field, array $items): array
    {
        $record = $this->recordContextResolver->resolve($table, $uid, $field);
        $stored = $this->recordContextResolver->storedValue($record);
        $html = LiveValidationHtml::normalize($stored['html'])['html'];

        $results = [];
        foreach (array_slice($items, 0, 20) as $item) {
            $suggestedAt = (int)($item['suggestedAt'] ?? 0);
            $results[] = $suggestedAt <= 0 || $stored['tstamp'] < $suggestedAt
                ? 'not_saved'
                : $this->verifyItem($html, $record, $item);
        }

        return ['saved' => in_array(true, array_map(static fn (string $result): bool => $result !== 'not_saved', $results), true), 'results' => $results];
    }

    /** @param array{ruleId:string,tag:string,attributes:array<string,string>,text:string} $item */
    private function verifyItem(string $html, EditorAiRecordContext $record, array $item): string
    {
        $ruleId = (string)($item['ruleId'] ?? '');
        $tag = strtolower((string)($item['tag'] ?? ''));
        if (!EditorAiRuleCatalog::supports($ruleId) || preg_match('/^[a-z][a-z0-9]{0,10}$/', $tag) !== 1) {
            return 'not_verified';
        }
        $expectedAttributes = is_array($item['attributes'] ?? null) ? array_map('strval', $item['attributes']) : [];

        $elements = RteDomLoader::load($html)->getElementsByTagName($tag);
        $occurrence = (int)($item['occurrence'] ?? -1);
        $total = (int)($item['total'] ?? -1);
        // The applied element by its position: an identical duplicate elsewhere must not stand in for it.
        if ($occurrence >= 0 && $total !== $elements->length) {
            return 'not_found';
        }
        $matches = $this->matchingElements($elements, $expectedAttributes, (string)($item['text'] ?? ''), $occurrence);
        // Without a position, the applied element is proven by its count: the field must hold more elements like it
        // than it held before the change. An identical element that was already there never stands in for it.
        $before = (int)($item['before'] ?? -1);
        if ($matches === [] || ($before >= 0 && count($matches) <= $before)) {
            return 'not_found';
        }

        $rule = $this->rule($ruleId);
        if ($rule === null) {
            return 'not_verified';
        }
        foreach ($rule->check($this->checkContext($record, $html)) as $violation) {
            $reported = $this->targetResolver->resolve($html, $violation);
            if ($reported !== null && $reported->tag === $tag && in_array($reported->occurrence, $matches, true)) {
                return 'still_reported';
            }
        }

        return 'verified';
    }

    /**
     * @param array{table:string,uid:int,field:string,html:string,ruleId:string,contextPath:string,occurrence:int} $input
     * @return array{0:EditorAiRecordContext,1:EditorFindingTarget,2:RuleViolation,3:string}
     */
    private function prepare(array $input, bool $requireActionable = true): array
    {
        $record = $this->recordContextResolver->resolve((string)$input['table'], (int)$input['uid'], (string)$input['field']);
        $this->accessService->assertReady($record->siteIdentifier);

        $rawHtml = (string)$input['html'];
        if (strlen($rawHtml) > self::MAX_HTML_SIZE) {
            throw new EditorAiException('html_too_large', 'The field is too large for the live check.', 413);
        }
        $normalized = LiveValidationHtml::normalize($rawHtml);
        if ($normalized['decoded'] || trim($normalized['html']) === '') {
            throw new EditorAiException('source_ambiguous', 'The field content cannot be targeted exactly.', 422);
        }
        $html = $normalized['html'];

        $ruleId = (string)$input['ruleId'];
        if (!EditorAiRuleCatalog::supports($ruleId) || !$this->ruleConfigurationService->isRuleEnabledForSite($record->siteIdentifier, $ruleId)) {
            throw new EditorAiException('unsupported_rule', 'No AI action exists for this finding.', 422);
        }
        $rule = $this->rule($ruleId);
        if ($rule === null) {
            throw new EditorAiException('unsupported_rule', 'No AI action exists for this finding.', 422);
        }

        $target = null;
        $match = null;
        foreach ($rule->check($this->checkContext($record, $html)) as $violation) {
            if ($violation->contextPath !== (string)$input['contextPath']) {
                continue;
            }
            $candidate = $this->targetResolver->resolve($html, $violation);
            if ($candidate !== null && $candidate->occurrence === (int)$input['occurrence']) {
                $target = $candidate;
                $match = $violation;
                break;
            }
        }
        if ($target === null || $match === null) {
            // The finding is gone or points to another element now: the editor content changed meanwhile.
            throw new EditorAiException('stale', 'The finding no longer matches the current content.', 409);
        }
        if ($requireActionable && !$target->isActionable()) {
            throw new EditorAiException('manual_only', $target->unavailableReason, 422);
        }

        return [$record, $target, $match, $html];
    }

    /** The site's switch "AI link and iframe title suggestions" covers fixes and explanations of both in editors. */
    private function assertTextSuggestionsAllowed(EditorAiRecordContext $record, EditorFindingTarget $target): void
    {
        if (in_array($target->kind, [EditorAiRuleCatalog::KIND_LINK_TEXT, EditorAiRuleCatalog::KIND_IFRAME_TITLE], true)
            && !(bool)($this->accessService->editorConfiguration($record->siteIdentifier)['linkText'] ?? false)) {
            throw new EditorAiException('link_text_disabled', 'AI link and iframe title suggestions are disabled for this site.', 422);
        }
    }

    /**
     * A stored content-scan finding in the record as TYPO3 shows it to this user (workspace overlay included): the
     * same rule, structural path and snippet in the stored field. The remediation queue prepares and checks changes
     * without an open editor; a finding that no longer matches the stored content resolves to null (stale), never to
     * a nearby element.
     *
     * @return array{record:EditorAiRecordContext,target:EditorFindingTarget,html:string,tstamp:int}|null
     */
    public function locateStoredFinding(string $table, int $uid, string $field, string $ruleId, string $contextPath, string $snippet): ?array
    {
        $record = $this->recordContextResolver->resolve($table, $uid, $field);
        $stored = $this->recordContextResolver->storedValue($record);
        $normalized = LiveValidationHtml::normalize($stored['html']);
        $rule = $this->rule($ruleId);
        if ($rule === null || !EditorAiRuleCatalog::supports($ruleId) || $normalized['decoded'] || trim($normalized['html']) === '') {
            return null;
        }
        $html = $normalized['html'];
        foreach ($rule->check($this->checkContext($record, $html)) as $violation) {
            if ($violation->contextPath !== $contextPath || ($snippet !== '' && $violation->contextSnippet !== $snippet)) {
                continue;
            }
            $target = $this->targetResolver->resolve($html, $violation);
            if ($target !== null) {
                return ['record' => $record, 'target' => $target, 'html' => $html, 'tstamp' => $stored['tstamp']];
            }
        }

        return null;
    }

    /**
     * The element as it will look after the change, in the shape verify() expects: the remediation queue stores it at
     * approval and later proves the save by finding exactly this element in the stored record. 'before' counts the
     * elements like it that the field holds already, so that one of them never proves the change.
     *
     * @return array{ruleId:string,tag:string,attributes:array<string,string>,text:string,occurrence:int,total:int,before:int}|null
     */
    public function expectedSignature(string $html, EditorFindingTarget $target, string $value): ?array
    {
        $changed = $this->changedHtml($html, $target, $value);
        if ($changed === null) {
            return null;
        }
        $dom = RteDomLoader::load($changed);
        $elements = $dom->getElementsByTagName($target->tag);
        $element = $elements->item($target->occurrence);
        if (!$element instanceof \DOMElement) {
            return null;
        }
        $attributes = [];
        foreach ($element->attributes ?? [] as $attribute) {
            $attributes[strtolower($attribute->name)] = (string)$attribute->value;
        }
        ksort($attributes);
        $text = EditorFindingTargetResolver::normalizeText($element->textContent);

        return [
            'ruleId' => $target->ruleId,
            'tag' => $target->tag,
            'attributes' => $attributes,
            'text' => $text,
            'occurrence' => $target->occurrence,
            'total' => $elements->length,
            'before' => count($this->matchingElements(RteDomLoader::load($html)->getElementsByTagName($target->tag), $attributes, $text, -1)),
        ];
    }

    /**
     * Indexes of the elements with exactly these attributes and this text (only the one at $occurrence when >= 0).
     *
     * @param \DOMNodeList<\DOMElement> $elements
     * @param array<string,string> $expectedAttributes
     * @return list<int>
     */
    private function matchingElements(\DOMNodeList $elements, array $expectedAttributes, string $expectedText, int $occurrence): array
    {
        ksort($expectedAttributes);
        $expectedText = EditorFindingTargetResolver::normalizeText($expectedText);
        $matches = [];
        foreach ($elements as $index => $element) {
            if (!$element instanceof \DOMElement || ($occurrence >= 0 && (int)$index !== $occurrence)) {
                continue;
            }
            $attributes = [];
            foreach ($element->attributes ?? [] as $attribute) {
                $attributes[strtolower($attribute->name)] = (string)$attribute->value;
            }
            ksort($attributes);
            if ($attributes === $expectedAttributes && EditorFindingTargetResolver::normalizeText($element->textContent) === $expectedText) {
                $matches[] = (int)$index;
            }
        }

        return $matches;
    }

    /** The field HTML with the value applied to the target element, or null when the element cannot be found. */
    private function changedHtml(string $html, EditorFindingTarget $target, string $value): ?string
    {
        $dom = RteDomLoader::load($html);
        $element = $dom->getElementsByTagName($target->tag)->item($target->occurrence);
        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$element instanceof \DOMElement || !$body instanceof \DOMElement) {
            return null;
        }
        if ($target->operation === EditorAiRuleCatalog::OPERATION_ATTRIBUTE) {
            $element->setAttribute($target->attribute, $value);
        } else {
            while ($element->firstChild !== null) {
                $element->removeChild($element->firstChild);
            }
            $element->appendChild($dom->createTextNode($value));
        }
        $changed = '';
        foreach ($body->childNodes as $child) {
            $changed .= (string)$dom->saveHTML($child);
        }

        return $changed;
    }

    /** Applies the value to a fresh parse and runs the rule again: does it still report this element? */
    private function resolvesFinding(string $html, EditorAiRecordContext $record, EditorFindingTarget $target, string $value): ?bool
    {
        $rule = $this->rule($target->ruleId);
        if ($rule === null) {
            return null;
        }
        $changed = $this->changedHtml($html, $target, $value);
        if ($changed === null) {
            return null;
        }

        foreach ($rule->check($this->checkContext($record, $changed)) as $violation) {
            $reported = $this->targetResolver->resolve($changed, $violation);
            if ($reported !== null && $reported->tag === $target->tag && $reported->occurrence === $target->occurrence) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the image of a located image-alt finding can be sent, checked before anything is queued or asked:
     * EditorAiImageResolver::READABLE ('') or the reason it cannot (missing file, external URL, no read access, …).
     * No image is processed and no provider request is made. Other finding kinds answer READABLE.
     */
    public function imageAvailability(EditorAiRecordContext $record, EditorFindingTarget $target): string
    {
        if ($target->kind !== EditorAiRuleCatalog::KIND_IMAGE_ALT) {
            return EditorAiImageResolver::READABLE;
        }

        return $this->imageResolver->availability($target->attributes, $record, $this->recordContextResolver->storedValue($record)['html']);
    }

    private function imageInput(EditorFindingTarget $target, EditorAiRecordContext $record): AiImageInput
    {
        $dataUrl = $this->imageResolver->imageDataUrl($target->attributes, $record, $this->recordContextResolver->storedValue($record)['html']);
        if ($dataUrl === null) {
            throw new EditorAiException('image_unavailable', 'AQG could not read this image from a TYPO3 file storage.', 422);
        }
        try {
            return AiImageInput::fromDataUrl($dataUrl);
        } catch (\InvalidArgumentException $exception) {
            throw new EditorAiException('image_unavailable', 'The image could not be prepared for AI.', 422, 0, $exception);
        }
    }

    /**
     * Site content goes into the untrusted context JSON only. $includesPageTitle is set by the caller from what it
     * actually put into $context (page or link-target titles), so the runner can enforce the site's switch.
     *
     * @param array<string,mixed> $context
     * @param array<string,mixed> $format
     */
    private function request(string $task, string $promptVersion, string $instructions, array $context, array $format, int $maxOutputTokens, bool $includesPageTitle, ?AiImageInput $image = null): AiStructuredRequest
    {
        return new AiStructuredRequest(
            task: $task,
            promptVersion: $promptVersion,
            instructions: $instructions,
            inputText: AiPromptDefinition::CONTEXT_WRAPPER_PREFIX . "\n" . json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            schemaName: (string)$format['name'],
            outputSchema: $format['schema'],
            maxOutputTokens: $maxOutputTokens,
            image: $image,
            includesPageTitle: $includesPageTitle || array_key_exists('page_title', $context),
        );
    }

    /**
     * @param list<string> $sent
     * @return array<string,mixed>
     */
    private function basePayload(AiStructuredResponse $response, string $promptVersion, array $sent): array
    {
        return [
            'provider' => $response->provider,
            'providerLabel' => AiProviderCatalog::label($response->provider),
            'model' => $response->model,
            // The display name AI settings show ("Claude Haiku 5.5"), never only the technical model id.
            'modelLabel' => (string)($this->modelRegistry->find($response->model, $response->provider)['label'] ?? $response->model),
            'promptVersion' => $promptVersion,
            'usage' => [
                'inputTokens' => $response->usage->inputTokens,
                'outputTokens' => $response->usage->outputTokens,
                'reported' => $response->usage->reported,
            ],
            'sent' => $sent,
            'notSent' => self::NOT_SENT,
            // Server time of the suggestion: Verify compares the saved record's tstamp with it (no client clock).
            'serverTime' => time(),
        ];
    }

    private function rule(string $ruleId): ?RuleInterface
    {
        return $this->ruleRegistry->getById($ruleId);
    }

    private function checkContext(EditorAiRecordContext $record, string $html): CheckContext
    {
        return new CheckContext(
            siteIdentifier: $record->siteIdentifier,
            pageUid: $record->pageUid,
            sourceLangUid: max(0, $record->languageUid),
            sourceTable: $record->table,
            sourceUid: $record->uid,
            sourceField: $record->field,
            content: $html,
            cType: $record->cType,
            contextPath: sprintf('Page:%d > %s:%d > %s', $record->pageUid, $record->table, $record->uid, $record->field),
        );
    }

    /** Model text shown to the editor: plain text, bounded, never markup. */
    private function plain(string $value, int $maxLength): string
    {
        $value = trim((string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', strip_tags($value)));

        return mb_substr($value, 0, $maxLength);
    }
}
