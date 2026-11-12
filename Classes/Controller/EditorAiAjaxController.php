<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Ai\Editor\EditorAiAccessService;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiErrorResponse;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiRemediationService;
use Priebera\A11yQualityGate\Service\BackendUserService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Editor AI actions of the CKEditor plugin and the plain HTML editor. Every answer is a suggestion for the editor to
 * review; this controller never writes content. Errors carry a stable code the editor maps to its own copy; provider
 * messages, keys and content never reach the browser.
 */
#[AsController]
final class EditorAiAjaxController
{
    public function __construct(
        private readonly EditorAiRemediationService $remediationService,
        private readonly EditorAiAccessService $accessService,
        private readonly BackendUserService $backendUserService,
    ) {}

    public function fixAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handle($request, fn (array $body): array => $this->remediationService->fix($this->findingInput($body)));
    }

    public function explainAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handle($request, fn (array $body): array => $this->remediationService->explain($this->findingInput($body)));
    }

    public function writeAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handle($request, fn (array $body): array => $this->remediationService->write(
            (string)($body['table'] ?? 'tt_content'),
            (int)($body['recordUid'] ?? 0),
            (string)($body['fieldName'] ?? ''),
            (string)($body['mode'] ?? ''),
            (string)($body['text'] ?? ''),
        ));
    }

    public function verifyAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handle($request, function (array $body): array {
            $items = [];
            foreach (array_slice(is_array($body['items'] ?? null) ? $body['items'] : [], 0, 20) as $item) {
                if (is_array($item)) {
                    $items[] = [
                        'ruleId' => (string)($item['ruleId'] ?? ''),
                        'tag' => (string)($item['tag'] ?? ''),
                        'attributes' => is_array($item['attributes'] ?? null) ? array_map('strval', array_filter($item['attributes'], 'is_scalar')) : [],
                        'text' => (string)($item['text'] ?? ''),
                        'suggestedAt' => (int)($item['suggestedAt'] ?? 0),
                        'occurrence' => (int)($item['occurrence'] ?? -1),
                        'total' => (int)($item['total'] ?? -1),
                    ];
                }
            }

            return $this->remediationService->verify(
                (string)($body['table'] ?? 'tt_content'),
                (int)($body['recordUid'] ?? 0),
                (string)($body['fieldName'] ?? ''),
                $items,
            ) + ['status' => 'checked'];
        });
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
            $body = json_decode((string)$request->getBody(), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->error('invalid_input', 400);
        }
        if (!is_array($body)) {
            return $this->error('invalid_input', 400);
        }

        try {
            return new JsonResponse(['success' => true] + $action($body));
        } catch (\Throwable $exception) {
            $response = EditorAiErrorResponse::fromThrowable($exception, $this->accessService->isAdmin());
            if ($response !== null) {
                return $response;
            }
            GeneralUtility::makeInstance(LogManager::class)->getLogger(self::class)->error('aqg_editor_ai_failure', ['exception_class' => $exception::class, 'exception_code' => (int)$exception->getCode()]);
            return $this->error('internal_error', 500);
        }
    }

    /** @return array{table:string,uid:int,field:string,html:string,ruleId:string,contextPath:string,occurrence:int} */
    private function findingInput(array $body): array
    {
        return [
            'table' => (string)($body['table'] ?? 'tt_content'),
            'uid' => (int)($body['recordUid'] ?? 0),
            'field' => (string)($body['fieldName'] ?? ''),
            'html' => (string)($body['html'] ?? ''),
            'ruleId' => (string)($body['ruleId'] ?? ''),
            'contextPath' => (string)($body['contextPath'] ?? ''),
            'occurrence' => (int)($body['occurrence'] ?? -1),
        ];
    }

    /** @param array<string,mixed> $extra */
    private function error(string $code, int $status, array $extra = []): JsonResponse
    {
        return new JsonResponse(['success' => false, 'status' => 'error', 'code' => $code] + $extra, $status);
    }
}
