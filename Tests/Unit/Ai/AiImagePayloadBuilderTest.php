<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Contract\AiProcessedImageMaterializerInterface;
use Priebera\A11yQualityGate\Ai\Exception\AiImagePayloadException;
use Priebera\A11yQualityGate\Ai\Service\AiImagePayloadBuilder;
use Priebera\A11yQualityGate\Remediation\ImageFindingContext;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\FileReference;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Extbase\Service\ImageService;

final class AiImagePayloadBuilderTest extends TestCase
{
    private const ONE_PIXEL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAABAAAAAQCAIAAACQkWg2AAAAGklEQVR42mP8//8/AymAiYFEMKphVMPQ0QAAVW0DHfeH1GIAAAAASUVORK5CYII=';
    private const ONE_PIXEL_WEBP = 'UklGRiQAAABXRUJQVlA4IBgAAAAwAQCdASoBAAEAAgA0JaQAA3AA/vuUAAA=';
    private const ONE_PIXEL_JPEG = '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAf/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABD/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAEDAQE/EB//xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oACAECAQE/EB//xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oACAEBAAE/EB//2Q==';

    #[Test]
    public function boundedOriginalImageIsUsedWithoutCreatingDerivative(): void
    {
        $resourceFactory = $this->createMock(ResourceFactory::class);
        $imageService = $this->createMock(ImageService::class);
        $fileReference = $this->createMock(FileReference::class);
        $file = $this->createMock(File::class);
        $contents = base64_decode(self::ONE_PIXEL_JPEG, true);
        self::assertIsString($contents);

        $resourceFactory->method('getFileReferenceObject')->with(2006)->willReturn($fileReference);
        $fileReference->method('getOriginalFile')->willReturn($file);
        $file->method('getMimeType')->willReturn('image/jpeg');
        $file->method('getSize')->willReturn(strlen($contents));
        $file->expects(self::once())->method('getContents')->willReturn($contents);
        $imageService->expects(self::never())->method('applyProcessingInstructions');

        $result = $this->builder($resourceFactory, $imageService)->build($this->context());

        self::assertSame('image/jpeg', $result->mimeType);
        self::assertSame('data:image/jpeg;base64,' . base64_encode($contents), $result->dataUrl);
    }


    #[DataProvider('additionalSupportedImageProvider')]
    #[Test]
    public function pngAndWebpOriginalsAreAccepted(string $mimeType, string $base64): void
    {
        $resourceFactory = $this->createMock(ResourceFactory::class);
        $imageService = $this->createMock(ImageService::class);
        $fileReference = $this->createMock(FileReference::class);
        $file = $this->createMock(File::class);
        $contents = base64_decode($base64, true);
        self::assertIsString($contents);

        $resourceFactory->method('getFileReferenceObject')->willReturn($fileReference);
        $fileReference->method('getOriginalFile')->willReturn($file);
        $file->method('getMimeType')->willReturn($mimeType);
        $file->method('getSize')->willReturn(strlen($contents));
        $file->method('getContents')->willReturn($contents);
        $imageService->expects(self::never())->method('applyProcessingInstructions');

        $result = $this->builder($resourceFactory, $imageService)->build($this->context());

        self::assertSame($mimeType, $result->mimeType);
        self::assertSame('data:' . $mimeType . ';base64,' . base64_encode($contents), $result->dataUrl);
    }

    public static function additionalSupportedImageProvider(): iterable
    {
        yield 'PNG' => ['image/png', self::ONE_PIXEL_PNG];
        yield 'WebP' => ['image/webp', self::ONE_PIXEL_WEBP];
    }

