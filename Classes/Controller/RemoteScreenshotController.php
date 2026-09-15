<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\Pro\Exception\ProNotConfiguredException;
use Priebera\A11yQualityGate\Pro\Exception\TokenRefreshException;
use Priebera\A11yQualityGate\Pro\Service\RemoteScreenshotService;
use Priebera\A11yQualityGate\Service\BackendRecordAccessService;
use Priebera\A11yQualityGate\Service\BackendUserService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AsController]
final class RemoteScreenshotController extends AbstractApiController
{
    public function __construct(
        private readonly RemoteScreenshotService $remoteScreenshotService,
        private readonly RemoteScanRepository $remoteScanRepository,
        private readonly BackendRecordAccessService $backendRecordAccessService,
        private readonly SiteResolutionService $siteResolutionService,
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
        BackendUserService $backendUserService,
    ) {
        parent::__construct($responseFactory, $streamFactory, $backendUserService);
    }


    private function canAccessRemotePage(int $remotePageUid): bool
    {
        $remotePage = $this->remoteScanRepository->findPageByUid($remotePageUid);
        if (!is_array($remotePage)) {
            return false;
        }

        $remoteScanUid = (int)($remotePage['remote_scan'] ?? 0);
        $remoteScan = $remoteScanUid > 0
            ? $this->remoteScanRepository->findScanByUid($remoteScanUid)
            : null;

        if (!is_array($remoteScan)) {
            return false;
        }

        $pageUid = (int)($remoteScan['page_uid'] ?? 0);
        if ($pageUid > 0) {
            return $this->backendRecordAccessService->canEditRecord(Tables::PAGES, $pageUid);
        }

        $siteIdentifier = trim((string)($remoteScan['site_identifier'] ?? ''));
        if ($siteIdentifier === '') {
            return false;
        }

        $site = $this->siteResolutionService->resolveSiteByIdentifier($siteIdentifier);
        $rootPageId = $site !== null ? (int)$site->getRootPageId() : 0;

        return $rootPageId > 0
            && $this->backendRecordAccessService->canEditRecord(Tables::PAGES, $rootPageId);
    }

    public function showAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isBackendUserLoggedIn()) {
            return $this->unauthorizedResponse();
        }

        $queryParams = $request->getQueryParams();
        $remotePageUid = (int)($queryParams['remotePageUid'] ?? 0);

        if ($remotePageUid <= 0) {
            return $this->badRequestResponse('Missing remotePageUid');
        }

        // Messages from the licence and crawler chain carry crawler URLs, response bodies and token
        // metadata, and database errors carry SQL. They are logged; the client gets a fixed text.
        try {
            if (!$this->canAccessRemotePage($remotePageUid)) {
                return $this->forbiddenResponse();
            }

            $result = $this->remoteScreenshotService->fetchScreenshotByRemotePageUid($remotePageUid);

            if (!is_array($result)) {
                return $this->notFoundResponse('Screenshot could not be loaded');
            }

            $response = $this->responseFactory->createResponse(200)
                ->withHeader('Content-Type', $result['contentType'])
                ->withHeader('X-Content-Type-Options', 'nosniff')
                ->withHeader('Cache-Control', 'private, max-age=300')
                ->withHeader('Content-Disposition', 'inline; filename="' . $result['filename'] . '"');

            $response->getBody()->write($result['content']);

            return $response;
        } catch (TokenRefreshException | ProNotConfiguredException $exception) {
            $this->logScreenshotFailure($remotePageUid, $exception);
            return $this->forbiddenResponse('Screenshot is not available for the current licence.');
        } catch (\Throwable $exception) {
            $this->logScreenshotFailure($remotePageUid, $exception);
            return $this->jsonResponse([
                'success' => false,
                'error' => 'Screenshot could not be loaded.',
            ], 500);
        }
    }

    private function logScreenshotFailure(int $remotePageUid, \Throwable $exception): void
    {
        try {
            GeneralUtility::makeInstance(LogManager::class)
                ->getLogger(__CLASS__)
                ->warning('AQG remote screenshot request failed', [
                    'remotePageUid' => $remotePageUid,
                    'exceptionClass' => $exception::class,
                    'exceptionMessage' => $exception->getMessage(),
                ]);
        } catch (\Throwable) {
            // Logging must never replace the bounded response with a raw error.
        }
    }
}
