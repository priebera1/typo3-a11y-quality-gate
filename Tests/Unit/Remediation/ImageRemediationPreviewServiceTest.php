<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Remediation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Remediation\ImageFindingContext;
use Priebera\A11yQualityGate\Remediation\ImageRemediationPreviewService;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Extbase\Service\ImageService;

/**
 * U-04 (2026-11-05 company acceptance): the image card on a finding showed "File: typo3/image/process" (TYPO3 14 deferred
 * processing) or "fileadmin/_processed_/…/csm_…jpg" — the preview's URL, not the file the editor knows. The card names
 * the original file; its public path is only the tooltip.
 */
final class ImageRemediationPreviewServiceTest extends TestCase
{
    #[Test]
    public function theCardNamesTheOriginalFileNotThePreviewUrl(): void
    {
        foreach (['/typo3/image/process?token=abc&skipProcessing=0', '/fileadmin/_processed_/3/a/csm_cargo-bike_5e1c.jpg'] as $previewUri) {
            $preview = $this->service($previewUri)->build($this->context());

            self::assertTrue($preview['available']);
            self::assertSame($previewUri, $preview['url'], 'The preview image itself still uses the processed file.');
            self::assertSame('cargo-bike.jpg', $preview['fileName']);
            self::assertSame('fileadmin/products/cargo-bike.jpg', $preview['displayPath']);
        }
    }

    private function service(string $previewUri): ImageRemediationPreviewService
    {
        $file = $this->createMock(File::class);
        $file->method('getName')->willReturn('cargo-bike.jpg');
        $file->method('getMimeType')->willReturn('image/jpeg');
        $reference = $this->createMock(FileReference::class);
        $reference->method('getOriginalFile')->willReturn($file);
        $factory = $this->createMock(ResourceFactory::class);
        $factory->method('getFileReferenceObject')->willReturn($reference);
        $processed = $this->createMock(ProcessedFile::class);
        $images = $this->createMock(ImageService::class);
        $images->method('applyProcessingInstructions')->willReturn($processed);
        $images->method('getImageUri')->willReturnCallback(static fn (object $image): string => $image === $file ? '/fileadmin/products/cargo-bike.jpg' : $previewUri);

        return new ImageRemediationPreviewService($factory, $images);
    }

    private function context(): ImageFindingContext
    {
        return new ImageFindingContext([], [], 'northwind', 4, 0, 'tt_content', 12, 'image', 1, 7, 'fp', 0, 0);
    }
}