    #[Test]
    public function damagedOriginalAndDamagedDerivativeFailGracefully(): void
    {
        $resourceFactory = $this->createMock(ResourceFactory::class);
        $imageService = $this->createMock(ImageService::class);
        $fileReference = $this->createMock(FileReference::class);
        $file = $this->createMock(File::class);
        $processedFile = $this->createMock(ProcessedFile::class);
        $processedImageMaterializer = $this->createMock(AiProcessedImageMaterializerInterface::class);

        $resourceFactory->method('getFileReferenceObject')->willReturn($fileReference);
        $fileReference->method('getOriginalFile')->willReturn($file);
        $file->method('getMimeType')->willReturn('image/jpeg');
        $file->method('getSize')->willReturn(100);
        $file->method('getContents')->willReturn('damaged-image');
        $imageService->method('applyProcessingInstructions')->willReturn($processedFile);
        $processedFile->method('usesOriginalFile')->willReturn(false);
        $processedFile->method('isProcessed')->willReturn(true);
        $processedFile->method('exists')->willReturn(true);
        $processedFile->method('getContents')->willReturn('still-damaged');
        $processedFile->method('getUid')->willReturn(82);
        $processedFile->method('delete')->willReturn(true);
        $processedImageMaterializer->method('process')->with(82)->willReturn($processedFile);

        $this->expectException(AiImagePayloadException::class);
        $this->expectExceptionCode(1771002205);

        $this->builder($resourceFactory, $imageService, $processedImageMaterializer)->build($this->context());
    }


    #[Test]
    public function oversizedOriginalUsesValidatedDerivative(): void
    {
        $resourceFactory = $this->createMock(ResourceFactory::class);
        $imageService = $this->createMock(ImageService::class);
        $fileReference = $this->createMock(FileReference::class);
        $file = $this->createMock(File::class);
        $processedFile = $this->createMock(ProcessedFile::class);
        $processedContents = base64_decode(self::ONE_PIXEL_JPEG, true);
        self::assertIsString($processedContents);

        $resourceFactory->method('getFileReferenceObject')->willReturn($fileReference);
        $fileReference->method('getOriginalFile')->willReturn($file);
        $file->method('getMimeType')->willReturn('image/jpeg');
        $file->method('getSize')->willReturn(AiImagePayloadBuilder::MAX_PAYLOAD_BYTES + 1);
        $imageService->expects(self::once())
            ->method('applyProcessingInstructions')
            ->with($file, [
                'width' => AiImagePayloadBuilder::MAX_LONGEST_EDGE . 'm',
                'height' => AiImagePayloadBuilder::MAX_LONGEST_EDGE . 'm',
            ])
            ->willReturn($processedFile);
        $processedFile->method('usesOriginalFile')->willReturn(false);
        $processedFile->method('isProcessed')->willReturn(true);
        $processedFile->method('exists')->willReturn(true);
        $processedFile->method('getContents')->willReturn($processedContents);

        $result = $this->builder($resourceFactory, $imageService)->build($this->context());

        self::assertSame('image/jpeg', $result->mimeType);
        self::assertSame('data:image/jpeg;base64,' . base64_encode($processedContents), $result->dataUrl);
    }

    #[Test]
    public function processedImageIsReadThroughFalInsteadOfDirectFilesystemPath(): void
    {
        $resourceFactory = $this->createMock(ResourceFactory::class);
        $imageService = $this->createMock(ImageService::class);
        $fileReference = $this->createMock(FileReference::class);
        $file = $this->createMock(File::class);
        $processedFile = $this->createMock(ProcessedFile::class);
        $processedImageMaterializer = $this->createMock(AiProcessedImageMaterializerInterface::class);

        $resourceFactory->method('getFileReferenceObject')->with(2006)->willReturn($fileReference);
        $fileReference->method('getOriginalFile')->willReturn($file);
        $file->method('getMimeType')->willReturn('image/jpeg');
        $file->method('getSize')->willReturn(97_111);
        $file->method('getContents')->willReturn('not-a-valid-image');
        $imageService->method('applyProcessingInstructions')->willReturn($processedFile);
        $processedFile->method('usesOriginalFile')->willReturn(false);
        $processedFile->method('isProcessed')->willReturn(true);
        $processedFile->method('exists')->willReturn(true);
        $processedFile->method('getContents')
            ->willThrowException(new \RuntimeException('Simulated FAL read failure.'));
        $processedFile->method('getUid')->willReturn(82);
        $processedFile->method('delete')->willReturn(true);
        $processedFile->expects(self::never())->method('getForLocalProcessing');
        $processedImageMaterializer->method('process')
            ->with(82)
            ->willThrowException(new \RuntimeException('Simulated synchronous processing failure.'));

        $this->expectException(AiImagePayloadException::class);
        $this->expectExceptionCode(1771002203);

        $this->builder($resourceFactory, $imageService, $processedImageMaterializer)->build($this->context());
    }

