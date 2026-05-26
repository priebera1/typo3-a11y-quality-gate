<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rule\Structured;

use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Domain\Repository\FileReferenceRepository;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleInterface;
use Priebera\A11yQualityGate\Rule\RuleViolation;
use Priebera\A11yQualityGate\Service\DictionaryRegistry;
use Priebera\A11yQualityGate\Service\PhraseMatcher;

final class FileReferenceAltQualityRule implements RuleInterface
{
    private const SUPPORTED_FILE_FIELDS = ['image', 'assets', 'media'];

    public function __construct(
        private readonly FileReferenceRepository $fileReferenceRepository,
        private readonly DictionaryRegistry $dictionaryRegistry,
        private readonly int $defaultMaxLength = 120,
    ) {
    }

    public function getRuleId(): string
    {
        return 'structured.file_reference_alt_quality';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::Warning;
    }

    public function getMessage(): string
    {
        return 'Image alternative text quality issue in media record.';
    }

    public function getHint(): string
    {
        return 'Improve the alternative text in the image reference. Keep it concise and avoid redundant phrases.';
    }

    public function supports(CheckContext $context): bool
    {
        $sourceField = strtolower(trim($context->sourceField));

        return $context->sourceTable !== ''
            && $context->sourceUid > 0
            && (
                in_array($sourceField, self::SUPPORTED_FILE_FIELDS, true)
                || str_contains($sourceField, 'image')
                || str_contains($sourceField, 'asset')
            );
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

        $phrases = $this->dictionaryRegistry->resolveForContext('rte.img_alt_redundant_phrase', $context);
        $violations = [];

        foreach ($references as $reference) {
            $alt = $this->resolveEffectiveAlt($reference);
            if ($alt === null || $alt === '') {
                continue;
            }

            $referenceUid = (int)($reference['uid'] ?? 0);
            $fileName = basename((string)($reference['identifier'] ?? 'unknown'));
            $contextPath = sprintf('%s:%d > %s > ref:%d', $context->sourceTable, $context->sourceUid, $context->sourceField, $referenceUid);
            $length = mb_strlen($alt);

            if ($length > $this->defaultMaxLength) {
                $violations[] = new RuleViolation(
                    ruleId: $this->getRuleId(),
                    severity: $this->getDefaultSeverity(),
                    message: sprintf('Image "%s" has alt text with %d characters. Recommended maximum is %d.', $fileName, $length, $this->defaultMaxLength),
                    hint: $this->getHint(),
                    contextSnippet: sprintf('sys_file_reference uid:%d, file: %s, alt: "%s"', $referenceUid, $fileName, mb_substr($alt, 0, 160)),
                    contextPath: $contextPath,
                );

                continue;
            }

            if ($phrases !== [] && PhraseMatcher::isPrefixMatch(PhraseMatcher::normalize($alt), $phrases)) {
                $violations[] = new RuleViolation(
                    ruleId: $this->getRuleId(),
                    severity: $this->getDefaultSeverity(),
                    message: sprintf('Image "%s" alt text starts with a redundant phrase.', $fileName),
                    hint: 'Remove redundant opening phrases such as "photo of" or "image of" from the alternative text.',
                    contextSnippet: sprintf('sys_file_reference uid:%d, file: %s, alt: "%s"', $referenceUid, $fileName, mb_substr($alt, 0, 160)),
                    contextPath: $contextPath,
                );
            }
        }

        return $violations;
    }

    private function resolveEffectiveAlt(array $reference): ?string
    {
        $rawReferenceAlt = $reference['alternative'] ?? null;
        if (is_string($rawReferenceAlt)) {
            return trim($rawReferenceAlt);
        }

        $rawMetadataAlt = $reference['metadata_alternative'] ?? null;
        if (is_string($rawMetadataAlt)) {
            $metadataAlt = trim($rawMetadataAlt);
            return $metadataAlt !== '' ? $metadataAlt : null;
        }

        return null;
    }
}
