<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use GuzzleHttp\Utils;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Enum\IssueStatus;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Service\BackendUserService;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use TYPO3\CMS\Backend\Attribute\AsController;

#[AsController]
final class IssueApiController extends AbstractApiController
{
    public function __construct(
        private readonly IssueRepository $issueRepository,
        ResponseFactoryInterface $responseFactory,
        StreamFactoryInterface $streamFactory,
        BackendUserService $backendUserService,
    ) {
        parent::__construct($responseFactory, $streamFactory, $backendUserService);
    }

    public function issuesAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isBackendUserLoggedIn()) {
            return $this->unauthorizedResponse();
        }

        $params = $request->getQueryParams();
        $recordUid = (int)($params['recordUid'] ?? 0);
        $fieldName = trim((string)($params['fieldName'] ?? 'bodytext'));

        if ($recordUid <= 0) {
            return $this->badRequestResponse('Missing recordUid');
        }

        $issues = $this->issueRepository->findOpenForRecord(
            sourceTable: Tables::TT_CONTENT,
            sourceUid: $recordUid,
            sourceField: $fieldName,
        );

        return $this->jsonResponse([
            'success' => true,
            'issues' => array_map(
                fn(array $row): array => $this->formatIssue($row),
                $issues
            ),
        ]);
    }

    public function ignoreAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->isBackendUserLoggedIn()) {
            return $this->unauthorizedResponse();
        }

        if (strtoupper($request->getMethod()) !== 'POST') {
            return $this->jsonResponse([
                'success' => false,
                'error' => 'Method not allowed',
            ], 405);
        }

        $body = (string)$request->getBody();

        try {
            $data = Utils::jsonDecode($body, true) ?? [];
        } catch (\InvalidArgumentException) {
            return $this->badRequestResponse('Invalid JSON body');
        }

        $fingerprint = trim((string)($data['fingerprint'] ?? ''));
        $reason = trim((string)($data['reason'] ?? 'Ignored via editor'));

        if ($fingerprint === '') {
            return $this->badRequestResponse('Missing fingerprint');
        }

        if ($reason === '') {
            $reason = 'Ignored via CKEditor';
        }

        $issue = $this->issueRepository->findByFingerprintPublic($fingerprint);
        if ($issue === null) {
            return $this->notFoundResponse('Issue not found');
        }

        $status = IssueStatus::fromInt((int)$issue['status']);
        if ($status->isProtected()) {
            return $this->jsonResponse([
                'success' => false,
                'error' => 'Issue is already ignored or muted',
            ], 409);
        }

        $this->issueRepository->ignore(
            issueUid: (int)$issue['uid'],
            reason: $reason,
            backendUserUid: $this->getBackendUserUid(),
        );

        return $this->jsonResponse([
            'success' => true,
            'fingerprint' => $fingerprint,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatIssue(array $row): array
    {
        $severity = Severity::fromInt((int)$row['severity']);

        return [
            'fingerprint' => $row['fingerprint'] ?? '',
            'ruleId' => $row['rule_id'] ?? '',
            'severity' => strtolower($severity->name),
            'message' => $row['message'] ?? '',
            'hint' => $row['hint'] ?? '',
            'snippet' => $row['context_snippet'] ?? '',
            'contextPath' => $row['context_path'] ?? '',
            'status' => (int)($row['status'] ?? 0),
        ];
    }
}