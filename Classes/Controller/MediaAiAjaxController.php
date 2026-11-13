<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Ai\Editor\EditorAiAccessService;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiException;
use Priebera\A11yQualityGate\Ai\Exception\AiConfigurationException;
use Priebera\A11yQualityGate\Ai\Exception\AiProviderException;
use Priebera\A11yQualityGate\Ai\Exception\AiRateLimitException;
use Priebera\A11yQualityGate\Ai\Media\FileMetadataAiService;
use Priebera\A11yQualityGate\Ai\Media\MediaAiFile;
use Priebera\A11yQualityGate\Ai\Media\MediaAiMetadataEditLink;
use Priebera\A11yQualityGate\Ai\Media\ReferenceAltAiService;
use Priebera\A11yQualityGate\Service\BackendUserService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * AI media actions of the metadata form and the Filelist (file metadata) and of image fields in content elements (one
 * image use). Every action is read-only: it returns a pre-flight, a suggestion or a check result. The browser fills
 * the form fields and TYPO3 Save stores them; AQG has no write path into sys_file_metadata. Error codes are stable
 * and safe; provider messages, keys and content never reach the browser.
 */
#[AsController]
final class MediaAiAjaxController
{
    public function __construct(
        private readonly FileMetadataAiService $metadataService,
        private readonly ReferenceAltAiService $referenceService,
        private readonly EditorAiAccessService $accessService,
        private readonly BackendUserService $backendUserService,
        private readonly MediaAiMetadataEditLink $editLink,
    ) {}

    public function fileStateAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handle($request, function (array $body): array {
            $state = $this->metadataService->state(
                is_array($body['fileUids'] ?? null) ? array_map('intval', array_filter($body['fileUids'], 'is_scalar')) : [],
                (int)($body['languageUid'] ?? 0),
                (string)($body['site'] ?? ''),
                $this->context($body),
            );
            // The way on for each editable file: TYPO3's metadata form, where the wand suggests and TYPO3 Save stores.
            foreach ($state['files'] as $index => $file) {
                $state['files'][$index]['editUrl'] = ($file['status'] ?? '') === MediaAiFile::STATUS_ELIGIBLE ? $this->editLink->url((int)($file['uid'] ?? 0)) : '';
            }

            return $state + ['status' => 'state'];
        });
    }

    public function suggestMetadataAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handle($request, fn (array $body): array => $this->metadataService->suggest(
            (int)($body['fileUid'] ?? 0),
            (int)($body['languageUid'] ?? 0),
            is_array($body['fields'] ?? null) ? array_values($body['fields']) : [],
            (string)($body['site'] ?? ''),
            ($body['mode'] ?? '') === 'translate' ? 'translate' : 'image',
            $this->context($body),
        ));
    }

    public function verifyFileAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handle($request, fn (array $body): array => $this->metadataService->verify(
            (int)($body['fileUid'] ?? 0),
            (int)($body['languageUid'] ?? 0),
        ));
    }

    public function suggestReferenceAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handle($request, fn (array $body): array => $this->referenceService->suggest([
            'parentTable' => (string)($body['parentTable'] ?? ''),
            'parentUid' => (int)($body['parentUid'] ?? 0),
            'fieldName' => (string)($body['fieldName'] ?? ''),
            'referenceUid' => (int)($body['referenceUid'] ?? 0),
            'fileUid' => (int)($body['fileUid'] ?? 0),
            'currentAlt' => (string)($body['currentAlt'] ?? ''),
            'mode' => ($body['mode'] ?? '') === 'translate' ? 'translate' : 'suggest',
        ]));
    }

    public function verifyReferenceAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handle($request, function (array $body): array {
            $items = [];
            foreach (array_slice(is_array($body['items'] ?? null) ? $body['items'] : [], 0, 20) as $item) {
                if (is_array($item)) {
                    $items[] = ['referenceUid' => (int)($item['referenceUid'] ?? 0), 'suggestedAt' => (int)($item['suggestedAt'] ?? 0)];
                }
            }

            return $this->referenceService->verify(
                (string)($body['parentTable'] ?? ''),
                (int)($body['parentUid'] ?? 0),
                (string)($body['fieldName'] ?? ''),
                $items,
            ) + ['status' => 'checked'];
        });
    }

    private function context(array $body): string
    {
        return match ($body['context'] ?? '') {
            FileMetadataAiService::CONTEXT_BULK => FileMetadataAiService::CONTEXT_BULK,
            FileMetadataAiService::CONTEXT_UPLOAD => FileMetadataAiService::CONTEXT_UPLOAD,
            default => FileMetadataAiService::CONTEXT_SINGLE,
        };
    }

    /** @param callable(array<string,mixed>):array<string,mixed> $action */
    private function handle(ServerRequestInterface $request, callable $action): ResponseInterface
    {
        if (!$this->backendUserService->isLoggedIn()) {
            return $this->error('session_expired', 401);
        }
        if (strtoupper($request->getMethod()) !== 'POST') {
            return $this->error('invalid_input', 405);
        }
        try {
            $body = json_decode((string)$request->getBody(), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->error('invalid_input', 400);
        }
        if (!is_array($body)) {
            return $this->error('invalid_input', 400);
        }

        try {
            return new JsonResponse(['success' => true] + $action($body));
        } catch (EditorAiException $exception) {
            return $this->error($exception->safeCode, $exception->httpStatus);
        } catch (AiRateLimitException $exception) {
            return $this->error('rate_limited', 429, ['retryAfter' => max(1, $exception->retryAfter)]);
        } catch (AiConfigurationException $exception) {
            return match ($exception->getCode()) {
                1771002403 => $this->error('privacy_images', 422),
                1771002405 => $this->error('privacy_page_title', 422),
                1771002404 => $this->error(EditorAiAccessService::STATE_NO_LICENCE, 403),
                // Readiness and budget refusals (also from the locked budget check right before the request).
                default => $this->error(EditorAiAccessService::stateForConfigurationCode((int)$exception->getCode()), 422),
            };
        } catch (AiProviderException $exception) {
            return $this->error($this->providerCode((int)$exception->getCode()), 502, ['providerCode' => $this->accessService->isAdmin() ? (int)$exception->getCode() : 0]);
        } catch (\InvalidArgumentException) {
            return $this->error('invalid_input', 422);
        } catch (\Throwable $exception) {
            GeneralUtility::makeInstance(LogManager::class)->getLogger(self::class)->error('aqg_media_ai_failure', ['exception_class' => $exception::class, 'exception_code' => (int)$exception->getCode()]);
            return $this->error('internal_error', 500);
        }
    }

    /** The shared AQG provider error codes (OpenAI and Anthropic map to the same ones). */
    private function providerCode(int $code): string
    {
        return match ($code) {
            1771002313 => 'refused',
            1771002302 => 'provider_timeout',
            1771002311 => 'provider_rate_limited',
            1771002320, 1771002323 => 'provider_credit',
            1771002310, 1771002317, 1771002321 => 'paused',
            1771002301, 1771002304, 1771002305, 1771002314, 1771002315, 1771002316, 1771002318 => 'invalid_response',
            default => 'provider_unavailable',
        };
    }

    /** @param array<string,mixed> $extra */
    private function error(string $code, int $status, array $extra = []): JsonResponse
    {
        return new JsonResponse(['success' => false, 'status' => 'error', 'code' => $code] + $extra, $status);
    }
}
