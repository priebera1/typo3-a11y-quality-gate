<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Remediation\Workflow;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiException;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiRecordContext;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiRemediationService;
use Priebera\A11yQualityGate\Ai\Editor\EditorFindingTarget;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiValueValidator;
use Priebera\A11yQualityGate\Ai\Exception\AiProviderException;
use Priebera\A11yQualityGate\Ai\Exception\AiRateLimitException;
use Priebera\A11yQualityGate\Ai\Media\ReferenceAltAiService;
use Priebera\A11yQualityGate\Domain\Repository\Contract\IssueRemediationRepositoryInterface;
use Priebera\A11yQualityGate\Remediation\ImageAltTextValidator;
use Priebera\A11yQualityGate\Remediation\ImageFindingContext;
use Priebera\A11yQualityGate\Remediation\ImageFindingContextResolver;
use Priebera\A11yQualityGate\Remediation\ImageFindingVersionTokenService;
use Priebera\A11yQualityGate\Remediation\ImageRemediationServiceInterface;
use Priebera\A11yQualityGate\Remediation\ImageRemediationWriteException;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationAccessService;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationException;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationStatus;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationTarget;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationTargetKind;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationTargetResolver;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationWorkflowService;
use Priebera\A11yQualityGate\Service\Contract\RuleMetadataPresentationServiceInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class RemediationWorkflowServiceTest extends TestCase
{
    private const USER = 7;

    private InMemoryRemediationItemRepository $items;
    /** @var array<string,mixed> */
    private array $issue;
    private RemediationTarget $target;
    /** @var array<string,mixed> the sys_file_reference row as the apply step reads it under its lock */
    private array $storedReference;
    private string $applyRefusal = '';
    private bool $mayUseBulk = true;
    private bool $canSeeOthers = true;
    private int $workspace = 0;
    private bool $altValidatorRefuses = false;
    private int $contextReference = 88;
    private bool $pageReadable = true;
    private bool $targetEditable = true;
    private bool $licence = true;
    private string $aiRefusal = '';
    private ReferenceAltAiService&MockObject $referenceAi;
    private EditorAiRemediationService&MockObject $editorAi;
    private ImageRemediationServiceInterface&MockObject $imageRemediation;
    private RemediationTargetResolver&MockObject $targetResolver;

    protected function setUp(): void
    {
        $this->items = new InMemoryRemediationItemRepository();
        $this->issue = [
            'uid' => 41, 'status' => 0, 'page_uid' => 3, 'site_identifier' => 'main', 'source_lang_uid' => 0, 'fingerprint' => 'fp',
            'rule_id' => 'structured.file_reference_alt', 'severity' => 1, 'source_type' => 'structured', 'context_path' => 'ref:88',
            'context_snippet' => '', 'source_table' => 'tt_content', 'source_uid' => 9, 'source_field' => 'image',
        ];
        $this->target = $this->imageTarget('');
        $this->storedReference = ['alternative' => '', 'tx_a11y_is_decorative' => 0, 'uid_local' => 5];
    }

    #[Test]
    public function prepareCreatesOneItemPerFindingAndNeverASecondOne(): void
    {
        $service = $this->service();
        $first = $service->prepare([41], RemediationAccessService::ORIGIN_EDITOR_REQUEST);
        $second = $service->prepare([41], RemediationAccessService::ORIGIN_EDITOR_REQUEST);

        self::assertSame('queued', $first['items'][0]['outcome']);
        self::assertSame('already_queued', $second['items'][0]['outcome']);
        self::assertSame($first['items'][0]['itemUid'], $second['items'][0]['itemUid']);
        self::assertCount(1, $this->items->items);
        $item = $this->items->findByUid($first['items'][0]['itemUid']);
        self::assertSame(RemediationStatus::PREPARING, $item['status']);
        self::assertSame($this->target->baseHash, $item['base_hash']);
        self::assertSame('editor', $item['owner']);
        self::assertSame(self::USER, $item['created_by']);
    }

    #[Test]
    public function prepareRefusesAgentsBatchesAboveTheCapAndClosedFindings(): void
    {
        $service = $this->service();
        try {
            $service->prepare([41], RemediationAccessService::ORIGIN_AGENT);
            self::fail('Agents must not prepare items.');
        } catch (RemediationException $exception) {
            self::assertSame('agent_disabled', $exception->safeCode);
        }
        try {
            $service->prepare(range(1, 51), RemediationAccessService::ORIGIN_BULK);
            self::fail('The batch cap must hold.');
        } catch (RemediationException $exception) {
            self::assertSame('batch_too_large', $exception->safeCode);
        }
        $this->issue['status'] = 2;
        self::assertSame('finding_closed', $service->prepare([41], RemediationAccessService::ORIGIN_EDITOR_REQUEST)['items'][0]['reason']);
        self::assertSame([], $this->items->items);
    }

    #[Test]
    public function aUserWhoMayOnlyReadThePageCreatesNoItem(): void
    {
        $this->targetEditable = false;

        $result = $this->service()->prepare([41], RemediationAccessService::ORIGIN_EDITOR_REQUEST);

        self::assertSame(['itemUid' => 0, 'outcome' => 'skipped', 'reason' => 'no_edit_access'], array_diff_key($result['items'][0], ['findingUid' => 0]));
        self::assertSame([], $this->items->items, 'nothing that could hold the finding for an editor');
    }

    #[Test]
    public function bulkPreparationNeedsTheQueuePermissionAndNeverRevealsAnotherPersonsItem(): void
    {
        $service = $this->service();
        $service->prepare([41], RemediationAccessService::ORIGIN_EDITOR_REQUEST);
        $this->items->items[1]['created_by'] = 99;

        $this->canSeeOthers = false;
        self::assertSame(0, $service->prepare([41], RemediationAccessService::ORIGIN_EDITOR_REQUEST)['items'][0]['itemUid']);

        $this->mayUseBulk = false;
        try {
            $service->prepare([41, 42], RemediationAccessService::ORIGIN_BULK);
            self::fail('Bulk preparation without ai.bulk must be refused.');
        } catch (RemediationException $exception) {
            self::assertSame('permission_denied', $exception->safeCode);
        }
    }

    #[Test]
    public function prepareSkipsFindingsWithoutASafeTarget(): void
    {
        $this->target = RemediationTarget::unavailable('rendered_output');
        $result = $this->service()->prepare([41], RemediationAccessService::ORIGIN_EDITOR_REQUEST);

        self::assertSame(['findingUid' => 41, 'itemUid' => 0, 'outcome' => 'skipped', 'reason' => 'rendered_output'], $result['items'][0]);
    }

    #[Test]
    public function generateCallsTheProviderOnceAndStoresTheSuggestionWithProvenance(): void
    {
        $service = $this->service();
        $this->referenceAi->expects(self::once())->method('suggest')->willReturn($this->suggestion('Bar chart of water prices'));
        $uid = $this->prepared($service);

        $item = $service->generate($uid, 0);

        self::assertSame(RemediationStatus::PENDING_REVIEW, $item['status']);
        self::assertSame('Bar chart of water prices', $item['proposed_value']);
        self::assertSame(1, $item['proposal_version']);
        self::assertSame('anthropic', $item['provider']);
        self::assertSame(120, $item['input_tokens']);
        // A second request with the old row version is refused: no second provider call, no second charge.
        try {
            $service->generate($uid, 0);
            self::fail('A second generation for the same row version must be refused.');
        } catch (RemediationException $exception) {
            self::assertContains($exception->safeCode, ['in_progress', 'changed_concurrently']);
        }
    }

    #[Test]
    public function aRateLimitKeepsTheItemQueuedWithTheRetryTime(): void
    {
        $service = $this->service();
        $this->referenceAi->method('suggest')->willThrowException(new AiRateLimitException('limited', 42));
        $uid = $this->prepared($service);

        $item = $service->generate($uid, 0);

        self::assertSame(RemediationStatus::PREPARING, $item['status']);
        self::assertSame(42, $item['retryAfter']);
    }

    #[Test]
    public function aSecondGenerationWhileTheFirstRunsIsRefusedEvenWithTheLatestRowVersion(): void
    {
        $service = $this->service();
        $uid = $this->prepared($service);
        $calls = 0;
        $second = null;
        $this->referenceAi->method('suggest')->willReturnCallback(function () use (&$calls, &$second, $service, $uid): array {
            if (++$calls === 1) {
                // Another tab refreshed while the provider is asked: it holds the claimed row version.
                $current = $this->items->findByUid($uid);
                try {
                    $service->generate($uid, (int)$current['row_version']);
                    $second = 'accepted';
                } catch (RemediationException $exception) {
                    $second = $exception->safeCode;
                }
            }

            return $this->suggestion('Bar chart of water prices');
        });

        $item = $service->generate($uid, 0);

        self::assertSame('in_progress', $second);
        self::assertSame(1, $calls, 'One provider request, one charge.');
        self::assertSame(1, (int)$item['attempts']);
        self::assertSame(RemediationStatus::PENDING_REVIEW, $item['status']);
        self::assertFalse(RemediationWorkflowService::isGenerating($item));
    }

    #[Test]
    public function aRateLimitOrAnErrorEndsTheClaimSoTheItemCanBeGeneratedAgain(): void
    {
        $service = $this->service();
        $this->referenceAi->method('suggest')->willReturnOnConsecutiveCalls(
            self::throwException(new AiRateLimitException('limited', 42)),
            self::throwException(new \RuntimeException('connection reset')),
            $this->suggestion('Bar chart'),
        );
        $uid = $this->prepared($service);

        $limited = $service->generate($uid, 0);
        self::assertSame([RemediationStatus::PREPARING, '', 42], [$limited['status'], $limited['status_reason'], $limited['retryAfter']]);

        try {
            $service->generate($uid, (int)$limited['row_version']);
            self::fail('An unexpected error is passed on.');
        } catch (\RuntimeException) {
        }
        $item = $this->items->findByUid($uid);
        self::assertFalse(RemediationWorkflowService::isGenerating($item), 'The error released the claim.');

        self::assertSame(RemediationStatus::PENDING_REVIEW, $service->generate($uid, (int)$item['row_version'])['status']);
        self::assertSame(3, (int)$this->items->findByUid($uid)['attempts']);
    }

    #[Test]
    public function anInterruptedGenerationBlocksOnlyUntilItsLeaseRunsOut(): void
    {
        $service = $this->service();
        $this->referenceAi->method('suggest')->willReturn($this->suggestion('Bar chart'));
        $uid = $this->prepared($service);
        // A request that died without ending its claim (PHP timeout, killed worker).
        $this->items->items[$uid] = ['status_reason' => RemediationWorkflowService::GENERATING, 'row_version' => 1, 'tstamp' => time()] + $this->items->items[$uid];

        try {
            $service->generate($uid, 1);
            self::fail('A running generation must not be started twice.');
        } catch (RemediationException $exception) {
            self::assertSame('in_progress', $exception->safeCode);
        }

        $this->items->items[$uid]['tstamp'] = time() - RemediationWorkflowService::GENERATION_LEASE - 1;
        self::assertSame(RemediationStatus::PENDING_REVIEW, $service->generate($uid, 1)['status']);
    }

    #[Test]
    public function providerFailuresAndNoSuggestionsAreNeverPresentedAsAProposal(): void
    {
        $service = $this->service();
        $this->referenceAi->method('suggest')->willThrowException(new AiProviderException('overloaded', 1771002302));
        $item = $service->generate($this->prepared($service), 0);
        self::assertSame(RemediationStatus::FAILED, $item['status']);
        self::assertSame('provider_timeout', $item['status_reason']);
        self::assertSame('', $item['proposed_value']);

        $this->setUp();
        $service = $this->service();
        $this->referenceAi->method('suggest')->willReturn(['status' => 'decorative', 'value' => '', 'provider' => 'openai', 'model' => 'm', 'usage' => []]);
        $item = $service->generate($this->prepared($service), 0);
        self::assertSame(RemediationStatus::NO_SUGGESTION, $item['status']);
        self::assertSame('decorative', $item['status_reason']);
    }

    #[Test]
    public function aRejectionWhileTheSuggestionIsGeneratedIsNotOverridden(): void
    {
        $service = $this->service();
        $uid = $this->prepared($service);
        $this->referenceAi->method('suggest')->willReturnCallback(function () use ($service, $uid): array {
            // Someone rejects the item while the provider is still answering.
            $current = $this->items->findByUid($uid);
            $service->reject($uid, (int)$current['row_version'], 'other');

            return $this->suggestion('Late answer');
        });

        $item = $service->generate($uid, 0);

        self::assertSame(RemediationStatus::REJECTED, $item['status']);
        self::assertSame('', $item['proposed_value']);
        self::assertSame('reject_other', $item['status_reason']);
    }

    #[Test]
    public function aLateAnswerOfAnEarlierClaimNeverLandsOnAReopenedAndRegeneratedItem(): void
    {
        $service = $this->service();
        $uid = $this->prepared($service);
        $calls = 0;
        $this->referenceAi->method('suggest')->willReturnCallback(function () use (&$calls, $service, $uid): array {
            if (++$calls === 1) {
                // While A waits: reject, reopen, and B generates (and finishes) on the reopened item.
                $service->reject($uid, (int)$this->items->findByUid($uid)['row_version'], 'other');
                $reopened = $service->reopen($uid, (int)$this->items->findByUid($uid)['row_version']);
                $service->generate($uid, (int)$reopened['row_version']);

                return $this->suggestion('Late answer of A');
            }

            return $this->suggestion('Answer of B');
        });

        $item = $service->generate($uid, 0);

        self::assertSame([RemediationStatus::PENDING_REVIEW, 'Answer of B'], [$item['status'], $item['proposed_value']]);
    }

    #[Test]
    public function anOwnerChangeDuringGenerationKeepsTheClaimAndItsAnswer(): void
    {
        $service = $this->service();
        $uid = $this->prepared($service);
        $this->referenceAi->method('suggest')->willReturnCallback(function () use ($service, $uid): array {
            $service->assignOwner('main', 3, 'structured.file_reference_alt', 'developer');

            return $this->suggestion('Bar chart');
        });

        $item = $service->generate($uid, 0);

        self::assertSame([RemediationStatus::PENDING_REVIEW, 'Bar chart', 'developer'], [$item['status'], $item['proposed_value'], $item['owner']]);
    }

    #[Test]
    public function anImageTextTheStorageRefusesIsAnInvalidValueNotAServerError(): void
    {
        $this->altValidatorRefuses = true;
        [$service, $uid] = $this->pending('Bar chart');
        $service->markViewed($uid, 1);

        try {
            $service->approve($uid, $this->version($uid), false);
            self::fail('A value the validator refuses must not be applied.');
        } catch (RemediationException $exception) {
            self::assertSame('invalid_value', $exception->safeCode);
        }
    }

    #[Test]
    public function approvalRequiresThatThisPersonDisplayedThisExactVersion(): void
    {
        [$service, $uid] = $this->pending('Portrait of Jana Mayer');

        try {
            $service->approve($uid, $this->version($uid), false);
            self::fail('Approving without having seen the change must be refused.');
        } catch (RemediationException $exception) {
            self::assertSame('not_reviewed', $exception->safeCode);
        }
        $service->markViewed($uid, 1);
        $edited = $service->editValue($uid, $this->version($uid), 'Portrait of Jana Mayer, head of service');
        self::assertSame(2, $edited['proposal_version']);
        self::assertTrue($this->items->hasSeen($uid, self::USER, 2), 'Typing the value is seeing it.');
    }

    #[Test]
    public function approvingAnImageWritesThroughTheExistingPathOnceAndVerifiesTheStoredRecord(): void
    {
        [$service, $uid] = $this->pending('Bar chart');
        $service->markViewed($uid, 1);
        $this->imageRemediation->expects(self::once())->method('applyAlt')->with(41, 'Bar chart', 'token')->willReturnCallback($this->writer());
        $this->referenceAi->method('verify')->willReturn(['items' => [['referenceUid' => 88, 'status' => 'verified']]]);

        $item = $service->approve($uid, $this->version($uid), false);

        self::assertSame(RemediationStatus::VERIFIED, $item['status']);
        self::assertSame(self::USER, $item['reviewed_by']);
        self::assertSame(self::USER, $item['applied_by']);
        self::assertContains('saved', $this->items->actions($uid));
        self::assertContains('verified', $this->items->actions($uid));

        // A repeated submission (double click, second tab) changes nothing and never writes twice.
        try {
            $service->approve($uid, (int)$item['row_version'], false);
            self::fail('A verified item must not be applied again.');
        } catch (RemediationException $exception) {
            self::assertSame('already_done', $exception->safeCode);
        }
        // Repeated verification does not count again.
        $before = count($this->items->events);
        self::assertSame(RemediationStatus::VERIFIED, $service->refresh($uid)['status']);
        self::assertSame($before, count($this->items->events));
    }

    #[Test]
    public function twoReviewersApproveTheSameRowVersionButOnlyOneWrites(): void
    {
        [$service, $uid] = $this->pending('Bar chart');
        $service->markViewed($uid, 1);
        $version = $this->version($uid);
        $this->imageRemediation->expects(self::once())->method('applyAlt')->willReturnCallback($this->writer());
        $this->referenceAi->method('verify')->willReturn(['items' => [['referenceUid' => 88, 'status' => 'verified']]]);

        $service->approve($uid, $version, false);
        try {
            $service->approve($uid, $version, false);
            self::fail('The second approval of the same row version must be refused.');
        } catch (RemediationException $exception) {
            self::assertContains($exception->safeCode, ['already_done', 'changed_concurrently']);
        }
    }

    #[Test]
    public function aChangedTargetIsAConflictAndNothingIsWritten(): void
    {
        [$service, $uid] = $this->pending('Bar chart');
        $service->markViewed($uid, 1);
        // Somebody saved another alternative text after the suggestion was made.
        $this->storedReference['alternative'] = 'Chart water prices';
        $this->imageRemediation->expects(self::never())->method('applyAlt');

        $item = $service->approve($uid, $this->version($uid), false);

        self::assertSame(RemediationStatus::CONFLICT, $item['status']);
        self::assertSame('target_changed', $item['status_reason']);
    }

    #[Test]
    public function aFailedWriteIsRetriedOnceWithoutDuplicatingIt(): void
    {
        [$service, $uid] = $this->pending('Bar chart');
        $service->markViewed($uid, 1);
        $this->imageRemediation->expects(self::once())->method('applyAlt')->willThrowException(new ImageRemediationWriteException('image_update_failed'));
        $failed = $service->approve($uid, $this->version($uid), false);
        self::assertSame(RemediationStatus::FAILED, $failed['status']);
        self::assertSame('write_failed', $failed['status_reason']);

        // The value reached the record meanwhile (e.g. the first write did commit): the retry records it, writes nothing.
        $this->storedReference['alternative'] = 'Bar chart';
        $this->referenceAi->method('verify')->willReturn(['items' => [['referenceUid' => 88, 'status' => 'verified']]]);
        $retried = $service->approve($uid, (int)$failed['row_version'], true);
        self::assertSame(RemediationStatus::VERIFIED, $retried['status']);
    }

    #[Test]
    public function agentItemsAndOtherWorkspacesAreNeverApplied(): void
    {
        [$service, $uid] = $this->pending('Bar chart');
        $service->markViewed($uid, 1);
        $this->imageRemediation->expects(self::never())->method('applyAlt');

        $this->applyRefusal = 'agent_approval_disabled';
        try {
            $service->approve($uid, $this->version($uid), false);
            self::fail('Agent-originated approval is not authorized.');
        } catch (RemediationException $exception) {
            self::assertSame('agent_approval_disabled', $exception->safeCode);
        }
        $this->applyRefusal = '';
        $this->workspace = 3;
        try {
            $service->approve($uid, $this->version($uid), false);
            self::fail('Another workspace must not apply this item.');
        } catch (RemediationException $exception) {
            self::assertSame('not_found', $exception->safeCode, 'Items of another workspace are not even visible.');
        }
    }

    #[Test]
    public function inAWorkspaceAqgNeverWritesAnImageTextItself(): void
    {
        $this->workspace = 2;
        [$service, $uid] = $this->pending('Bar chart');
        $service->markViewed($uid, 1);
        $this->imageRemediation->expects(self::never())->method('applyAlt');

        try {
            $service->approve($uid, $this->version($uid), false);
            self::fail('A workspace image item must be applied in the record.');
        } catch (RemediationException $exception) {
            self::assertSame('workspace_apply_in_record', $exception->safeCode);
        }
        self::assertSame(RemediationStatus::PENDING_REVIEW, $this->items->findByUid($uid)['status']);
    }

    #[Test]
    public function aFindingThatNowNamesAnotherImageUseIsAConflictAndNothingIsWritten(): void
    {
        [$service, $uid] = $this->pending('Bar chart');
        $service->markViewed($uid, 1);
        $this->imageRemediation->expects(self::never())->method('applyAlt');
        // A later scan reused the finding row for another reference of the same field.
        $this->contextReference = 89;

        self::assertSame(RemediationStatus::CONFLICT, $service->approve($uid, $this->version($uid), false)['status']);
    }

    #[Test]
    public function aFindingWithAnotherFingerprintIsAConflict(): void
    {
        [$service, $uid] = $this->pending('Bar chart');
        $service->markViewed($uid, 1);
        $this->imageRemediation->expects(self::never())->method('applyAlt');
        $this->issue['fingerprint'] = 'another';

        self::assertSame(RemediationStatus::CONFLICT, $service->approve($uid, $this->version($uid), false)['status']);
    }

    #[Test]
    public function editingNeedsTheRightToApplyNotOnlyVisibility(): void
    {
        [$service, $uid] = $this->pending('Bar chart');
        $this->applyRefusal = 'no_edit_access';

        try {
            $service->editValue($uid, $this->version($uid), 'Changed by someone without edit access');
            self::fail('Editing the reviewed text needs the apply rights.');
        } catch (RemediationException $exception) {
            self::assertSame('no_edit_access', $exception->safeCode);
        }
    }

    #[Test]
    public function reopeningNeedsEditAccessToTheTargetBecauseTheItemHoldsTheFindingAgain(): void
    {
        [$service, $uid] = $this->pending('Bar chart');
        $rejected = $service->reject($uid, $this->version($uid), 'none');
        $this->targetEditable = false;

        try {
            $service->reopen($uid, (int)$rejected['row_version']);
            self::fail('A user who may not edit the record cannot reopen the item.');
        } catch (RemediationException $exception) {
            self::assertSame('no_edit_access', $exception->safeCode);
        }
        self::assertSame(RemediationStatus::REJECTED, $this->items->findByUid($uid)['status']);
    }

    #[Test]
    public function aReopenedItemNeedsAFreshViewBeforeApproval(): void
    {
        [$service, $uid] = $this->pending('Bar chart');
        $service->markViewed($uid, 1);
        $rejected = $service->reject($uid, $this->version($uid), 'none');
        $reopened = $service->reopen($uid, (int)$rejected['row_version']);

        self::assertSame(2, $reopened['proposal_version']);
        $this->expectException(RemediationException::class);
        $service->approve($uid, (int)$reopened['row_version'], false);
    }

    #[Test]
    public function aStalePreparationAndItemsOfOtherWorkspacesDoNotBlockAFinding(): void
    {
        $service = $this->service();
        $first = $this->prepared($service);
        $this->items->items[$first]['tstamp'] = time() - 2 * 86400;

        $second = $this->prepared($service);
        self::assertNotSame($first, $second);
        self::assertSame(RemediationStatus::NOT_APPLICABLE, $this->items->findByUid($first)['status']);
        self::assertSame('expired', $this->items->findByUid($first)['status_reason']);

        $this->workspace = 4;
        self::assertNotSame($second, $this->prepared($service), 'A live item does not hold the finding in a workspace.');
    }

    #[Test]
    public function aStillReportedFindingIsSavedButNotVerified(): void
    {
        [$service, $uid] = $this->pending('logo');
        $service->markViewed($uid, 1);
        $this->referenceAi->method('verify')->willReturn(['items' => [['referenceUid' => 88, 'status' => 'still_reported']]]);
        $this->imageRemediation->method('applyAlt')->willReturnCallback($this->writer());

        $item = $service->approve($uid, $this->version($uid), false);

        self::assertSame(RemediationStatus::NOT_VERIFIED, $item['status']);
        self::assertSame('still_reported', $item['status_reason']);
    }

    #[Test]
    public function aSavedImageItemIsVerifiedOnlyWhileTheStoredReferenceHoldsTheApprovedValue(): void
    {
        [$service, $uid] = $this->pending('The exact description that was approved');
        $service->markViewed($uid, 1);
        $this->referenceAi->method('verify')->willReturnOnConsecutiveCalls(
            ['items' => [['referenceUid' => 88, 'status' => 'still_reported']]],
            ['items' => [['referenceUid' => 88, 'status' => 'verified']]],
        );
        $this->imageRemediation->method('applyAlt')->willReturnCallback($this->writer());
        self::assertSame(RemediationStatus::NOT_VERIFIED, $service->approve($uid, $this->version($uid), false)['status']);

        // A colleague fixes the image with another text: the check now passes, but not with this proposal.
        $this->storedReference['alternative'] = 'A different description saved by a colleague';
        $item = $service->refresh($uid);
        self::assertSame([RemediationStatus::NOT_VERIFIED, 'value_changed'], [$item['status'], $item['status_reason']]);
        self::assertNotContains('verified', $this->items->actions($uid));
        $events = count($this->items->events);
        $service->refresh($uid);
        self::assertSame($events, count($this->items->events), 'A repeated check of the same state records nothing.');

        // The next scan closes the finding: the item leaves the queue as solved differently, never as Verified.
        $this->issue['status'] = 1;
        $item = $service->refresh($uid);
        self::assertSame([RemediationStatus::NOT_APPLICABLE, 'solved_differently'], [$item['status'], $item['status_reason']]);
        self::assertSame(0, (int)$item['verified_at']);
    }

    #[Test]
    public function aClosedFindingIsNoProofOfAnotherFixWhileTheImageStillFails(): void
    {
        [$service, $uid] = $this->pending('Bar chart');
        $service->markViewed($uid, 1);
        $this->referenceAi->method('verify')->willReturnOnConsecutiveCalls(
            ['items' => [['referenceUid' => 88, 'status' => 'still_reported']]],
            ['items' => [['referenceUid' => 88, 'status' => 'still_reported']]],
        );
        $this->imageRemediation->method('applyAlt')->willReturnCallback($this->writer());
        self::assertSame(RemediationStatus::NOT_VERIFIED, $service->approve($uid, $this->version($uid), false)['status']);

        // Applying closed the finding row itself; later the text is removed again.
        $this->issue['status'] = 2;
        $this->storedReference['alternative'] = '';
        $item = $service->refresh($uid);

        self::assertSame([RemediationStatus::NOT_VERIFIED, 'value_changed'], [$item['status'], $item['status_reason']]);
    }

    #[Test]
    public function aDecorativeFlagAnotherFileOrAMissingReferenceIsNotThisProposal(): void
    {
        foreach ([
            [['tx_a11y_is_decorative' => 1], 'value_changed'],
            [['uid_local' => 6], 'target_changed'],
            [null, 'target_changed'],
        ] as [$change, $reason]) {
            $this->setUp();
            [$service, $uid] = $this->pending('Bar chart');
            $service->markViewed($uid, 1);
            $this->referenceAi->method('verify')->willReturn(['items' => [['referenceUid' => 88, 'status' => 'verified']]]);
            $this->imageRemediation->method('applyAlt')->willReturnCallback($this->writer());
            self::assertSame(RemediationStatus::VERIFIED, $service->approve($uid, $this->version($uid), false)['status']);

            // The same change on an item that was saved but not verified yet.
            $this->items->items[$uid] = ['status' => RemediationStatus::SAVED, 'verified_at' => 0] + $this->items->items[$uid];
            $this->storedReference = $change === null ? [] : $change + $this->storedReference;
            $item = $service->refresh($uid);

            self::assertSame([RemediationStatus::NOT_VERIFIED, $reason], [$item['status'], $item['status_reason']], (string)$reason);
        }
    }

    #[Test]
    public function aFindingClosedMeanwhileMakesTheItemNotApplicable(): void
    {
        [$service, $uid] = $this->pending('Bar chart');
        $service->markViewed($uid, 1);
        $this->issue['status'] = 1;
        $this->imageRemediation->expects(self::never())->method('applyAlt');

        self::assertSame(RemediationStatus::NOT_APPLICABLE, $service->approve($uid, $this->version($uid), false)['status']);
    }

    #[Test]
    public function richTextIsApprovedForTheEditorAndSavedOnlyByTheStoredRecord(): void
    {
        $this->issue = ['uid' => 50, 'status' => 0, 'page_uid' => 3, 'site_identifier' => 'main', 'source_lang_uid' => 0, 'fingerprint' => 'f2',
            'rule_id' => 'rte.non_descriptive_link', 'severity' => 2, 'source_type' => 'rte', 'context_path' => 'p[1] > a', 'context_snippet' => '<a href="/x">click here</a>',
            'source_table' => 'tt_content', 'source_uid' => 12, 'source_field' => 'bodytext'];
        $this->target = new RemediationTarget(RemediationTargetKind::RICH_TEXT, 'tt_content', 12, 'bodytext', ['contextPath' => 'p[1] > a', 'occurrence' => 0, 'tag' => 'a', 'total' => 1], 'click here', 'rte-hash', 'link_text');
        $service = $this->service();
        $this->editorAi->method('locateStoredFinding')->willReturn(['record' => null, 'target' => $this->createMock(\Priebera\A11yQualityGate\Ai\Editor\EditorFindingTarget::class), 'html' => '<p><a href="/x">click here</a></p>', 'tstamp' => 1]);
        $this->editorAi->method('fix')->willReturn($this->suggestion('Download the tariff sheet'));
        $this->editorAi->method('expectedSignature')->willReturn(['ruleId' => 'rte.non_descriptive_link', 'tag' => 'a', 'attributes' => ['href' => '/x'], 'text' => 'Download the tariff sheet', 'occurrence' => 0, 'total' => 1]);
        $uid = $this->prepared($service, 50);
        $service->generate($uid, 0);
        $service->markViewed($uid, 1);

        $approved = $service->approve($uid, $this->version($uid), false);
        self::assertSame(RemediationStatus::APPROVED, $approved['status'], 'Approval is not a save.');
        $expected = json_decode((string)$approved['target_descriptor'], true)['expected'];
        // The editor's save normalizes the HTML (CKEditor drops empty links): the saved element is proven by its
        // attributes and text, never by a position counted in the stored HTML (found in the real Anthropic E2E run).
        self::assertSame([-1, -1], [$expected['occurrence'], $expected['total']]);
        self::assertSame('Download the tariff sheet', $expected['text']);
        $this->imageRemediation->expects(self::never())->method('applyAlt');

        $staged = $service->staged($uid, 'tt_content', 12, 'bodytext');
        self::assertSame('Download the tariff sheet', $staged['value']);
        self::assertSame(RemediationStatus::IN_EDITOR, $this->items->findByUid($uid)['status']);

        // The editor has not saved yet: the stored record does not hold the element.
        $this->editorAi->method('verify')->willReturnOnConsecutiveCalls(['saved' => false, 'results' => ['not_saved']], ['saved' => true, 'results' => ['verified']]);
        self::assertSame(RemediationStatus::IN_EDITOR, $service->refresh($uid)['status']);
        // After TYPO3 Save the AQG check of the stored record decides.
        self::assertSame(RemediationStatus::VERIFIED, $service->refresh($uid)['status']);
    }

    #[Test]
    public function oneSavedElementVerifiesOnlyOneOfTwoItemsExpectingIt(): void
    {
        // Two identical "click here" links of one field, both approved with the same text; only one is saved.
        $this->target = new RemediationTarget(RemediationTargetKind::RICH_TEXT, 'tt_content', 12, 'bodytext', ['contextPath' => 'p[1] > a', 'occurrence' => 0, 'tag' => 'a', 'total' => 2], 'click here', 'rte-hash', 'link_text');
        $service = $this->service();
        $this->editorAi->method('locateStoredFinding')->willReturn(['record' => null, 'target' => $this->createMock(\Priebera\A11yQualityGate\Ai\Editor\EditorFindingTarget::class), 'html' => '<p><a href="/x">click here</a> <a href="/x">click here</a></p>', 'tstamp' => 1]);
        $this->editorAi->method('fix')->willReturn($this->suggestion('Download the tariff sheet'));
        $this->editorAi->method('expectedSignature')->willReturn(['ruleId' => 'rte.non_descriptive_link', 'tag' => 'a', 'attributes' => ['href' => '/x'], 'text' => 'Download the tariff sheet', 'occurrence' => 0, 'total' => 2, 'before' => 0]);
        // The stored record holds exactly one element like the approved one.
        $this->editorAi->method('verify')->willReturnCallback(static fn (string $table, int $uid, string $field, array $items): array => ['saved' => true, 'results' => [1 > (int)$items[0]['before'] ? 'verified' : 'not_found']]);
        $uids = [];
        foreach ([50, 51] as $findingUid) {
            $this->issue = ['uid' => $findingUid, 'status' => 0, 'page_uid' => 3, 'site_identifier' => 'main', 'source_lang_uid' => 0, 'fingerprint' => 'f' . $findingUid,
                'rule_id' => 'rte.non_descriptive_link', 'severity' => 2, 'source_type' => 'rte', 'context_path' => 'p[1] > a', 'context_snippet' => '<a href="/x">click here</a>',
                'source_table' => 'tt_content', 'source_uid' => 12, 'source_field' => 'bodytext'];
            $uid = $this->prepared($service, $findingUid);
            $service->generate($uid, 0);
            $service->markViewed($uid, 1);
            self::assertSame(RemediationStatus::APPROVED, $service->approve($uid, $this->version($uid), false)['status']);
            $uids[] = $uid;
        }

        self::assertSame(RemediationStatus::VERIFIED, $service->refresh($uids[0])['status']);
        // The one saved element is already the first item's evidence.
        self::assertSame(RemediationStatus::APPROVED, $service->refresh($uids[1])['status']);

        // A save from before the second item's approval is part of that item's 'before' count: it claims nothing.
        $first = $this->items->findByUid($uids[0]);
        $this->items->transition($uids[0], (int)$first['row_version'], [RemediationStatus::VERIFIED], RemediationStatus::VERIFIED, ['saved_at' => (int)$this->items->findByUid($uids[1])['reviewed_at'] - 60]);
        self::assertSame(RemediationStatus::VERIFIED, $service->refresh($uids[1])['status']);
    }

    #[Test]
    public function aStagedChangeIsOnlyHandedToItsOwnRecordAndField(): void
    {
        $this->target = new RemediationTarget(RemediationTargetKind::RICH_TEXT, 'tt_content', 12, 'bodytext', ['contextPath' => 'p[1] > a', 'occurrence' => 0, 'tag' => 'a', 'total' => 1], 'click here', 'rte-hash', 'link_text');
        $this->issue['source_type'] = 'rte';
        $service = $this->service();
        $uid = $this->prepared($service);

        try {
            $service->staged($uid, 'tt_content', 13, 'bodytext');
            self::fail('Another record must not receive the staged change.');
        } catch (RemediationException $exception) {
            self::assertSame('not_this_field', $exception->safeCode);
        }
    }

    #[Test]
    public function rejectingKeepsTheFindingOpenAndCanBeReopened(): void
    {
        [$service, $uid] = $this->pending('Wrong');
        $rejected = $service->reject($uid, $this->version($uid), 'factually_wrong');
        self::assertSame(RemediationStatus::REJECTED, $rejected['status']);
        self::assertSame('reject_factually_wrong', $rejected['status_reason']);

        $reopened = $service->reopen($uid, (int)$rejected['row_version']);
        self::assertSame(RemediationStatus::PENDING_REVIEW, $reopened['status']);
    }

    #[Test]
    public function aStaleScreenGetsTheCurrentStateInsteadOfAWrite(): void
    {
        [$service, $uid] = $this->pending('Bar chart');
        $service->markViewed($uid, 1);
        $stale = $this->version($uid);
        $service->editValue($uid, $stale, 'Bar chart 2026');

        try {
            $service->reject($uid, $stale, 'none');
            self::fail('A stale row version must be refused.');
        } catch (RemediationException $exception) {
            self::assertSame('changed_concurrently', $exception->safeCode);
            self::assertSame($this->version($uid), $exception->data['rowVersion']);
        }
    }

    #[Test]
    public function generationFailuresOfTheEditorPathAreReportedWithTheirCode(): void
    {
        $this->target = new RemediationTarget(RemediationTargetKind::RICH_TEXT, 'tt_content', 12, 'bodytext', ['contextPath' => 'p[1] > a', 'occurrence' => 0, 'tag' => 'a', 'total' => 1], 'click here', 'rte-hash', 'link_text');
        $service = $this->service();
        $this->editorAi->method('locateStoredFinding')->willReturn(['record' => null, 'target' => $this->createMock(\Priebera\A11yQualityGate\Ai\Editor\EditorFindingTarget::class), 'html' => '<p>x</p>', 'tstamp' => 1]);
        $this->editorAi->method('fix')->willThrowException(new EditorAiException('link_text_disabled', 'disabled', 422));

        $item = $service->generate($this->prepared($service), 0);

        self::assertSame(RemediationStatus::FAILED, $item['status']);
        self::assertSame('link_text_disabled', $item['status_reason']);
    }

    /** AQG-300-UX-04: Page Detail "Explain with AI" goes through the editor's explain path and queues nothing. */
    #[Test]
    public function explainLocatesTheStoredFindingAndUsesTheEditorExplainPathWithoutQueueingAnything(): void
    {
        $this->issue = $this->richTextIssue();
        $service = $this->service();
        $this->editorAi->expects(self::once())->method('locateStoredFinding')
            ->with('tt_content', 40, 'bodytext', 'rte.empty_heading', 'html/body/h4[1]', '<h4></h4>')
            ->willReturn($this->located());
        $this->editorAi->expects(self::once())->method('explain')->with([
            'table' => 'tt_content', 'uid' => 40, 'field' => 'bodytext', 'html' => '<h4></h4>',
            'ruleId' => 'rte.empty_heading', 'contextPath' => 'html/body/h4[1]', 'occurrence' => 0,
        ])->willReturn(['status' => 'explanation', 'explanation' => 'The heading is empty.']);
        $this->editorAi->expects(self::never())->method('fix');

        self::assertSame('explanation', $service->explain(42)['status']);
        self::assertSame([], $this->items->items);
        self::assertSame([], $this->items->events);
    }

    /** @return iterable<string,array{0:string,1:string}> */
    public static function explainRefusalProvider(): iterable
    {
        yield 'page not readable' => ['unreadable', 'not_found'];
        yield 'closed finding' => ['closed', 'finding_closed'];
        yield 'no licence' => ['nolicence', 'no_licence'];
        yield 'rendered output' => ['rendered', 'unsupported_rule'];
        yield 'rule without AI help (form label)' => ['formlabel', 'unsupported_rule'];
        yield 'AI not ready for the site' => ['notready', 'not_configured'];
        yield 'content changed since the scan' => ['stale', 'stale'];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('explainRefusalProvider')]
    public function explainRefusesWithoutAProviderRequest(string $case, string $code): void
    {
        $this->issue = $this->richTextIssue();
        match ($case) {
            'unreadable' => $this->pageReadable = false,
            'closed' => $this->issue['status'] = 2,
            'nolicence' => $this->licence = false,
            'rendered' => $this->issue['source_type'] = 'rendered',
            'formlabel' => $this->issue['rule_id'] = 'rte.form_control_missing_label',
            'notready' => $this->aiRefusal = 'not_configured',
            default => null,
        };
        $service = $this->service();
        $this->editorAi->method('locateStoredFinding')->willReturn($case === 'stale' ? null : $this->located());
        $this->editorAi->expects(self::never())->method('explain');

        try {
            $service->explain(42);
            self::fail('The explanation must be refused.');
        } catch (RemediationException $exception) {
            self::assertSame($code, $exception->safeCode);
        }
    }

    /** @return array<string,mixed> */
    private function richTextIssue(): array
    {
        return [
            'uid' => 42, 'status' => 0, 'page_uid' => 39, 'site_identifier' => 'main', 'source_lang_uid' => 0, 'fingerprint' => 'fp-h',
            'rule_id' => 'rte.empty_heading', 'severity' => 3, 'source_type' => 'rte', 'context_path' => 'html/body/h4[1]',
            'context_snippet' => '<h4></h4>', 'source_table' => 'tt_content', 'source_uid' => 40, 'source_field' => 'bodytext',
        ];
    }

    /** @return array{record:EditorAiRecordContext,target:EditorFindingTarget,html:string,tstamp:int} */
    private function located(): array
    {
        return [
            'record' => new EditorAiRecordContext('tt_content', 40, 'bodytext', 39, 'main', 0, 'en-GB', 'English', 'en-GB', 0, false, 'html'),
            'target' => new EditorFindingTarget('rte.empty_heading', 'heading_text', 'text', '', 'h4', 0, 1, 'html/body/h4[1]', [], '', false, ''),
            'html' => '<h4></h4>',
            'tstamp' => 1,
        ];
    }

    private function service(): RemediationWorkflowService
    {
        $issues = $this->createMock(IssueRemediationRepositoryInterface::class);
        $issues->method('findByUid')->willReturnCallback(fn (int $uid): ?array => $uid === (int)$this->issue['uid'] ? $this->issue : null);

        $this->targetResolver = $this->createMock(RemediationTargetResolver::class);
        $this->targetResolver->method('resolve')->willReturnCallback(fn (): RemediationTarget => $this->target);

        $access = $this->createMock(RemediationAccessService::class);
        $access->method('userUid')->willReturn(self::USER);
        $access->method('canReadPage')->willReturnCallback(fn (): bool => $this->pageReadable);
        $access->method('canEditPage')->willReturn(true);
        $access->method('canReadSiteIdentifier')->willReturn(true);
        $access->method('hasLicence')->willReturnCallback(fn (): bool => $this->licence);
        $access->method('prepareRefusal')->willReturnCallback(fn (): string => $this->aiRefusal);
        $access->method('mayUseBulk')->willReturnCallback(fn (): bool => $this->mayUseBulk);
        $access->method('currentWorkspace')->willReturnCallback(fn (): int => $this->workspace);
        $access->method('canSee')->willReturnCallback(fn (array $item): bool => (int)$item['workspace_id'] === $this->workspace && ($this->canSeeOthers || (int)$item['created_by'] === self::USER));
        $access->method('applyRefusal')->willReturnCallback(fn (): string => $this->applyRefusal);
        $access->method('canEditTarget')->willReturnCallback(fn (): bool => $this->targetEditable);

        $this->referenceAi = $this->createMock(ReferenceAltAiService::class);
        $this->editorAi = $this->createMock(EditorAiRemediationService::class);
        $valueValidator = $this->createMock(EditorAiValueValidator::class);
        $valueValidator->method('validate')->willReturnCallback(static fn (string $kind, string $value): array => [$value, EditorAiValueValidator::VALID]);
        $altValidator = $this->createMock(ImageAltTextValidator::class);
        $altValidator->method('validate')->willReturnCallback(function (string $value): string {
            if ($this->altValidatorRefuses) {
                // What ImageAltTextValidator throws: the base class, not ImageRemediationValidationException.
                throw new \InvalidArgumentException('Alternative text is too long.', 1771001202);
            }

            return $value;
        });
        $this->imageRemediation = $this->createMock(ImageRemediationServiceInterface::class);
        $imageResolver = $this->createMock(ImageFindingContextResolver::class);
        $imageResolver->method('resolve')->willReturnCallback(fn (): ImageFindingContext => new ImageFindingContext($this->issue, [], 'main', 3, 0, 'tt_content', 9, 'image', $this->contextReference, 5, 'fp', 1, 1));
        $tokens = $this->createMock(ImageFindingVersionTokenService::class);
        $tokens->method('create')->willReturn('token');
        $metadata = $this->createMock(RuleMetadataPresentationServiceInterface::class);
        $metadata->method('present')->willReturn(['owner' => 'editor']);

        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturnCallback(fn (): array|false => $this->storedReference === [] ? false : $this->storedReference);
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($this->createMock(AbstractPlatform::class));
        $connection->method('executeQuery')->willReturn($result);
        $connection->method('transactional')->willReturnCallback(fn (callable $work): mixed => $work($connection));
        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);

        // NEW-09: preparation runs under a per-finding database mutex; here it is always granted (its own behaviour is
        // covered by RemediationPrepareConcurrencyFunctionalTest on a real database).
        $mutex = $this->createMock(\Priebera\A11yQualityGate\Concurrency\DatabaseMutex::class);
        $mutex->method('acquire')->willReturn('test-token');

        return new RemediationWorkflowService($this->items, $issues, $this->targetResolver, $access, $this->referenceAi, $this->editorAi, $valueValidator, $altValidator, $this->imageRemediation, $imageResolver, $tokens, $metadata, $pool, $mutex);
    }

    /** The existing write path as a stand-in: it stores the value on the reference and returns its context. */
    private function writer(): \Closure
    {
        return function (int $finding, string $value): ImageFindingContext {
            $this->storedReference['alternative'] = $value;
            $this->storedReference['tx_a11y_is_decorative'] = 0;

            return new ImageFindingContext($this->issue, [], 'main', 3, 0, 'tt_content', 9, 'image', 88, 5, 'fp', 1, 1);
        };
    }

    private function imageTarget(string $alternative): RemediationTarget
    {
        return new RemediationTarget(
            RemediationTargetKind::IMAGE_REFERENCE_ALT,
            'sys_file_reference',
            88,
            'alternative',
            ['parentTable' => 'tt_content', 'parentUid' => 9, 'fieldName' => 'image', 'referenceUid' => 88, 'fileUid' => 5],
            $alternative,
            RemediationTargetResolver::imageBaseHash($alternative, '0', '5'),
            'image_alt',
        );
    }

    /** @return array<string,mixed> */
    private function suggestion(string $value): array
    {
        return ['status' => 'suggestion', 'value' => $value, 'reason' => 'From the element text.', 'provider' => 'anthropic', 'model' => 'claude-test',
            'promptVersion' => 'v1', 'usage' => ['inputTokens' => 120, 'outputTokens' => 12], 'sent' => ['image']];
    }

    private function prepared(RemediationWorkflowService $service, int $findingUid = 41): int
    {
        return (int)$service->prepare([$findingUid], RemediationAccessService::ORIGIN_EDITOR_REQUEST)['items'][0]['itemUid'];
    }

    /** @return array{0:RemediationWorkflowService,1:int} */
    private function pending(string $value): array
    {
        $service = $this->service();
        $this->referenceAi->method('suggest')->willReturn($this->suggestion($value));
        $uid = $this->prepared($service);
        $service->generate($uid, 0);

        return [$service, $uid];
    }

    private function version(int $uid): int
    {
        return (int)$this->items->findByUid($uid)['row_version'];
    }
}
