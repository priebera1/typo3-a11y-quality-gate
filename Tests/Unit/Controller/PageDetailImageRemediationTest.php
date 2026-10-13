<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Controller\PageDetailController;
use Priebera\A11yQualityGate\Remediation\ImageFindingContextResolver;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Editors with edit access but without the image remediation capability were offered "Apply reviewed text",
 * "Mark as decorative" and "Suggest alt text with AI"; every one of them answered 403 permission_denied.
 */
final class PageDetailImageRemediationTest extends TestCase
{
    private const IMAGE_FINDING = [
        'rule_id' => 'structured.file_reference_alt',
        'source_type' => 'structured',
        'context_path' => 'tt_content:12 > image > ref:34',
        'hasEditAccess' => true,
    ];

    #[Test]
    public function imageActionsNeedTheRemediationCapability(): void
    {
        self::assertTrue($this->offers(self::IMAGE_FINDING, true));
        self::assertFalse($this->offers(self::IMAGE_FINDING, false));
    }

    #[Test]
    public function imageActionsStillNeedEditAccessAndAnImageFinding(): void
    {
        self::assertFalse($this->offers(['hasEditAccess' => false] + self::IMAGE_FINDING, true));
        self::assertFalse($this->offers(['rule_id' => 'rte.img_alt_missing', 'source_type' => 'rte'] + self::IMAGE_FINDING, true));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function offers(array $row, bool $canRemediateImages): bool
    {
        $subject = (new ReflectionClass(PageDetailController::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(PageDetailController::class, 'imageFindingContextResolver'))->setValue(
            $subject,
            (new ReflectionClass(ImageFindingContextResolver::class))->newInstanceWithoutConstructor()
        );

        return (new ReflectionMethod(PageDetailController::class, 'offersImageRemediation'))->invoke($subject, $row, $canRemediateImages);
    }
}
