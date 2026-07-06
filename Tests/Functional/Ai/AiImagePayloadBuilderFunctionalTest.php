<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Ai;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Ai\Contract\AiProcessedImageMaterializerInterface;
use Priebera\A11yQualityGate\Ai\Service\AiImagePayloadBuilder;
use Priebera\A11yQualityGate\Remediation\ImageFindingContext;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\FileProcessingAspect;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ProcessedFileRepository;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Service\ImageService;

final class AiImagePayloadBuilderFunctionalTest extends AbstractFunctionalTestCase
{
    private string $storageDirectory = '';
    private ResourceStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storageDirectory = Environment::getPublicPath() . '/fileadmin/aqg-ai-functional/';
        GeneralUtility::mkdir_deep($this->storageDirectory);

        $storageRepository = $this->get(StorageRepository::class);
        $storageUid = $storageRepository->createLocalStorage(
            'AQG AI functional storage',
            'fileadmin/aqg-ai-functional/',
            'relative',
        );
        $this->storage = $storageRepository->getStorageObject($storageUid);

        // Reproduce the backend request context that originally persisted a deferred
        // sys_file_processedfile row without materializing the underlying resource.
        $this->get(Context::class)->setAspect('fileProcessing', new FileProcessingAspect(true));
    }

    protected function tearDown(): void
    {
        $this->get(Context::class)->setAspect('fileProcessing', new FileProcessingAspect(false));
        if ($this->storageDirectory !== '' && is_dir($this->storageDirectory)) {
            GeneralUtility::rmdir($this->storageDirectory, true);
        }
        parent::tearDown();
    }

    #[Test]
    public function realLargeJpegCreatesReadableBoundedFalDerivative(): void
    {
        [$file, $referenceUid] = $this->importFixture('large-4000x3000.jpg');

        $payload = $this->get(AiImagePayloadBuilder::class)->build($this->context($file, $referenceUid));
        $contents = $this->decodeDataUrl($payload->dataUrl);
        $imageInfo = getimagesizefromstring($contents);

        self::assertIsArray($imageInfo);
        self::assertSame('image/jpeg', $imageInfo['mime']);
        self::assertLessThanOrEqual(AiImagePayloadBuilder::MAX_LONGEST_EDGE, max($imageInfo[0], $imageInfo[1]));
        self::assertLessThanOrEqual(AiImagePayloadBuilder::MAX_PAYLOAD_BYTES, strlen($contents));

        $processedFile = $this->findUsableProcessedFile($file);
        self::assertTrue($processedFile->exists());
        self::assertNotSame('', $processedFile->getContents());
    }

    #[Test]
    public function missingProcessedResourceIsRecoveredThroughBoundedFalRetry(): void
    {
        [$file, $referenceUid] = $this->importFixture('large-4000x3000.jpg');
        $this->get(AiImagePayloadBuilder::class)->build($this->context($file, $referenceUid));

        $processedFile = $this->findUsableProcessedFile($file);
        $staleProcessedUid = $processedFile->getUid();
        $staleIdentifier = $processedFile->getIdentifier();
        $processedResourcePath = $this->processedResourcePath($processedFile);
        self::assertFileExists($processedResourcePath);
        self::assertTrue(is_readable($processedResourcePath));

        $rowsBeforeRecovery = $this->countProcessedRows($file);
        self::assertSame(1, $rowsBeforeRecovery);

        // Functional-test-only setup: remove the actual resource from the known local test
        // storage while deliberately retaining the persisted processing task in the database.
        // Production code remains storage-neutral and never constructs local filesystem paths.
        self::assertTrue(unlink($processedResourcePath));

        $processedFileBeforeRecovery = $this->get(ProcessedFileRepository::class)
            ->findByUid($staleProcessedUid);
        $isProcessedBeforeRecovery = $processedFileBeforeRecovery->isProcessed();
        $existsBeforeRecovery = $processedFileBeforeRecovery->exists();
        $readableBeforeRecovery = is_readable($processedResourcePath);
        $byteSizeBeforeRecovery = is_file($processedResourcePath)
            ? filesize($processedResourcePath)
            : null;

        self::assertSame(1, $rowsBeforeRecovery);
        self::assertFalse($isProcessedBeforeRecovery);
        self::assertFalse($existsBeforeRecovery);
        self::assertFalse($readableBeforeRecovery);
        self::assertNull($byteSizeBeforeRecovery);

        $countingMaterializer = new CountingProcessedImageMaterializer(
            $this->get(AiProcessedImageMaterializerInterface::class),
        );
        self::assertSame(0, $countingMaterializer->calls);

        // Return the real persisted stale ProcessedFile deterministically. Calling the real
        // ImageService here may repair the missing resource before AiImagePayloadBuilder can
        // exercise its own bounded recovery branch, which would test TYPO3 Core rather than AQG.
        $imageService = $this->createMock(ImageService::class);
        $imageService->expects(self::once())
            ->method('applyProcessingInstructions')
            ->with($file, ['width' => '1600m', 'height' => '1600m'])
            ->willReturn($processedFileBeforeRecovery);

        $builder = new AiImagePayloadBuilder(
            $this->get(ResourceFactory::class),
            $imageService,
            $countingMaterializer,
        );

        $this->get(Context::class)->setAspect('fileProcessing', new FileProcessingAspect(true));
        $payload = $builder->build($this->context($file, $referenceUid));
        $contents = $this->decodeDataUrl($payload->dataUrl);
        $imageInfo = getimagesizefromstring($contents);
        $recoveredProcessedFile = $this->get(ProcessedFileRepository::class)
            ->findByUid($staleProcessedUid);
        $rowsAfterRecovery = $this->countProcessedRows($file);
        $resourceExistsAfterRecovery = is_file($processedResourcePath);
        $resourceReadableAfterRecovery = is_readable($processedResourcePath);
        $resourceByteSizeAfterRecovery = $resourceExistsAfterRecovery
            ? filesize($processedResourcePath)
            : null;

        $diagnosticMessage = json_encode([
            'staleProcessedUid' => $staleProcessedUid,
            'materializationInputUids' => $countingMaterializer->inputUids,
            'materializedOutputUids' => $countingMaterializer->outputUids,
            'processedRowsBeforeRecovery' => $rowsBeforeRecovery,
            'processedRowsAfterRecovery' => $rowsAfterRecovery,
            'identifierBeforeRecovery' => $staleIdentifier,
            'identifierAfterRecovery' => $recoveredProcessedFile->getIdentifier(),
            'isProcessedBeforeRecovery' => $isProcessedBeforeRecovery,
            'existsBeforeRecovery' => $existsBeforeRecovery,
            'readableBeforeRecovery' => $readableBeforeRecovery,
            'byteSizeBeforeRecovery' => $byteSizeBeforeRecovery,
            'materializerCallsBeforeRecovery' => 0,
            'recoveredProcessed' => $recoveredProcessedFile->isProcessed(),
            'recoveredExists' => $recoveredProcessedFile->exists(),
            'resourceExistsAfterRecovery' => $resourceExistsAfterRecovery,
            'resourceReadableAfterRecovery' => $resourceReadableAfterRecovery,
            'synchronousProcessCalls' => $countingMaterializer->calls,
            'materializedByteSize' => $resourceByteSizeAfterRecovery,
            'payloadByteSize' => strlen($contents),
            'detectedMime' => is_array($imageInfo) ? ($imageInfo['mime'] ?? '') : '',
            'detectedDimensions' => is_array($imageInfo) ? $imageInfo[0] . 'x' . $imageInfo[1] : '',
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        self::assertIsArray($imageInfo, $diagnosticMessage);
        self::assertSame('image/jpeg', $imageInfo['mime'], $diagnosticMessage);
        self::assertLessThanOrEqual(
            AiImagePayloadBuilder::MAX_LONGEST_EDGE,
            max($imageInfo[0], $imageInfo[1]),
            $diagnosticMessage,
        );
        self::assertLessThanOrEqual(AiImagePayloadBuilder::MAX_PAYLOAD_BYTES, strlen($contents), $diagnosticMessage);
        self::assertNotSame('', $contents, $diagnosticMessage);
        self::assertSame(1, $countingMaterializer->calls, $diagnosticMessage);
        self::assertSame([$staleProcessedUid], $countingMaterializer->inputUids, $diagnosticMessage);
        self::assertSame([$recoveredProcessedFile->getUid()], $countingMaterializer->outputUids, $diagnosticMessage);
        self::assertSame(1, $rowsAfterRecovery, $diagnosticMessage);
        self::assertSame($staleIdentifier, $recoveredProcessedFile->getIdentifier(), $diagnosticMessage);
        self::assertTrue($recoveredProcessedFile->isProcessed(), $diagnosticMessage);
        self::assertTrue($recoveredProcessedFile->exists(), $diagnosticMessage);
        self::assertTrue($resourceExistsAfterRecovery, $diagnosticMessage);
        self::assertTrue($resourceReadableAfterRecovery, $diagnosticMessage);
        self::assertIsInt($resourceByteSizeAfterRecovery, $diagnosticMessage);
        self::assertGreaterThan(0, $resourceByteSizeAfterRecovery, $diagnosticMessage);
        self::assertNotSame('', $recoveredProcessedFile->getContents(), $diagnosticMessage);

        foreach ($this->get(ProcessedFileRepository::class)->findAllByOriginalFile($file) as $candidate) {
            if (!in_array($candidate->getProcessingConfiguration()['width'] ?? null, ['1600m', '1280m', '1024m'], true)) {
                continue;
            }
            self::assertTrue($candidate->exists(), $diagnosticMessage);
            self::assertNotSame('', $candidate->getContents(), $diagnosticMessage);
        }
    }

    #[Test]
    public function materializedProcessedResourceIsReusedWithoutSynchronousProcessing(): void
    {
        [$file, $referenceUid] = $this->importFixture('large-4000x3000.jpg');
        $this->get(AiImagePayloadBuilder::class)->build($this->context($file, $referenceUid));

        $materializedProcessedFile = $this->findUsableProcessedFile($file);
        $processedResourcePath = $this->processedResourcePath($materializedProcessedFile);
        $rowsBefore = $this->countProcessedRows($file);
        $storedContents = $materializedProcessedFile->getContents();

        self::assertSame(1, $rowsBefore);
        self::assertTrue($materializedProcessedFile->isProcessed());
        self::assertTrue($materializedProcessedFile->exists());
        self::assertFileExists($processedResourcePath);
        self::assertTrue(is_readable($processedResourcePath));
        self::assertNotSame('', $storedContents);

        $countingMaterializer = new CountingProcessedImageMaterializer(
            $this->get(AiProcessedImageMaterializerInterface::class),
        );
        $imageService = $this->createMock(ImageService::class);
        $imageService->expects(self::once())
            ->method('applyProcessingInstructions')
            ->with($file, ['width' => '1600m', 'height' => '1600m'])
            ->willReturn($materializedProcessedFile);

        $builder = new AiImagePayloadBuilder(
            $this->get(ResourceFactory::class),
            $imageService,
            $countingMaterializer,
        );
        $payload = $builder->build($this->context($file, $referenceUid));
        $payloadContents = $this->decodeDataUrl($payload->dataUrl);
        $rowsAfter = $this->countProcessedRows($file);

        self::assertSame(0, $countingMaterializer->calls);
        self::assertSame([], $countingMaterializer->inputUids);
        self::assertSame([], $countingMaterializer->outputUids);
        self::assertSame($rowsBefore, $rowsAfter);
        self::assertSame($storedContents, $payloadContents);
        self::assertTrue($materializedProcessedFile->exists());
        self::assertNotSame('', $materializedProcessedFile->getContents());
    }

    #[Test]
    public function corruptProcessedResourceIsInvalidatedAndRegeneratedOnce(): void
    {
        [$file, $referenceUid] = $this->importFixture('large-4000x3000.jpg');
        $this->get(AiImagePayloadBuilder::class)->build($this->context($file, $referenceUid));

        $corruptProcessedFile = $this->findUsableProcessedFile($file);
        $corruptProcessedUid = $corruptProcessedFile->getUid();
        $corruptResourcePath = $this->processedResourcePath($corruptProcessedFile);
        $rowsBefore = $this->countProcessedRows($file);

        self::assertSame(1, $rowsBefore);
        self::assertGreaterThan(0, file_put_contents($corruptResourcePath, 'not-an-image'));

        $corruptProcessedFile = $this->get(ProcessedFileRepository::class)
            ->findByUid($corruptProcessedUid);
        self::assertTrue($corruptProcessedFile->exists());
        self::assertTrue($corruptProcessedFile->isProcessed());
        self::assertSame('not-an-image', $corruptProcessedFile->getContents());

        $countingMaterializer = new CountingProcessedImageMaterializer(
            $this->get(AiProcessedImageMaterializerInterface::class),
        );
        $this->get(Context::class)->setAspect('fileProcessing', new FileProcessingAspect(true));
        $builder = new AiImagePayloadBuilder(
            $this->get(ResourceFactory::class),
            $this->get(ImageService::class),
            $countingMaterializer,
        );

        $payload = $builder->build($this->context($file, $referenceUid));
        $contents = $this->decodeDataUrl($payload->dataUrl);
        $imageInfo = getimagesizefromstring($contents);
        $recoveredProcessedFile = $this->findUsableProcessedFile($file);
        $rowsAfter = $this->countProcessedRows($file);

        self::assertIsArray($imageInfo);
        self::assertSame('image/jpeg', $imageInfo['mime']);
        self::assertLessThanOrEqual(AiImagePayloadBuilder::MAX_LONGEST_EDGE, max($imageInfo[0], $imageInfo[1]));
        self::assertLessThanOrEqual(AiImagePayloadBuilder::MAX_PAYLOAD_BYTES, strlen($contents));
        self::assertSame(1, $countingMaterializer->calls);
        self::assertCount(1, $countingMaterializer->inputUids);
        self::assertCount(1, $countingMaterializer->outputUids);
        self::assertSame(1, $rowsAfter);
        self::assertTrue($recoveredProcessedFile->exists());
        self::assertTrue($recoveredProcessedFile->isProcessed());
        self::assertNotSame('not-an-image', $recoveredProcessedFile->getContents());
        self::assertNotSame('', $recoveredProcessedFile->getContents());
    }

    #[Test]
    public function adaptiveProcessingUsesFirstDerivativeBelowPayloadLimit(): void
    {
        [$file, $referenceUid] = $this->importFixture('adaptive-3000x2250.png');

        $payload = $this->get(AiImagePayloadBuilder::class)->build($this->context($file, $referenceUid));
        $contents = $this->decodeDataUrl($payload->dataUrl);
        $imageInfo = getimagesizefromstring($contents);

        self::assertIsArray($imageInfo);
        self::assertSame('image/png', $imageInfo['mime']);
        self::assertLessThanOrEqual(1024, max($imageInfo[0], $imageInfo[1]));
        self::assertLessThanOrEqual(AiImagePayloadBuilder::MAX_PAYLOAD_BYTES, strlen($contents));

        $processed1600 = $this->findProcessedFileByWidth($file, '1600m');
        $processed1280 = $this->findProcessedFileByWidth($file, '1280m');
        $processed1024 = $this->findProcessedFileByWidth($file, '1024m');

        self::assertGreaterThan(AiImagePayloadBuilder::MAX_PAYLOAD_BYTES, strlen($processed1600->getContents()));
        self::assertGreaterThan(AiImagePayloadBuilder::MAX_PAYLOAD_BYTES, strlen($processed1280->getContents()));
        self::assertLessThanOrEqual(AiImagePayloadBuilder::MAX_PAYLOAD_BYTES, strlen($processed1024->getContents()));
        self::assertSame($processed1024->getContents(), $contents);
    }

    #[Test]
    public function smallJpegUsesOriginalWithoutCreatingAiDerivative(): void
    {
        [$file, $referenceUid] = $this->importFixture('small-762x508.jpg');

        $payload = $this->get(AiImagePayloadBuilder::class)->build($this->context($file, $referenceUid));
        $contents = $this->decodeDataUrl($payload->dataUrl);
        $imageInfo = getimagesizefromstring($contents);

        self::assertIsArray($imageInfo);
        self::assertSame(762, $imageInfo[0]);
        self::assertSame(508, $imageInfo[1]);
        self::assertSame([], $this->get(ProcessedFileRepository::class)->findAllByOriginalFile($file));
    }

    /** @return array{File,int} */
    private function importFixture(string $fixtureName): array
    {
        $source = dirname(__DIR__, 2) . '/Fixtures/Images/' . $fixtureName;
        $target = $this->storageDirectory . $fixtureName;
        self::assertFileExists($source);
        self::assertTrue(copy($source, $target));

        $file = $this->storage->getFile('/' . $fixtureName);
        self::assertInstanceOf(File::class, $file);
        self::assertGreaterThan(0, $file->getUid());

        $connection = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file_reference');
        $connection->insert('sys_file_reference', [
            'pid' => 0,
            'uid_local' => $file->getUid(),
            'uid_foreign' => 1,
            'tablenames' => 'tt_content',
            'fieldname' => 'image',
            'sys_language_uid' => 0,
            'deleted' => 0,
            'hidden' => 0,
        ]);

        return [$file, (int)$connection->lastInsertId()];
    }

    private function context(File $file, int $referenceUid): ImageFindingContext
    {
        return new ImageFindingContext(
            issue: ['uid' => 9001],
            fileReference: ['uid' => $referenceUid, 'uid_local' => $file->getUid()],
            siteIdentifier: 'functional-test',
            pageUid: 0,
            languageUid: 0,
            sourceTable: 'tt_content',
            sourceUid: 1,
            sourceField: 'image',
            fileReferenceUid: $referenceUid,
            fileUid: $file->getUid(),
            fingerprint: 'functional-image-payload',
            issueTimestamp: 0,
            fileReferenceTimestamp: 0,
        );
    }

    private function findUsableProcessedFile(File $file): ProcessedFile
    {
        $processedFiles = $this->get(ProcessedFileRepository::class)->findAllByOriginalFile($file);
        foreach ($processedFiles as $processedFile) {
            $configuration = $processedFile->getProcessingConfiguration();
            if (!in_array($configuration['width'] ?? null, ['1600m', '1280m', '1024m'], true)) {
                continue;
            }
            if (!$processedFile->exists()) {
                continue;
            }
            try {
                if ($processedFile->getContents() !== '') {
                    return $processedFile;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        self::fail('No readable AQG AI derivative was persisted for the large fixture.');
    }


    private function findProcessedFileByWidth(File $file, string $width): ProcessedFile
    {
        foreach ($this->get(ProcessedFileRepository::class)->findAllByOriginalFile($file) as $processedFile) {
            if (($processedFile->getProcessingConfiguration()['width'] ?? null) !== $width) {
                continue;
            }
            if (!$processedFile->exists()) {
                continue;
            }
            return $processedFile;
        }

        self::fail(sprintf('No physical AQG AI derivative with width configuration %s was found.', $width));
    }

    private function processedResourcePath(ProcessedFile $processedFile): string
    {
        $identifier = ltrim($processedFile->getIdentifier(), '/');
        self::assertNotSame('', $identifier);

        $root = rtrim(str_replace('\\', '/', $this->storageDirectory), '/') . '/';
        $path = str_replace('\\', '/', $this->storageDirectory . $identifier);
        self::assertStringStartsWith($root, $path);

        return $path;
    }

    private function countProcessedRows(File $file): int
    {
        return (int)GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('sys_file_processedfile')
            ->count('uid', 'sys_file_processedfile', ['original' => $file->getUid()]);
    }

    private function decodeDataUrl(string $dataUrl): string
    {
        $separator = strpos($dataUrl, ',');
        self::assertNotFalse($separator);
        $decoded = base64_decode(substr($dataUrl, $separator + 1), true);
        self::assertIsString($decoded);
        return $decoded;
    }
}

final class CountingProcessedImageMaterializer implements AiProcessedImageMaterializerInterface
{
    public int $calls = 0;

    /** @var list<int> */
    public array $inputUids = [];

    /** @var list<int> */
    public array $outputUids = [];

    public function __construct(private readonly AiProcessedImageMaterializerInterface $inner) {}

    public function process(int $processedFileUid): ProcessedFile
    {
        ++$this->calls;
        $this->inputUids[] = $processedFileUid;
        $processedFile = $this->inner->process($processedFileUid);
        $this->outputUids[] = $processedFile->getUid();
        return $processedFile;
    }
}
