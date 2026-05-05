<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rule\Structured;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Domain\Repository\FileReferenceRepository;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleInterface;
use Priebera\A11yQualityGate\Rule\RuleViolation;

final class FileReferenceAltRule implements RuleInterface
{
    private const GROUPING_THRESHOLD = 5;
    private const GROUPED_CONTEXT_PREVIEW_LIMIT = 5;

    public function __construct(
        private readonly FileReferenceRepository $fileReferenceRepository,
    ) {
    }

    public function getRuleId(): string
    {
        return 'structured.file_reference_alt';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::Critical;
    }

    public function getMessage(): string
    {
        return 'Image file reference is missing alt text.';
    }

    public function getHint(): string
    {
        return 'Open the record and add a description in the "Alternative text" field for each image.';
    }

    public function supports(CheckContext $context): bool
    {
        return $context->sourceTable !== ''
            && $context->sourceField !== ''
            && $context->sourceUid > 0;
    }

    /**
     * @return RuleViolation[]
     */
    public function check(CheckContext $context): array
    {
        $references = $this->fileReferenceRepository->findVisibleImageReferencesWithMetadata(
            $context->sourceTable,
            $context->sourceUid,
            $context->sourceField,
        );

        if ($references === []) {
            return [];
        }

        $criticalItems = [];
        $warningItems = [];

        foreach ($references as $reference) {
            $referenceUid = (int)($reference['uid'] ?? 0);
            $fileName = basename((string)($reference['identifier'] ?? 'unknown'));
            $isDecorative = (bool)($reference['tx_a11y_is_decorative'] ?? false);

            $rawReferenceAlt = $reference['alternative'] ?? null;
            $rawReferenceTitle = $reference['title'] ?? null;
            $rawMetadataAlt = $reference['metadata_alternative'] ?? null;
            $rawMetadataTitle = $reference['metadata_title'] ?? null;

            $referenceAlt = is_string($rawReferenceAlt) ? trim($rawReferenceAlt) : null;
            $referenceTitle = is_string($rawReferenceTitle) ? trim($rawReferenceTitle) : null;
            $metadataAlt = is_string($rawMetadataAlt) ? trim($rawMetadataAlt) : null;
            $metadataTitle = is_string($rawMetadataTitle) ? trim($rawMetadataTitle) : null;

            $effectiveAlt = $this->resolveEffectiveAlt(
                $rawReferenceAlt,
                $referenceAlt,
                $metadataAlt,
            );

            $effectiveTitle = $this->resolveEffectiveTitle(
                $referenceTitle,
                $metadataTitle,
            );

            $contextPath = sprintf(
                '%s:%d > %s > ref:%d',
                $context->sourceTable,
                $context->sourceUid,
                $context->sourceField,
                $referenceUid,
            );

            if ($isDecorative) {
                if ($effectiveAlt !== null && $effectiveAlt !== '') {
                    $warningItems[] = [
                        'uid' => $referenceUid,
                        'file' => $fileName,
                        'value' => $effectiveAlt,
                        'contextPath' => $contextPath,
                        'reason' => 'decorative_with_alt',
                    ];
                }

                continue;
            }

            if ($effectiveAlt !== null && $effectiveAlt !== '') {
                continue;
            }

            if (is_string($rawReferenceAlt) && trim($rawReferenceAlt) === '') {
                continue;
            }

            if (($effectiveAlt === null || $effectiveAlt === '') && $effectiveTitle !== null && $effectiveTitle !== '') {
                $warningItems[] = [
                    'uid' => $referenceUid,
                    'file' => $fileName,
                    'value' => $effectiveTitle,
                    'contextPath' => $contextPath,
                    'reason' => 'title_only',
                ];

                continue;
            }

            $criticalItems[] = [
                'uid' => $referenceUid,
                'file' => $fileName,
                'contextPath' => $contextPath,
            ];
        }

        $totalItems = count($criticalItems) + count($warningItems);
        $fieldContextPath = sprintf(
            '%s:%d > %s',
            $context->sourceTable,
            $context->sourceUid,
            $context->sourceField,
        );

        if ($totalItems <= self::GROUPING_THRESHOLD) {
            return $this->buildIndividualViolations($criticalItems, $warningItems);
        }

        return $this->buildGroupedViolations(
            $criticalItems,
            $warningItems,
            $fieldContextPath,
        );
    }

    private function resolveEffectiveAlt(
        mixed $rawReferenceAlt,
        ?string $referenceAlt,
        ?string $metadataAlt,
    ): ?string {
        if (is_string($rawReferenceAlt)) {
            return $referenceAlt;
        }

        if ($metadataAlt !== null && $metadataAlt !== '') {
            return $metadataAlt;
        }

        return null;
    }

    private function resolveEffectiveTitle(
        ?string $referenceTitle,
        ?string $metadataTitle,
    ): ?string {
        if ($referenceTitle !== null && $referenceTitle !== '') {
            return $referenceTitle;
        }

        if ($metadataTitle !== null && $metadataTitle !== '') {
            return $metadataTitle;
        }

        return null;
    }