    #[Test]
    public function noOpProcessedFileReadsOriginalThroughItsOwnStorage(): void
    {
        $resourceFactory = $this->createMock(ResourceFactory::class);
        $imageService = $this->createMock(ImageService::class);
        $fileReference = $this->createMock(FileReference::class);
        $file = $this->createMock(File::class);
        $processedFile = $this->createMock(ProcessedFile::class);
        $contents = base64_decode(self::ONE_PIXEL_JPEG, true);
        self::assertIsString($contents);

        $resourceFactory->method('getFileReferenceObject')->willReturn($fileReference);
        $fileReference->method('getOriginalFile')->willReturn($file);
        $file->method('getMimeType')->willReturn('image/jpeg');
        $file->method('getSize')->willReturn(AiImagePayloadBuilder::MAX_PAYLOAD_BYTES + 1);
        $file->expects(self::once())->method('getContents')->willReturn($contents);
        $imageService->method('applyProcessingInstructions')->willReturn($processedFile);
        $processedFile->method('usesOriginalFile')->willReturn(true);
        $processedFile->expects(self::never())->method('getContents');

        $result = $this->builder($resourceFactory, $imageService)->build($this->context());

        self::assertSame('data:image/jpeg;base64,' . base64_encode($contents), $result->dataUrl);
    }



    #[Test]
    public function missingDeferredProcessedResourceIsMaterializedInPlaceOnce(): void
    {
        $resourceFactory = $this->createMock(ResourceFactory::class);
        $imageService = $this->createMock(ImageService::class);
        $processedImageMaterializer = $this->createMock(AiProcessedImageMaterializerInterface::class);
        $fileReference = $this->createMock(FileReference::class);
        $file = $this->createMock(File::class);
        $deferredProcessedFile = $this->createMock(ProcessedFile::class);
        $materializedProcessedFile = $this->createMock(ProcessedFile::class);
        $processedContents = base64_decode(self::ONE_PIXEL_JPEG, true);
        self::assertIsString($processedContents);

        $resourceFactory->method('getFileReferenceObject')->willReturn($fileReference);
        $fileReference->method('getOriginalFile')->willReturn($file);
        $file->method('getMimeType')->willReturn('image/jpeg');
        $file->method('getSize')->willReturn(AiImagePayloadBuilder::MAX_PAYLOAD_BYTES + 1);

        $deferredProcessedFile->method('usesOriginalFile')->willReturn(false);
        $deferredProcessedFile->method('isProcessed')->willReturn(false);
        $deferredProcessedFile->method('exists')->willReturn(false);
        $deferredProcessedFile->method('getUid')->willReturn(82);
        $deferredProcessedFile->expects(self::never())->method('delete');

        $materializedProcessedFile->method('usesOriginalFile')->willReturn(false);
        $materializedProcessedFile->method('isProcessed')->willReturn(true);
        $materializedProcessedFile->method('exists')->willReturn(true);
        $materializedProcessedFile->method('getUid')->willReturn(82);
        $materializedProcessedFile->method('getContents')->willReturn($processedContents);

        $imageService->expects(self::once())
            ->method('applyProcessingInstructions')
            ->with($file, ['width' => '1600m', 'height' => '1600m'])
            ->willReturn($deferredProcessedFile);
        $processedImageMaterializer->expects(self::once())
            ->method('process')
            ->with(82)
            ->willReturn($materializedProcessedFile);

        $result = $this->builder(
            $resourceFactory,
            $imageService,
            $processedImageMaterializer,
        )->build($this->context());

        self::assertSame('image/jpeg', $result->mimeType);
    }

