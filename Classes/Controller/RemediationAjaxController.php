<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Ai\Editor\EditorAiErrorResponse;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiException;
use Priebera\A11yQualityGate\Ai\Usage\AiMoney;
use Priebera\A11yQualityGate\Ai\Usage\AiUsageRecorder;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationAccessService;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationAssignedOwnerService;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationChangeLogService;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationException;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationItemPresenter;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationOwnerRuleValidator;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationPageSummaryService;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationQueueService;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationWorkflowService;
use Priebera\A11yQualityGate\Service\BackendUserService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Priebera\A11yQualityGate\Utility\BackendLabelUtility;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The remediation queue's actions. The browser names an item uid and the row version it saw — never a table, uid or
 * field — and every answer carries the item as the server now holds it, so a stale screen updates itself. Error
 * codes are stable and safe; provider messages and content never reach the browser.
 */
#[AsController]
final class RemediationAjaxController
{
    public function __construct(
        private readonly RemediationWorkflowService $workflow,
        private readonly RemediationQueueService $queue,
        private readonly RemediationItemPresenter $presenter,
        private readonly RemediationAccessService $access,
        private readonly RemediationPageSummaryService $pageSummary,
        private readonly IssueRepository $issueRepository,
        private readonly SiteResolutionService $siteResolutionService,
        private readonly BackendUserService $backendUserService,
        private readonly RemediationChangeLogService $changeLog,
        // Nullable so a DI container cached before 3.0 degrades to makeInstance() instead of breaking every route.
        private readonly ?RemediationOwnerRuleValidator $ownerRules = null,
        private readonly ?RemediationAssignedOwnerService $assignedOwners = null,
        private readonly ?AiUsageRecorder $usageRecorder = null,
    ) {}

