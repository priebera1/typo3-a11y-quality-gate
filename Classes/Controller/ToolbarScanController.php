<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Ai\Editor\EditorAiAccessService;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiErrorResponse;
use Priebera\A11yQualityGate\Backend\ToolbarItems\A11yScanToolbarItem;
use Priebera\A11yQualityGate\Backend\ToolbarItems\Assistant\Ask\AskAqgLimits;
use Priebera\A11yQualityGate\Backend\ToolbarItems\Assistant\Ask\AskAqgRepairService;
use Priebera\A11yQualityGate\Backend\ToolbarItems\Assistant\Ask\AskAqgService;
use Priebera\A11yQualityGate\Backend\ToolbarItems\Assistant\ToolbarAssistantContext;
use Priebera\A11yQualityGate\Backend\ToolbarItems\Assistant\ToolbarAssistantContextResolver;
use Priebera\A11yQualityGate\Backend\ToolbarItems\Assistant\ToolbarAssistantService;
use Priebera\A11yQualityGate\Service\AccessControlService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AsController]
final class ToolbarScanController
{
    /** The context identifiers "Ask AQG" accepts; the resolver validates and permission-checks each one again. */
    private const ASK_CONTEXT_KEYS = ['id', 'language', 'editTable', 'editUid', 'fileUid', 'unsaved', 'changed'];

    public function __construct(
        private readonly A11yScanToolbarItem $a11yScanToolbarItem,
        private readonly ?ToolbarAssistantContextResolver $assistantContextResolver = null,
        private readonly ?ToolbarAssistantService $assistantService = null,
        private readonly ?AccessControlService $accessControlService = null,
        private readonly ?BackendViewFactory $backendViewFactory = null,
        private readonly ?AskAqgService $askService = null,
        private readonly ?EditorAiAccessService $editorAiAccessService = null,
        private readonly ?AskAqgRepairService $repairService = null,
    ) {
    }

    public function renderMenuAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->a11yScanToolbarItem->setRequest($request);