    #[Test]
    public function corruptExistingProcessedResourceIsInvalidatedBeforeSingleForcedAttempt(): void
    {
        $resourceFactory = $this->createMock(ResourceFactory::class);
        $imageService = $this->createMock(ImageService::class);
        $processedImageMaterializer = $this->createMock(AiProcessedImageMaterializerInterface::class);
        $fileReference = $this->createMock(FileReference::class);
        $file = $this->createMock(File::class);
        $corruptProcessedFile = $this->createMock(ProcessedFile::class);
        $replacementProcessedFile = $this->createMock(ProcessedFile::class);
        $recoveredProcessedFile = $this->createMock(ProcessedFile::class);
        $processedContents = base64_decode(self::ONE_PIXEL_JPEG, true);
        self::assertIsString($processedContents);

        $resourceFactory->method('getFileReferenceObject')->willReturn($fileReference);
        $fileReference->method('getOriginalFile')->willReturn($file);
        $file->method('getMimeType')->willReturn('image/jpeg');
        $file->method('getSize')->willReturn(AiImagePayloadBuilder::MAX_PAYLOAD_BYTES + 1);

        $corruptProcessedFile->method('usesOriginalFile')->willReturn(false);
        $corruptProcessedFile->method('isProcessed')->willReturn(true);
        $corruptProcessedFile->method('exists')->willReturn(true);
        $corruptProcessedFile->method('getUid')->willReturn(84);
        $corruptProcessedFile->method('getContents')->willReturn('damaged-processed-image');
        $corruptProcessedFile->expects(self::once())->method('delete')->with(true)->willReturn(true);

        $replacementProcessedFile->method('usesOriginalFile')->willReturn(false);
        $replacementProcessedFile->method('getUid')->willReturn(85);

        $recoveredProcessedFile->method('usesOriginalFile')->willReturn(false);
        $recoveredProcessedFile->method('isProcessed')->willReturn(true);
        $recoveredProcessedFile->method('exists')->willReturn(true);
        $recoveredProcessedFile->method('getUid')->willReturn(85);
        $recoveredProcessedFile->method('getContents')->willReturn($processedContents);

        $imageService->expects(self::exactly(2))
            ->method('applyProcessingInstructions')
            ->willReturnOnConsecutiveCalls($corruptProcessedFile, $replacementProcessedFile);
        $processedImageMaterializer->expects(self::once())
            ->method('process')
            ->with(85)
            ->willReturn($recoveredProcessedFile);

        $result = $this->builder(
            $resourceFactory,
            $imageService,
            $processedImageMaterializer,
        )->build($this->context());

        self::assertSame('image/jpeg', $result->mimeType);
    }

    #[Test]
    public function oversizedDerivativeFallsBackToTheNextSmallerEdge(): void
    {
        $resourceFactory = $this->createMock(ResourceFactory::class);
        $imageService = $this->createMock(ImageService::class);
        $fileReference = $this->createMock(FileReference::class);
        $file = $this->createMock(File::class);
        $processed1600 = $this->createMock(ProcessedFile::class);
        $processed1280 = $this->createMock(ProcessedFile::class);
        $validContents = base64_decode(self::ONE_PIXEL_JPEG, true);
        self::assertIsString($validContents);

        $resourceFactory->method('getFileReferenceObject')->willReturn($fileReference);
        $fileReference->method('getOriginalFile')->willReturn($file);
        $file->method('getMimeType')->willReturn('image/jpeg');
        $file->method('getSize')->willReturn(AiImagePayloadBuilder::MAX_PAYLOAD_BYTES + 1);

        foreach ([$processed1600, $processed1280] as $processedFile) {
            $processedFile->method('usesOriginalFile')->willReturn(false);
            $processedFile->method('isProcessed')->willReturn(true);
            $processedFile->method('exists')->willReturn(true);
        }
        $processed1600->method('getContents')->willReturn(str_repeat('x', AiImagePayloadBuilder::MAX_PAYLOAD_BYTES + 1));
        $processed1280->method('getContents')->willReturn($validContents);

        $imageService->expects(self::exactly(2))
            ->method('applyProcessingInstructions')
            ->willReturnCallback(
                static function (File $actualFile, array $instructions) use ($file, $processed1600, $processed1280): ProcessedFile {
                    self::assertSame($file, $actualFile);
                    return match ($instructions['width']) {
                        '1600m' => $processed1600,
                        '1280m' => $processed1280,
                        default => throw new \LogicException('Unexpected processing edge.'),
                    };
                },
            );

        $result = $this->builder($resourceFactory, $imageService)->build($this->context());

        self::assertSame('data:image/jpeg;base64,' . base64_encode($validContents), $result->dataUrl);
    }