    public function listAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handle($request, function (array $body): array {
            $this->access->assertLoggedIn();
            // U-09: suggestions of findings that were fixed, ignored or removed meanwhile leave every queue first.
            $this->workflow->closeItemsOfClosedFindings();
            $sites = $this->scopeSites($body);
            $result = $this->queue->list($sites, [
                'tab' => (string)($body['tab'] ?? 'open'),
                'status' => (string)($body['status'] ?? ''),
                'owner' => (string)($body['owner'] ?? ''),
                'pageUid' => (int)($body['pageUid'] ?? 0),
                'languageUid' => (int)($body['languageUid'] ?? -1),
                'severity' => (int)($body['severity'] ?? -1),
                'origin' => (string)($body['origin'] ?? ''),
                'batchId' => (string)($body['batchId'] ?? ''),
                'page' => (int)($body['page'] ?? 1),
                'query' => mb_substr((string)($body['query'] ?? ''), 0, RemediationQueueService::QUERY_MAX_LENGTH),
            ], fn (array $item): string => $this->presenter->searchText($item));
            $returnUrl = $this->safeReturnUrl((string)($body['returnUrl'] ?? ''));
            $result['items'] = array_map(fn (array $item): array => $this->presenter->present($item, false, $returnUrl), $result['items']);

            return ['status' => 'list'] + $result;
        });
    }

    /**
     * The module change log (Atlas 08·E), scoped like the queue: sites the person may read with the licence, items
     * the person may see. Read only.
     */
    public function changelogAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handle($request, function (array $body): array {
            $this->access->assertLoggedIn();
            $result = $this->changeLog->list($this->scopeSites($body), [
                'period' => (string)($body['period'] ?? RemediationChangeLogService::DEFAULT_PERIOD),
                'person' => (int)($body['person'] ?? 0),
                'action' => (string)($body['action'] ?? ''),
                'origin' => (string)($body['origin'] ?? ''),
                'page' => (int)($body['page'] ?? 1),
            ]);

            return [
                'status' => 'changelog',
                'entries' => array_map(fn (array $entry): array => $this->presenter->presentLogEntry($entry['event'], $entry['item']), $result['entries']),
                'total' => $result['total'],
                'page' => $result['page'],
                'pages' => $result['pages'],
                'perPage' => RemediationChangeLogService::PER_PAGE,
                'people' => array_map(fn (int $uid): array => ['uid' => $uid, 'name' => $this->presenter->personName($uid)], $result['people']),
            ];
        });
    }

    public function itemAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handle($request, fn (array $body): array => $this->itemResponse($this->workflow->visibleItem((int)($body['item'] ?? 0)), $body));
    }

    public function prepareAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handle($request, function (array $body): array {
            $findingUids = is_array($body['findingUids'] ?? null) ? array_map('intval', array_slice($body['findingUids'], 0, RemediationWorkflowService::MAX_BATCH + 1)) : [];
            if ($findingUids === [] && (int)($body['pageUid'] ?? 0) > 0) {
                $findingUids = $this->eligibleOnPage((int)$body['pageUid'], (int)($body['languageUid'] ?? -1));
            }
            $origin = ($body['scope'] ?? '') === 'bulk' || count($findingUids) > 1 ? RemediationAccessService::ORIGIN_BULK : RemediationAccessService::ORIGIN_EDITOR_REQUEST;

            return ['status' => 'prepared'] + $this->workflow->prepare($findingUids, $origin);
        });
    }

    /** Page Detail "Explain with AI": an explanation of one open finding; nothing is queued or changed. */
    public function explainAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->usageRecorder?->forgetLastRecord();

        return $this->withUsage($this->handle($request, fn (array $body): array => $this->workflow->explain((int)($body['findingUid'] ?? 0))));
    }

    public function generateAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->usageRecorder?->forgetLastRecord();

        return $this->withUsage($this->handle($request, function (array $body): array {
            $item = $this->workflow->generate((int)($body['item'] ?? 0), (int)($body['rowVersion'] ?? -1));
            $retryAfter = (int)($item['retryAfter'] ?? 0);
            unset($item['retryAfter']);

            return $this->itemResponse($item, $body) + ($retryAfter > 0 ? ['retryAfter' => $retryAfter] : []);
        }));
    }

    public function viewAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handle($request, fn (array $body): array => $this->itemResponse(
            $this->workflow->markViewed((int)($body['item'] ?? 0), (int)($body['proposalVersion'] ?? 0)),
            $body,
        ));
    }

    public function editAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handle($request, fn (array $body): array => $this->itemResponse(
            $this->workflow->editValue((int)($body['item'] ?? 0), (int)($body['rowVersion'] ?? -1), mb_substr((string)($body['value'] ?? ''), 0, 1000)),
            $body,
        ));
    }

    public function approveAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handle($request, fn (array $body): array => $this->itemResponse(
            $this->workflow->approve(
                (int)($body['item'] ?? 0),
                (int)($body['rowVersion'] ?? -1),
                ($body['bulk'] ?? false) === true,
                preg_match('/^[a-f0-9]{16}$/', (string)($body['batchId'] ?? '')) === 1 ? (string)$body['batchId'] : '',
            ),
            $body,
        ));
    }

    public function rejectAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handle($request, fn (array $body): array => $this->itemResponse(
            $this->workflow->reject((int)($body['item'] ?? 0), (int)($body['rowVersion'] ?? -1), (string)($body['reason'] ?? 'none')),
            $body,
        ));
    }

    public function reopenAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handle($request, fn (array $body): array => $this->itemResponse(
            $this->workflow->reopen((int)($body['item'] ?? 0), (int)($body['rowVersion'] ?? -1)),
            $body,
        ));
    }

    public function refreshAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handle($request, fn (array $body): array => $this->itemResponse($this->workflow->refresh((int)($body['item'] ?? 0)), $body));
    }

    public function stagedAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handle($request, fn (array $body): array => ['status' => 'staged'] + $this->workflow->staged(
            (int)($body['item'] ?? 0),
            (string)($body['table'] ?? ''),
            (int)($body['uid'] ?? 0),
            (string)($body['field'] ?? ''),
        ));
    }

    public function insertedAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handle($request, function (array $body): array {
            $this->workflow->editorInserted((int)($body['item'] ?? 0));

            return ['status' => 'recorded'];
        });
    }

    public function ownerAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handle($request, function (array $body): array {
            $pageUid = (int)($body['pageUid'] ?? 0);
            // The site comes from the page, never from the request.
            $site = $pageUid > 0 ? $this->siteResolutionService->resolveSiteByPageId($pageUid) : null;
            $siteIdentifier = $site?->getIdentifier() ?? '';
            $ruleId = (string)($body['ruleId'] ?? '');
            $this->access->assertLoggedIn();
            // Access before validation: a person who may not assign here learns nothing about the site's rules.
            if ($site === null || !$this->access->canEditPage($pageUid) || !$this->access->canReadSiteIdentifier($siteIdentifier)) {
                throw new RemediationException('permission_denied', 'You may not change the owner on this page.', 403);
            }
            if (!$this->access->hasLicence($siteIdentifier)) {
                throw new RemediationException('no_licence', 'Owners need an AQG PRO or Agency licence.', 403);
            }
            // Only a real issue type: a content finding of this page, or (site root) a rule of a stored paid frontend scan.
            if (!($this->ownerRules ?? GeneralUtility::makeInstance(RemediationOwnerRuleValidator::class))->isAssignable($siteIdentifier, $pageUid, $ruleId, $site->getRootPageId())) {
                throw new RemediationException('invalid_input', 'Unknown issue type.', 422);
            }
            $result = $this->workflow->assignOwner($siteIdentifier, $pageUid, $ruleId, (string)($body['owner'] ?? ''));
            // The issue type's owner tag of the Overview as this person now sees it (all readable pages of the site).
            $tag = ($this->assignedOwners ?? GeneralUtility::makeInstance(RemediationAssignedOwnerService::class))->forRules($siteIdentifier, [$ruleId])[$ruleId] ?? null;
            $tagOwner = $tag['owner'] ?? '';

            return ['status' => 'assigned'] + $result + ['tag' => [
                'owner' => $tagOwner,
                'label' => match ($tagOwner) {
                    '' => '',
                    RemediationAssignedOwnerService::VARIES => BackendLabelUtility::translate('overview.fixFirst.tag.ownerVaries', 'Varies by page'),
                    default => BackendLabelUtility::translate('remediation.owner.' . $tagOwner, ucfirst($tagOwner)),
                },
            ]];
        });
    }

    public function planAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->handle($request, function (array $body): array {
            $this->access->assertLoggedIn();
            $pageUid = (int)($body['pageUid'] ?? 0);
            $site = $pageUid > 0 ? $this->siteResolutionService->resolveSiteByPageId($pageUid) : null;
            if ($site === null || !$this->access->canReadPage($pageUid)) {
                throw new RemediationException('not_found', 'This page is not available.', 404);
            }
            $page = BackendUtility::getRecordWSOL('pages', $pageUid);
            $title = is_array($page) ? BackendUtility::getRecordTitle('pages', $page) : '';

            return ['status' => 'plan'] + $this->pageSummary->plan(
                $site->getIdentifier(),
                $pageUid,
                $this->issueRepository->findOpenForPage($pageUid, $site->getIdentifier(), (int)($body['languageUid'] ?? -1), true),
                $title,
            );
        });
    }

    /**
     * The sites a list request may cover: those named in the request that this person may read and that carry the
     * licence. A site the request invents is dropped, never trusted.
     *
     * @param array<string,mixed> $body
     * @return list<string>
     */
    private function scopeSites(array $body): array
    {
        return array_values(array_filter(
            array_map('strval', is_array($body['sites'] ?? null) ? array_slice($body['sites'], 0, 50) : []),
            // N-09: any readable page of the site is enough here; every item is checked against its own page and record.
            fn (string $site): bool => $this->access->canListSiteIdentifier($site) && $this->access->hasLicence($site),
        ));
    }

    /**
     * Open findings of one page that have an AI fix path, up to the batch limit.
     *
     * @return list<int>
     */
    private function eligibleOnPage(int $pageUid, int $languageUid): array
    {
        $site = $this->siteResolutionService->resolveSiteByPageId($pageUid);
        if ($site === null || !$this->access->canReadPage($pageUid)) {
            return [];
        }
        $uids = [];
        foreach ($this->issueRepository->findOpenForPage($pageUid, $site->getIdentifier(), $languageUid, true) as $issue) {
            if (in_array($this->pageSummary->fixPath($issue), [RemediationPageSummaryService::FIX_AI_IMAGE, RemediationPageSummaryService::FIX_AI_TEXT], true)) {
                $uids[] = (int)$issue['uid'];
            }
        }

        return array_slice($uids, 0, RemediationWorkflowService::MAX_BATCH);
    }

    /**
     * @param array<string,mixed> $item
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function itemResponse(array $item, array $body): array
    {
        return ['status' => 'item', 'item' => $this->presenter->present($item, ($body['detailed'] ?? true) !== false, $this->safeReturnUrl((string)($body['returnUrl'] ?? '')))];
    }

    /** Only a backend route of this installation may become the editor's return URL. */
    private function safeReturnUrl(string $url): string
    {
        return preg_match('#^/[A-Za-z0-9/_\-]*(?:\?[A-Za-z0-9_\-\[\]=&%.]*)?$#', $url) === 1 && !str_starts_with($url, '//') ? $url : '';
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
        } catch (RemediationException $exception) {
            return $this->error($exception->safeCode, $exception->httpStatus, $exception->data);
        } catch (EditorAiException $exception) {
            return $this->error($exception->safeCode, $exception->httpStatus);
        } catch (\Throwable $exception) {
            // Provider, rate-limit, readiness and budget refusals of an AI request (explain) answer with the editor's
            // stable codes instead of a generic failure.
            // Not \InvalidArgumentException: in these actions it is an internal error and must stay logged.
            $response = $exception instanceof \InvalidArgumentException ? null : EditorAiErrorResponse::fromThrowable($exception, $this->backendUserService->isAdmin());
            if ($response !== null) {
                return $response;
            }
            GeneralUtility::makeInstance(LogManager::class)->getLogger(self::class)->error('aqg_remediation_failure', ['exception_class' => $exception::class, 'exception_code' => (int)$exception->getCode()]);

            return $this->error('internal_error', 500);
        }
    }

    /**
     * Adds what this click did at the provider, from the usage the recorder computed for it (the existing usage and
     * price system; nothing new is priced here): "sent" false when no provider request was made (refused before
     * sending, rate limit, budget), otherwise the outcome and the recorded cost with its basis (reported tokens, the
     * estimated maximum when the provider reported none, none for a request rejected before processing). Never the
     * prompt, the answer or provider details.
     */
    private function withUsage(ResponseInterface $response): ResponseInterface
    {
        if ($this->usageRecorder === null || !$response instanceof JsonResponse) {
            return $response;
        }
        $data = json_decode((string)$response->getBody(), true);
        if (!is_array($data)) {
            return $response;
        }
        $last = $this->usageRecorder->lastRecord();
        $data['aiUsage'] = $last === null ? ['sent' => false] : [
            'sent' => true,
            'outcome' => $last['outcome'],
            'basis' => $last['basis'],
            'costKnown' => $last['costKnown'],
            'cost' => $last['costKnown'] ? AiMoney::formatUsd($last['costMicro']) : '',
            // Lets a batch show one total instead of one rounded term per request (U-05).
            'costMicro' => $last['costKnown'] ? max(0, (int)$last['costMicro']) : null,
        ];

        // A new response: setPayload() would append to the stream that was just read. Headers (Retry-After) are kept.
        return new JsonResponse($data, $response->getStatusCode(), $response->getHeaders());
    }

    /** @param array<string,mixed> $extra */
    private function error(string $code, int $status, array $extra = []): JsonResponse
    {
        return new JsonResponse(['success' => false, 'status' => 'error', 'code' => $code] + $extra, $status);
    }
}
