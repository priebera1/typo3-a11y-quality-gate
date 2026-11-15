<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Ai;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Ai\Contract\AiProcessedImageMaterializerInterface;
use Priebera\A11yQualityGate\Ai\Exception\AiImagePayloadException;
use Priebera\A11yQualityGate\Ai\Service\AiImagePayloadBuilder;
use Priebera\A11yQualityGate\Remediation\ImageFindingContext;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\FileProcessingAspect;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Service\ImageService;

/**
 * "Suggest alt text with AI" runs in a backend AJAX request. There TYPO3 registers
 * DeferredBackendImageProcessor, which answers every CropScaleMask task with a persisted
 * but not yet materialized processed file (a processing URL, no resource). These tests run
 * the real builder in exactly that request context, with real FAL processing.
 */
final class AiImagePayloadBuilderBackendRequestFunctionalTest extends AbstractFunctionalTestCase
{
    private string $storageDirectory = '';
    private ResourceStorage $storage;
    private mixed $previousRequest = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storageDirectory = Environment::getPublicPath() . '/fileadmin/aqg-ai-backend-request/';
        GeneralUtility::mkdir_deep($this->storageDirectory);

        $storageRepository = $this->get(StorageRepository::class);
        $storageUid = $storageRepository->createLocalStorage(
            'AQG AI backend request storage',
            'fileadmin/aqg-ai-backend-request/',
            'relative',
        );
        $this->storage = $storageRepository->getStorageObject($storageUid);