    /**
     * @param array<int, array{uid:int, file:string, contextPath:string}> $criticalItems
     * @param array<int, array{uid:int, file:string, value:string, contextPath:string, reason:string}> $warningItems
     * @return RuleViolation[]
     */
    private function buildIndividualViolations(array $criticalItems, array $warningItems): array
    {
        $violations = [];

        foreach ($criticalItems as $item) {
            $violations[] = new RuleViolation(
                ruleId: $this->getRuleId(),
                severity: Severity::Critical,
                message: sprintf(
                    'Image "%s" has no alt text (file reference uid:%d).',
                    $item['file'],
                    $item['uid'],
                ),
                hint: $this->getHint(),
                contextSnippet: sprintf(
                    'sys_file_reference uid:%d, file: %s',
                    $item['uid'],
                    $item['file'],
                ),
                contextPath: $item['contextPath'],
            );
        }

        foreach ($warningItems as $item) {
            $message = $item['reason'] === 'decorative_with_alt'
                ? sprintf(
                    'Decorative image "%s" should use an empty alt text (file reference uid:%d).',
                    $item['file'],
                    $item['uid'],
                )
                : sprintf(
                    'Image "%s" uses title text instead of alt text (file reference uid:%d).',
                    $item['file'],
                    $item['uid'],
                );

            $hint = $item['reason'] === 'decorative_with_alt'
                ? 'Decorative images should use an empty alt text. Remove the alt text or unmark the image as decorative.'
                : 'Provide explicit alt text in the "Alternative text" field instead of relying on the title field.';

            $contextSnippet = $item['reason'] === 'decorative_with_alt'
                ? sprintf(
                    'sys_file_reference uid:%d, file: %s, alt: "%s"',
                    $item['uid'],
                    $item['file'],
                    $item['value'],
                )
                : sprintf(
                    'sys_file_reference uid:%d, file: %s, effective title: "%s"',
                    $item['uid'],
                    $item['file'],
                    $item['value'],
                );

            $violations[] = new RuleViolation(
                ruleId: $this->getRuleId(),
                severity: Severity::Warning,
                message: $message,
                hint: $hint,
                contextSnippet: $contextSnippet,
                contextPath: $item['contextPath'],
            );
        }

        return $violations;
    }

    /**
     * @param array<int, array{uid:int, file:string, contextPath:string}> $criticalItems
     * @param array<int, array{uid:int, file:string, value:string, contextPath:string, reason:string}> $warningItems
     * @return RuleViolation[]
     */
    private function buildGroupedViolations(
        array $criticalItems,
        array $warningItems,
        string $fieldContextPath,
    ): array {
        $violations = [];

        if ($criticalItems !== []) {
            $violations[] = new RuleViolation(
                ruleId: $this->getRuleId(),
                severity: Severity::Critical,
                message: sprintf(
                    '%d image(s) are missing alt text.',
                    count($criticalItems),
                ),
                hint: $this->getHint(),
                contextSnippet: $this->buildContextSnippet($criticalItems),
                contextPath: $fieldContextPath,
            );
        }

        if ($warningItems !== []) {
            $titleOnlyCount = count(array_filter(
                $warningItems,
                static fn(array $item): bool => $item['reason'] === 'title_only',
            ));

            $decorativeWithAltCount = count(array_filter(
                $warningItems,
                static fn(array $item): bool => $item['reason'] === 'decorative_with_alt',
            ));

            $messageParts = [];

            if ($titleOnlyCount > 0) {
                $messageParts[] = sprintf(
                    '%d image(s) use title text instead of alt text',
                    $titleOnlyCount,
                );
            }

            if ($decorativeWithAltCount > 0) {
                $messageParts[] = sprintf(
                    '%d decorative image(s) use non-empty alt text',
                    $decorativeWithAltCount,
                );
            }

            $violations[] = new RuleViolation(
                ruleId: $this->getRuleId(),
                severity: Severity::Warning,
                message: implode('; ', $messageParts) . '.',
                hint: 'Provide explicit alt text for meaningful images. Decorative images should use an empty alt text.',
                contextSnippet: $this->buildContextSnippet($warningItems, true),
                contextPath: $fieldContextPath,
            );
        }

        return $violations;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function buildContextSnippet(array $items, bool $includeValue = false): string
    {
        $parts = [];

        foreach (array_slice($items, 0, self::GROUPED_CONTEXT_PREVIEW_LIMIT) as $item) {
            $part = sprintf(
                'ref:%d (%s)',
                (int)$item['uid'],
                (string)$item['file'],
            );

            if (
                $includeValue
                && isset($item['value'])
                && is_string($item['value'])
                && $item['value'] !== ''
            ) {
                $label = ($item['reason'] ?? '') === 'decorative_with_alt' ? 'alt' : 'title';
                $part .= sprintf(' %s="%s"', $label, $item['value']);
            }

            $parts[] = $part;
        }

        $remainingCount = count($items) - count($parts);

        if ($remainingCount > 0) {
            $parts[] = sprintf('+ %d more', $remainingCount);
        }

        return implode(' | ', $parts);
    }
}