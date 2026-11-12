<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Remediation\Workflow;

use Priebera\A11yQualityGate\Ai\Editor\EditorAiException;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiRemediationService;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiRuleCatalog;
use Priebera\A11yQualityGate\Ai\Editor\EditorFindingTarget;
use Priebera\A11yQualityGate\Remediation\ImageFindingContextResolver;
use Priebera\A11yQualityGate\Remediation\StaleImageFindingException;
use Priebera\A11yQualityGate\Remediation\ImageRemediationValidationException;

/**
 * Turns a stored local finding into a RemediationTarget, using the targeting rules Packages 2 and 3 already prove:
 * ImageFindingContextResolver for image uses (sys_file_reference, cross-checked against record, field, site and
 * language) and the Smart Editor's rule re-run for rich text and Plain HTML (same rule, path and snippet in the stored
 * field, one element by tag and occurrence). Anything else — rendered findings, page-level findings, rule-based fixes,
 * findings that no longer match the stored content — gets no writable target and a reason instead.
 */
final class RemediationTargetResolver
{
    public function __construct(
        private readonly ImageFindingContextResolver $imageResolver,
        private readonly EditorAiRemediationService $editorService,
    ) {}

    /** @param array<string,mixed> $issue a tx_a11y_issue row */
    public function resolve(array $issue): RemediationTarget
    {
        $ruleId = (string)($issue['rule_id'] ?? '');
        $sourceType = (string)($issue['source_type'] ?? '');

        if ($this->imageResolver->supportsIssueRow($issue)) {
            return $this->imageTarget((int)$issue['uid']);
        }
        if ($sourceType === 'rendered') {
            // Rendered HTML is template, plugin or TypoScript output: AQG cannot prove which record field produced it.
            return RemediationTarget::unavailable('rendered_output');
        }
        if ($sourceType !== 'rte' || !EditorAiRuleCatalog::supports($ruleId)) {
            return RemediationTarget::unavailable('no_safe_action');
        }
        if (!EditorAiRuleCatalog::hasAiFix($ruleId)) {
            return RemediationTarget::unavailable('rule_based_in_editor');
        }

        return $this->richTextTarget($issue);
    }

    private function imageTarget(int $findingUid): RemediationTarget
    {
        try {
            $context = $this->imageResolver->resolve($findingUid);
        } catch (StaleImageFindingException) {
            return RemediationTarget::unavailable('target_changed');
        } catch (ImageRemediationValidationException) {
            return RemediationTarget::unavailable('no_safe_action');
        }
        $alternative = (string)($context->fileReference['alternative'] ?? '');
        $decorative = (string)(int)($context->fileReference['tx_a11y_is_decorative'] ?? 0);

        return new RemediationTarget(
            kind: RemediationTargetKind::IMAGE_REFERENCE_ALT,
            table: 'sys_file_reference',
            uid: $context->fileReferenceUid,
            field: 'alternative',
            descriptor: [
                'parentTable' => $context->sourceTable,
                'parentUid' => $context->sourceUid,
                'fieldName' => $context->sourceField,
                'referenceUid' => $context->fileReferenceUid,
                'fileUid' => $context->fileUid,
            ],
            currentValue: $alternative,
            baseHash: self::imageBaseHash($alternative, $decorative, (string)$context->fileUid),
            aiKind: EditorAiRuleCatalog::KIND_IMAGE_ALT,
        );
    }

    /** @param array<string,mixed> $issue */
    private function richTextTarget(array $issue): RemediationTarget
    {
        try {
            $located = $this->editorService->locateStoredFinding(
                (string)$issue['source_table'],
                (int)$issue['source_uid'],
                (string)$issue['source_field'],
                (string)$issue['rule_id'],
                (string)($issue['context_path'] ?? ''),
                (string)($issue['context_snippet'] ?? ''),
            );
        } catch (EditorAiException $exception) {
            return RemediationTarget::unavailable(match ($exception->safeCode) {
                'permission_denied' => 'no_edit_access',
                'record_not_found' => 'target_missing',
                default => 'no_safe_action',
            });
        }
        if ($located === null) {
            return RemediationTarget::unavailable('target_changed');
        }
        $target = $located['target'];
        if (!$target->isActionable()) {
            return RemediationTarget::unavailable('manual_only');
        }

        return new RemediationTarget(
            kind: RemediationTargetKind::RICH_TEXT,
            table: $located['record']->table,
            uid: $located['record']->uid,
            field: $located['record']->field,
            descriptor: [
                'ruleId' => $target->ruleId,
                'contextPath' => $target->contextPath,
                'tag' => $target->tag,
                'occurrence' => $target->occurrence,
                'total' => $target->total,
                'operation' => $target->operation,
                'attribute' => $target->attribute,
                'cType' => $located['record']->cType,
                // The element's own identity for the editor: CKEditor normalizes content when it loads (it drops
                // empty links, for example), so positions counted in the stored HTML do not carry over to it.
                'attributes' => (object)$target->attributes,
                'text' => $target->text,
            ],
            currentValue: $target->currentValue,
            baseHash: self::richTextBaseHash($target),
            aiKind: $target->kind,
        );
    }

    public static function imageBaseHash(string $alternative, string $decorative, string $fileUid): string
    {
        return RemediationTarget::hashValue('image', $alternative, $decorative, $fileUid);
    }

    /**
     * Element-scoped: an edit elsewhere in the field keeps the change applicable, but another value, other attributes,
     * or a shifted position of the element (one of the same tag added or removed before it) is a conflict.
     */
    public static function richTextBaseHash(EditorFindingTarget $target): string
    {
        $attributes = $target->attributes;
        ksort($attributes);

        return RemediationTarget::hashValue(
            'rte',
            $target->ruleId,
            $target->tag,
            (string)$target->occurrence,
            (string)$target->total,
            json_encode($attributes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            $target->text,
            $target->currentValue,
        );
    }
}
