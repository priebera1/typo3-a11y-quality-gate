<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Service;

use Priebera\A11yQualityGate\Ai\Dto\AiFindingMetadata;
use Priebera\A11yQualityGate\Remediation\ImageFindingContext;
use Priebera\A11yQualityGate\Remediation\ImageFindingContextResolver;
use Priebera\A11yQualityGate\Remediation\ImageRemediationValidationException;
use Priebera\A11yQualityGate\Remediation\StaleImageFindingException;

final class AiFindingMetadataResolver
{
    private const IMAGE_FILENAME_PATTERN = '#^(?:.*[\\\\/])?[^\\\\/\s]+\.(?:jpe?g|png|webp|gif|svg|bmp|tiff?)$#iuD';

    public function resolve(ImageFindingContext $context): AiFindingMetadata
    {
        $ruleId = trim((string)($context->issue['rule_id'] ?? ''));
        if (!in_array($ruleId, ImageFindingContextResolver::SUPPORTED_RULE_IDS, true)) {
            throw new ImageRemediationValidationException('unsupported_finding', 1771002501);
        }

        if ($ruleId === 'structured.file_reference_alt') {
            $isDecorative = (int)($context->fileReference['tx_a11y_is_decorative'] ?? 0) === 1;
            $currentAlt = $context->fileReference['alternative'] ?? null;
            if ($isDecorative
                || (is_string($currentAlt) && trim($currentAlt) !== '')) {
                throw new StaleImageFindingException('missing_alt_changed', 1771002504);
            }

            return new AiFindingMetadata('missing', null, null);
        }

        $rawCurrentAlt = $context->fileReference['alternative'] ?? null;
        if (!is_string($rawCurrentAlt) || trim($rawCurrentAlt) === '') {
            throw new StaleImageFindingException('quality_alt_missing', 1771002502);
        }

        $currentAlt = trim($rawCurrentAlt);
        $snapshot = $this->extractAltSnapshot((string)($context->issue['context_snippet'] ?? ''));
        if ($snapshot !== null && !$this->matchesSnapshot($currentAlt, $snapshot)) {
            throw new StaleImageFindingException('quality_alt_changed', 1771002503);
        }

        return new AiFindingMetadata(
            'quality',
            $currentAlt,
            $this->resolveQualityReason($context, $currentAlt),
        );
    }

    private function extractAltSnapshot(string $contextSnippet): ?string
    {
        $marker = 'alt: "';
        $position = strrpos($contextSnippet, $marker);
        if ($position === false) {
            return null;
        }

        $snapshot = substr($contextSnippet, $position + strlen($marker));
        if (str_ends_with($snapshot, '"')) {
            $snapshot = substr($snapshot, 0, -1);
        }

        $snapshot = trim($snapshot);

        return $snapshot !== '' ? $snapshot : null;
    }

    private function matchesSnapshot(string $currentAlt, string $snapshot): bool
    {
        if (mb_strlen($snapshot) >= 160) {
            return str_starts_with($currentAlt, $snapshot);
        }

        return hash_equals($snapshot, $currentAlt);
    }

    private function resolveQualityReason(ImageFindingContext $context, string $currentAlt): string
    {
        if ($this->isFilenameOrUrl($currentAlt)) {
            return 'img_alt_is_filename';
        }

        $diagnostic = mb_strtolower(trim(
            (string)($context->issue['message'] ?? '') . ' ' . (string)($context->issue['hint'] ?? '')
        ));

        if (str_contains($diagnostic, 'redundant phrase') || $this->startsWithRedundantPhrase($currentAlt)) {
            return 'img_alt_redundant_phrase';
        }

        if (str_contains($diagnostic, 'too long')
            || str_contains($diagnostic, 'characters')
            || str_contains($diagnostic, 'recommended maximum')
            || mb_strlen($currentAlt) > 120) {
            return 'img_alt_too_long';
        }

        return 'img_alt_quality_other';
    }

    private function isFilenameOrUrl(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_URL) !== false) {
            return true;
        }

        $match = preg_match(self::IMAGE_FILENAME_PATTERN, $value);
        if ($match === false) {
            throw new \LogicException('Unable to classify image alternative text against the filename pattern.', 1771002505);
        }

        return $match === 1;
    }

    private function startsWithRedundantPhrase(string $value): bool
    {
        return preg_match(
            '/^(?:an?\s+)?(?:image|photo|photograph|picture|graphic|illustration|screenshot|bild|foto|abbildung)\s+(?:of|showing|von|mit)\b/iu',
            trim($value),
        ) === 1;
    }
}