        $this->previousRequest = $GLOBALS['TYPO3_REQUEST'] ?? null;
        $GLOBALS['TYPO3_REQUEST'] = $this->backendAjaxRequest();
        // A fresh backend request: no fileProcessing aspect, so deferral is the default.
        $this->get(Context::class)->setAspect('fileProcessing', new FileProcessingAspect(true));
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->previousRequest;
        $this->get(Context::class)->setAspect('fileProcessing', new FileProcessingAspect(false));
        if ($this->storageDirectory !== '' && is_dir($this->storageDirectory)) {
            GeneralUtility::rmdir($this->storageDirectory, true);
        }
        parent::tearDown();
    }

    #[Test]
    public function largeJpegIsPreparedInABackendRequest(): void
    {
        $file = $this->importFixture('large-4000x3000.jpg', 'hotel2.jpeg');

        $contents = $this->buildPayload($file, 'image/jpeg');
        $imageInfo = getimagesizefromstring($contents);

        self::assertIsArray($imageInfo);
        self::assertLessThanOrEqual(AiImagePayloadBuilder::MAX_LONGEST_EDGE, max($imageInfo[0], $imageInfo[1]));
    }

    #[Test]
    public function hotel2ShapedJpegIsPreparedWhenTheImageProcessorIsInstalled(): void
    {
        $file = $this->createImage('hotel2.jpeg', 5131, 3118, 'jpeg');

        $imageInfo = getimagesizefromstring($this->buildPayload($file, 'image/jpeg'));

        self::assertIsArray($imageInfo);
        self::assertSame(1600, max($imageInfo[0], $imageInfo[1]));
    }

    #[Test]
    public function largeWebpIsPreparedInABackendRequest(): void
    {
        if (!function_exists('imagewebp')) {
            self::markTestSkipped('GD without WebP support.');
        }
        $file = $this->createImage('large.webp', 4000, 2500, 'webp');

        $imageInfo = getimagesizefromstring($this->buildPayload($file, 'image/webp'));

        self::assertIsArray($imageInfo);
        self::assertLessThanOrEqual(AiImagePayloadBuilder::MAX_LONGEST_EDGE, max($imageInfo[0], $imageInfo[1]));
    }

    #[Test]
    public function largePngIsPreparedInABackendRequest(): void
    {
        $file = $this->importFixture('adaptive-3000x2250.png', 'adaptive.png');

        $contents = $this->buildPayload($file, 'image/png');
        $imageInfo = getimagesizefromstring($contents);

        self::assertIsArray($imageInfo);
        self::assertLessThanOrEqual(AiImagePayloadBuilder::MAX_LONGEST_EDGE, max($imageInfo[0], $imageInfo[1]));
    }

    /**
     * typo3test14 (2026-11-14): [GFX] processor=GraphicsMagick, processor_path=/usr/bin/, but no `gm`
     * binary on the host. TYPO3 then answers every resize with the original file (empty processed
     * identifier), so a 5131x3118 photo can never be reduced. AQG must refuse it before any provider
     * request instead of sending an image above its payload limits.
     */
    #[Test]
    public function missingImageProcessorBinaryRefusesAnOversizedOriginal(): void
    {
        $emptyBinaryDirectory = Environment::getVarPath() . '/aqg-no-image-processor/';
        GeneralUtility::mkdir_deep($emptyBinaryDirectory);
        $previousPath = $GLOBALS['TYPO3_CONF_VARS']['GFX']['processor_path'] ?? '';
        $GLOBALS['TYPO3_CONF_VARS']['GFX']['processor_path'] = $emptyBinaryDirectory;

        // Same shape as hotel2.jpeg on typo3test14: 5131x3118 (16 MP) but well below 2 MB.
        $file = $this->createImage('hotel2.jpeg', 5131, 3118, 'jpeg');
        self::assertLessThan(AiImagePayloadBuilder::MAX_PAYLOAD_BYTES, $file->getSize());
        try {
            $this->get(AiImagePayloadBuilder::class)->build($this->context($file));
            self::fail('An image that TYPO3 cannot reduce must not become an AI payload.');
        } catch (AiImagePayloadException $exception) {
            self::assertSame(1771002204, $exception->getCode());
            $diagnostics = $exception->diagnostics();
            self::assertTrue($diagnostics['usesOriginalFile'] ?? null);
            self::assertSame('image/jpeg', $diagnostics['detectedMimeType'] ?? null);
            self::assertSame('5131x3118', $diagnostics['detectedDimensions'] ?? null);
        } finally {
            $GLOBALS['TYPO3_CONF_VARS']['GFX']['processor_path'] = $previousPath;
            GeneralUtility::rmdir($emptyBinaryDirectory, true);
        }
    }

    private function buildPayload(File $file, string $expectedMimeType): string
    {
        $materializer = new CountingProcessedImageMaterializer($this->get(AiProcessedImageMaterializerInterface::class));
        $builder = new AiImagePayloadBuilder($this->get(ResourceFactory::class), $this->get(ImageService::class), $materializer);
        $payload = $builder->build($this->context($file));
        self::assertSame($expectedMimeType, $payload->mimeType);
        // TYPO3 deferred the derivative in this backend request; the builder materialized it itself.
        self::assertGreaterThanOrEqual(1, $materializer->calls);

        $separator = strpos($payload->dataUrl, ',');
        self::assertNotFalse($separator);
        $contents = base64_decode(substr($payload->dataUrl, $separator + 1), true);
        self::assertIsString($contents);
        self::assertNotSame('', $contents);
        self::assertLessThanOrEqual(AiImagePayloadBuilder::MAX_PAYLOAD_BYTES, strlen($contents));
        return $contents;
    }

    private function backendAjaxRequest(): ServerRequest
    {
        $serverParams = [
            'HTTP_HOST' => 'localhost',
            'SERVER_NAME' => 'localhost',
            'HTTPS' => 'on',
            'SERVER_PORT' => '443',
            'REQUEST_URI' => '/typo3/ajax/aqg/ai/alt-suggestion',
            'SCRIPT_NAME' => '/index.php',
            'PHP_SELF' => '/index.php',
            'SCRIPT_FILENAME' => Environment::getPublicPath() . '/index.php',
            'REMOTE_ADDR' => '127.0.0.1',
        ];
        $request = new ServerRequest('https://localhost/typo3/ajax/aqg/ai/alt-suggestion', 'POST', 'php://input', [], $serverParams);
        return $request
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE | SystemEnvironmentBuilder::REQUESTTYPE_AJAX)
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams($serverParams));
    }

    private function createImage(string $targetName, int $width, int $height, string $type): File
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, (int)imagecolorallocate($image, 90, 120, 150));
        $target = $this->storageDirectory . $targetName;
        self::assertTrue($type === 'webp' ? imagewebp($image, $target, 80) : imagejpeg($image, $target, 80));
        imagedestroy($image);

        $file = $this->storage->getFile('/' . $targetName);
        self::assertInstanceOf(File::class, $file);
        return $file;
    }

    private function importFixture(string $fixtureName, string $targetName): File
    {
        $source = dirname(__DIR__, 2) . '/Fixtures/Images/' . $fixtureName;
        self::assertFileExists($source);
        self::assertTrue(copy($source, $this->storageDirectory . $targetName));

        $file = $this->storage->getFile('/' . $targetName);
        self::assertInstanceOf(File::class, $file);
        return $file;
    }

    private function context(File $file): ImageFindingContext
    {
        $connection = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_file_reference');
        $connection->insert('sys_file_reference', [
            'pid' => 0,
            'uid_local' => $file->getUid(),
            'uid_foreign' => 2075,
            'tablenames' => 'tt_content',
            'fieldname' => 'image',
            'sys_language_uid' => 0,
            'deleted' => 0,
            'hidden' => 0,
        ]);
        $referenceUid = (int)$connection->lastInsertId();

        return new ImageFindingContext(
            issue: ['uid' => 9101],
            fileReference: ['uid' => $referenceUid, 'uid_local' => $file->getUid()],
            siteIdentifier: 'functional-test',
            pageUid: 0,
            languageUid: 0,
            sourceTable: 'tt_content',
            sourceUid: 2075,
            sourceField: 'image',
            fileReferenceUid: $referenceUid,
            fileUid: $file->getUid(),
            fingerprint: 'functional-backend-request-payload',
            issueTimestamp: 0,
            fileReferenceTimestamp: 0,
        );
    }
}
