<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Assignment\AssignmentException;
use Priebera\A11yQualityGate\Assignment\AssignmentService;
use Priebera\A11yQualityGate\Utility\BackendLabelUtility;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Http\JsonResponse;

/**
 * My issues: assign, reassign or unassign the issue type of a finding, and list who may take it. The request names a
 * finding only; site, page, language and rule come from the stored finding (AssignmentService::assignByFinding()).
 */
#[AsController]
final class AssignmentAjaxController
{
    public function __construct(private readonly AssignmentService $assignmentService)
    {
    }

    public function assignAction(ServerRequestInterface $request): ResponseInterface
    {
        $body = $this->body($request);
        try {
            $result = $this->assignmentService->assignByFinding(
                (int)($body['findingId'] ?? 0),
                max(0, (int)($body['assignee'] ?? 0)),
                (string)($body['note'] ?? ''),
                array_key_exists('expectedAssignee', $body) ? max(0, (int)$body['expectedAssignee']) : null,
            );
        } catch (AssignmentException $exception) {
            return new JsonResponse(['success' => false, 'code' => $exception->reason, 'message' => self::message($exception->reason)], $exception->status);
        }

        return new JsonResponse(['success' => true] + $result + [
            'message' => $result['assignee'] > 0
                ? sprintf(BackendLabelUtility::translate('assignment.flash.assigned', 'Assigned to %s.'), $result['assigneeLabel'])
                : BackendLabelUtility::translate('assignment.flash.unassigned', 'Nobody is assigned now.'),
        ]);
    }

    public function candidatesAction(ServerRequestInterface $request): ResponseInterface
    {
        $candidates = $this->assignmentService->candidatesForFinding((int)($this->body($request)['findingId'] ?? 0));

        return new JsonResponse(['success' => true, 'candidates' => $candidates]);
    }

    public static function message(string $code): string
    {
        return match ($code) {
            'assignment_conflict' => BackendLabelUtility::translate('assignment.error.conflict', 'Someone else changed this assignment in the meantime. Reload the page to see who is assigned now.'),
            'permission_denied' => BackendLabelUtility::translate('assignment.error.permission', 'You may not assign work on this page.'),
            'finding_closed' => BackendLabelUtility::translate('assignment.error.closed', 'This issue is no longer open. Rescan the page to see its current issues.'),
            'assignee_no_page' => BackendLabelUtility::translate('assignment.error.noPage', 'This person cannot open this page in TYPO3. Choose someone with access to it.'),
            'assignee_no_language' => BackendLabelUtility::translate('assignment.error.noLanguage', 'This person cannot work in this language. Choose someone with access to it.'),
            'assignee_no_module' => BackendLabelUtility::translate('assignment.error.noModule', 'This person has no access to the Accessibility module.'),
            'assignee_disabled', 'assignee_missing' => BackendLabelUtility::translate('assignment.error.inactive', 'This account is disabled or no longer exists.'),
            default => BackendLabelUtility::translate('assignment.error.notFound', 'This issue is not available.'),
        };
    }

    /** @return array<string, mixed> */
    private function body(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed) && $parsed !== []) {
            return $parsed;
        }
        try {
            $decoded = json_decode((string)$request->getBody(), true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