        return new HtmlResponse($this->a11yScanToolbarItem->getDropDown());
    }

    /**
     * The toolbar assistant for the context the browser reports. Requested only when the user opens the dropdown or
     * asks for it again, never by the 15 s status refresh. It reads stored data only and never calls an AI provider.
     */
    public function assistantAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->assistantContextResolver === null || $this->assistantService === null || $this->backendViewFactory === null
            || !($this->accessControlService?->canShowToolbarItem() ?? false)) {
            return new HtmlResponse('', 403);
        }

        $context = $this->assistantContextResolver->resolve($request);
        $assistant = $this->assistantService->build($context);
        $view = $this->backendViewFactory->create($request, ['a11y_quality_gate']);
        $view->assign('assistant', $assistant);
        $view->assign('ask', $this->askOffer($context, $assistant));

        return new HtmlResponse($view->render('ToolbarItems/A11yScanToolbarAssistant'));
    }

    /**
     * "Ask AQG": one explicit question (POST, JSON body {question, scope, intent, context: {identifiers}, history,
     * conversation}). The context is resolved and permission-checked server-side like the assistant's on every
     * request; AskAqgService answers the example questions deterministically and checks the AI gates, the scope, the
     * history limits and the conversation caps for everything else. The browser keeps the history; nothing is stored. Errors are stable codes; provider messages and content never reach the browser.
     */
    public function askAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->askService === null || $this->assistantContextResolver === null
            || !($this->accessControlService?->canShowToolbarItem() ?? false)) {
            return EditorAiErrorResponse::error('permission_denied', 403);
        }
        if (strtoupper($request->getMethod()) !== 'POST') {
            return EditorAiErrorResponse::error('invalid_input', 405);
        }
        try {
            $body = json_decode((string)$request->getBody(), true, 6, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return EditorAiErrorResponse::error('invalid_input', 400);
        }
        if (!is_array($body)) {
            return EditorAiErrorResponse::error('invalid_input', 400);
        }
        $identifiers = [];
        $context = is_array($body['context'] ?? null) ? $body['context'] : [];
        foreach (self::ASK_CONTEXT_KEYS as $key) {
            if (is_scalar($context[$key] ?? null)) {
                $identifiers[$key] = mb_substr((string)$context[$key], 0, 2000);
            }
        }

        try {
            $resolved = $this->assistantContextResolver->resolve($request->withQueryParams($identifiers));

            return new JsonResponse(['success' => true] + $this->askService->ask(
                $resolved,
                is_string($body['question'] ?? null) ? $body['question'] : '',
                is_string($body['scope'] ?? null) ? $body['scope'] : '',
                is_string($body['intent'] ?? null) ? $body['intent'] : '',
                is_array($body['history'] ?? null) ? $body['history'] : [],
                is_string($body['conversation'] ?? null) ? substr($body['conversation'], 0, 256) : '',
                is_string($body['repairTarget'] ?? null) ? substr($body['repairTarget'], 0, 16) : '',
            ));
        } catch (\Throwable $exception) {
            $response = EditorAiErrorResponse::fromThrowable($exception, $this->editorAiAccessService?->isAdmin() ?? false);
            if ($response !== null) {
                return $response;
            }
            GeneralUtility::makeInstance(LogManager::class)->getLogger(self::class)->error('aqg_toolbar_ask_failure', ['exception_class' => $exception::class, 'exception_code' => (int)$exception->getCode()]);

            return EditorAiErrorResponse::error('internal_error', 500);
        }
    }

    /**
     * Ask AQG repair workflow, "Prepare fixes" (POST, JSON body {context: {identifiers}, target, findingIds}): the context
     * is resolved and permission-checked again, and only findings of that target that the existing AI Fixes workflow can
     * prepare now become AI Fixes items. No AI request is made here; the browser then asks the existing generate route
     * per item with the row version returned, and every item waits for human review in AI Fixes.
     */
    public function repairPrepareAction(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->repairService === null || $this->assistantContextResolver === null
            || !($this->accessControlService?->canShowToolbarItem() ?? false)) {
            return EditorAiErrorResponse::error('permission_denied', 403);
        }
        if (strtoupper($request->getMethod()) !== 'POST') {
            return EditorAiErrorResponse::error('invalid_input', 405);
        }
        try {
            $body = json_decode((string)$request->getBody(), true, 6, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return EditorAiErrorResponse::error('invalid_input', 400);
        }
        if (!is_array($body)) {
            return EditorAiErrorResponse::error('invalid_input', 400);
        }

        try {
            $resolved = $this->assistantContextResolver->resolve($request->withQueryParams($this->contextIdentifiers($body)));

            return new JsonResponse(['success' => true] + $this->repairService->prepare(
                $resolved,
                is_string($body['target'] ?? null) ? substr($body['target'], 0, 16) : '',
                is_array($body['findingIds'] ?? null) ? array_slice(array_values($body['findingIds']), 0, AskAqgRepairService::MAX_FINDINGS + 1) : [],
            ));
        } catch (\Throwable $exception) {
            $response = EditorAiErrorResponse::fromThrowable($exception, $this->editorAiAccessService?->isAdmin() ?? false);
            if ($response !== null) {
                return $response;
            }
            GeneralUtility::makeInstance(LogManager::class)->getLogger(self::class)->error('aqg_toolbar_repair_failure', ['exception_class' => $exception::class, 'exception_code' => (int)$exception->getCode()]);

            return EditorAiErrorResponse::error('internal_error', 500);
        }
    }

    /**
     * The context identifiers of a JSON body (the resolver validates and permission-checks each one again).
     *
     * @param array<mixed> $body
     * @return array<string,string>
     */
    private function contextIdentifiers(array $body): array
    {
        $identifiers = [];
        $context = is_array($body['context'] ?? null) ? $body['context'] : [];
        foreach (self::ASK_CONTEXT_KEYS as $key) {
            if (is_scalar($context[$key] ?? null)) {
                $identifiers[$key] = mb_substr((string)$context[$key], 0, 2000);
            }
        }

        return $identifiers;
    }

    /**
     * What "Ask AQG" offers here: the question field for every user of the assistant on a page, content element or file
     * (also FREE: answered from the stored findings without AI), AI answers only where the server would accept them
     * (AI ready for this user on that site), the website option only with access to the site root.
     *
     * `principal` / `scope` are opaque keys for the browser's conversation storage (user + login session, + context):
     * history of another user, login or context is never shown or sent again (AskAqgService::browserScope()).
     *
     * @param array<string, mixed> $assistant
     * @return array{available: bool, ai: bool, site: bool, cost: string, cap: string, limits: string, principal: string, scope: string}
     */
    private function askOffer(ToolbarAssistantContext $context, array $assistant): array
    {
        $available = $this->askService !== null
            && in_array($context->type, [ToolbarAssistantContext::TYPE_PAGE, ToolbarAssistantContext::TYPE_RECORD, ToolbarAssistantContext::TYPE_FILE], true);

        return [
            'available' => $available,
            'ai' => $available && (bool)($assistant['ai']['ready'] ?? false),
            'site' => $available && $this->askService->canAskSite($context),
            'cost' => (string)($assistant['ai']['cost']['ask'] ?? ''),
            // The money cap is shown only where a price makes it enforceable (the cost hint exists).
            'cap' => (string)($assistant['ai']['cost']['ask'] ?? '') === '' ? '' : '$' . number_format(AskAqgLimits::MAX_CONVERSATION_COST_MICRO_USD / 1_000_000, 2, ($GLOBALS['LANG'] ?? null)?->lang === 'de' ? ',' : '.', ''),
            'limits' => (string)json_encode(AskAqgLimits::toArray()),
        ] + ($available ? $this->askService->browserScope($context) : ['principal' => '', 'scope' => '']);
    }
}