    #[Test]
    public function adaptiveProcessingFallsBackThrough1280To1024(): void
    {
        $resourceFactory = $this->createMock(ResourceFactory::class);
        $imageService = $this->createMock(ImageService::class);
        $fileReference = $this->createMock(FileReference::class);
        $file = $this->createMock(File::class);
        $processed1600 = $this->createMock(ProcessedFile::class);
        $processed1280 = $this->createMock(ProcessedFile::class);
        $processed1024 = $this->createMock(ProcessedFile::class);
        $validContents = base64_decode(self::ONE_PIXEL_JPEG, true);
        self::assertIsString($validContents);

        $resourceFactory->method('getFileReferenceObject')->willReturn($fileReference);
        $fileReference->method('getOriginalFile')->willReturn($file);
        $file->method('getMimeType')->willReturn('image/jpeg');
        $file->method('getSize')->willReturn(AiImagePayloadBuilder::MAX_PAYLOAD_BYTES + 1);

        foreach ([$processed1600, $processed1280, $processed1024] as $processedFile) {
            $processedFile->method('usesOriginalFile')->willReturn(false);
            $processedFile->method('isProcessed')->willReturn(true);
            $processedFile->method('exists')->willReturn(true);
        }
        $processed1600->method('getContents')->willReturn(str_repeat('a', AiImagePayloadBuilder::MAX_PAYLOAD_BYTES + 1));
        $processed1280->method('getContents')->willReturn(str_repeat('b', AiImagePayloadBuilder::MAX_PAYLOAD_BYTES + 1));
        $processed1024->method('getContents')->willReturn($validContents);

        $imageService->expects(self::exactly(3))
            ->method('applyProcessingInstructions')
            ->willReturnCallback(
                static function (File $actualFile, array $instructions) use (
                    $file,
                    $processed1600,
                    $processed1280,
                    $processed1024,
                ): ProcessedFile {
                    self::assertSame($file, $actualFile);
                    return match ($instructions['width']) {
                        '1600m' => $processed1600,
                        '1280m' => $processed1280,
                        '1024m' => $processed1024,
                        default => throw new \LogicException('Unexpected processing edge.'),
                    };
                },
            );

        $result = $this->builder($resourceFactory, $imageService)->build($this->context());

        self::assertSame('data:image/jpeg;base64,' . base64_encode($validContents), $result->dataUrl);
    }

    #[Test]
    public function unsupportedMimeTypeFailsGracefully(): void
    {
        $resourceFactory = $this->createMock(ResourceFactory::class);
        $imageService = $this->createMock(ImageService::class);
        $fileReference = $this->createMock(FileReference::class);
        $file = $this->createMock(File::class);

        $resourceFactory->method('getFileReferenceObject')->willReturn($fileReference);
        $fileReference->method('getOriginalFile')->willReturn($file);
        $file->method('getMimeType')->willReturn('image/gif');

        $this->expectException(AiImagePayloadException::class);
        $this->expectExceptionCode(1771002201);

        $this->builder($resourceFactory, $imageService)->build($this->context());
    }

    #[Test]
    public function missingFileReferenceFailsGracefully(): void
    {
        $resourceFactory = $this->createMock(ResourceFactory::class);
        $imageService = $this->createMock(ImageService::class);
        $resourceFactory->method('getFileReferenceObject')
            ->willThrowException(new \RuntimeException('Missing reference'));

        $this->expectException(AiImagePayloadException::class);
        $this->expectExceptionCode(1771002200);

        $this->builder($resourceFactory, $imageService)->build($this->context());
    }

    private function builder(
        ResourceFactory $resourceFactory,
        ImageService $imageService,
        ?AiProcessedImageMaterializerInterface $processedImageMaterializer = null,
    ): AiImagePayloadBuilder {
        return new AiImagePayloadBuilder(
            $resourceFactory,
            $imageService,
            $processedImageMaterializer ?? $this->createMock(AiProcessedImageMaterializerInterface::class),
        );
    }

    private function context(): ImageFindingContext
    {
        return new ImageFindingContext(
            issue: [],
            fileReference: [],
            siteIdentifier: 'test',
            pageUid: 0,
            languageUid: 0,
            sourceTable: '__unit_test_table_without_tca',
            sourceUid: 1535,
            sourceField: 'image',
            fileReferenceUid: 2006,
            fileUid: 60,
            fingerprint: 'test-fingerprint',
            issueTimestamp: 0,
            fileReferenceTimestamp: 0,
        );
    }
}
