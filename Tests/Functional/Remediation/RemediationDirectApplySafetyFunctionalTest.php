<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Remediation;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationException;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationStatus;
use Priebera\A11yQualityGate\Remediation\Workflow\RichTextRecordWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Direct reviewed apply, the edges (AQG 3.0.0 review): a save between the review and the locked write, a save TYPO3 or a
 * hook changes, a record open in another editor, a finding re-keyed to another record, and an apply interrupted midway.
 */
final class RemediationDirectApplySafetyFunctionalTest extends AbstractDirectApplyFunctionalTestCase
{
    /** @var array{0:string,1:int,2:string,3:string,4:string}|null */
    public static ?array $planned = null;

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['aqgTestRewrite']);
        self::$planned = null;
        parent::tearDown();
    }

    #[Test]
    public function aSaveBetweenTheReviewAndTheLockedWriteIsAConflictAndKeepsThatSave(): void
    {
        $this->login(self::ADMIN);
        $this->answer = ['status' => 'suggestion', 'value' => 'Directions and parking', 'reason' => 'r'];
        $item = $this->generateAndView(10);
        $other = str_replace('kept</p>', 'kept, saved meanwhile</p>', self::CONTACT);
        $writer = new class (GeneralUtility::makeInstance(ConnectionPool::class), $other) extends RichTextRecordWriter {
            public function __construct(ConnectionPool $pool, private readonly string $other)
            {
                parent::__construct($pool);
            }

            public function write(string $table, int $uid, string $field, string $expected, string $value): string
            {
                GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable($table)->update($table, [$field => $this->other], ['uid' => $uid]);
                return parent::write($table, $uid, $field, $expected, $value);
            }
        };

        $result = $this->workflow($writer)->approve((int)$item['uid'], (int)$item['row_version'], false, '', true);

        self::assertSame(RemediationStatus::CONFLICT, $result['status']);
        self::assertSame($other, $this->bodytext(10), 'The other save is kept; nothing of the change is written.');
    }

    #[Test]
    public function aSaveThatTypo3OrAHookChangedIsRolledBackAndReportedAsNotWritten(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']['aqgTestRewrite'] = RewritingDatamapHook::class;
        $this->login(self::ADMIN);
        $this->answer = ['status' => 'suggestion', 'value' => 'Directions and parking', 'reason' => 'r'];
        $item = $this->generateAndView(10);

        $result = $this->workflow()->approve((int)$item['uid'], (int)$item['row_version'], false, '', true);

        self::assertSame(RemediationStatus::FAILED, $result['status']);
        self::assertSame(RichTextRecordWriter::WRITE_FAILED, $result['status_reason']);
        self::assertSame(self::CONTACT, $this->bodytext(10), 'The changed save was rolled back: the record is as it was.');
    }

    #[Test]
    public function aRecordOpenInAnotherEditorGetsTheChangeInTheEditorInstead(): void
    {
        $this->login(self::ADMIN);
        $this->answer = ['status' => 'suggestion', 'value' => 'Directions and parking', 'reason' => 'r'];
        $item = $this->generateAndView(10);
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_lockedrecords')->insert('sys_lockedrecords', [
            'userid' => self::EDITOR, 'username' => 'editor', 'tstamp' => time(), 'record_table' => 'tt_content', 'record_uid' => 10, 'record_pid' => 1,
        ]);

        $result = $this->workflow()->approve((int)$item['uid'], (int)$item['row_version'], false, '', true);

        self::assertSame(RemediationStatus::APPROVED, $result['status']);
        self::assertContains('reason:record_open', $this->eventDetails((int)$item['uid']));
        self::assertSame(self::CONTACT, $this->bodytext(10));
    }

    #[Test]
    public function aFindingReKeyedToAnotherRecordWithTheSameElementIsAConflictNotAWriteThere(): void
    {
        $this->login(self::ADMIN);
        $this->answer = ['status' => 'suggestion', 'value' => 'Directions and parking', 'reason' => 'r'];
        $item = $this->generateAndView(10);
        $connections = GeneralUtility::makeInstance(ConnectionPool::class);
        $connections->getConnectionForTable('tt_content')->insert('tt_content', ['uid' => 31, 'pid' => 1, 'CType' => 'text', 'header' => 'Copy', 'bodytext' => self::CONTACT, 'tstamp' => 1000]);
        $connections->getConnectionForTable('tx_a11y_issue')->update('tx_a11y_issue', ['source_uid' => 31], ['uid' => $this->findings[10]]);

        try {
            $result = $this->workflow()->approve((int)$item['uid'], (int)$item['row_version'], false, '', true);
            self::assertContains($result['status'], [RemediationStatus::CONFLICT, RemediationStatus::NOT_APPLICABLE]);
        } catch (RemediationException $exception) {
            self::assertContains($exception->httpStatus, [403, 404, 409]);
        }
        self::assertSame(self::CONTACT, $this->bodytext(31), 'The record nobody reviewed is untouched.');
        self::assertSame(self::CONTACT, $this->bodytext(10));
    }

    #[Test]
    public function anInterruptedApplyIsSavedOnlyWhenTheRecordHoldsTheAppliedElement(): void
    {
        $this->login(self::ADMIN);
        $this->answer = ['status' => 'suggestion', 'value' => 'Directions and parking', 'reason' => 'r'];
        $written = $this->generateAndView(10);
        $this->workflow(new PlanRecordingWriter(GeneralUtility::makeInstance(ConnectionPool::class)))->approve((int)$written['uid'], (int)$written['row_version'], false, '', true);
        self::assertNotNull(self::$planned);
        // The write happened, the request stopped before the item moved on.
        $reviewedAt = (int)$this->item((int)$written['uid'])['reviewed_at'];
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tt_content')->update('tt_content', ['bodytext' => self::$planned[4], 'tstamp' => $reviewedAt], ['uid' => 10]);
        $this->interrupt((int)$written['uid']);

        $recovered = $this->workflow()->refresh((int)$written['uid']);

        self::assertContains($recovered['status'], [RemediationStatus::SAVED, RemediationStatus::VERIFIED], (string)$recovered['status_reason']);
        self::assertContains('saved', $this->eventActions((int)$written['uid']));
    }

    #[Test]
    public function anInterruptedApplyThatWroteNothingFails(): void
    {
        $this->login(self::ADMIN);
        $this->answer = ['status' => 'suggestion', 'value' => 'Directions and parking', 'reason' => 'r'];
        $item = $this->generateAndView(10);
        $this->workflow(new PlanRecordingWriter(GeneralUtility::makeInstance(ConnectionPool::class)))->approve((int)$item['uid'], (int)$item['row_version'], false, '', true);
        $this->interrupt((int)$item['uid']);

        $result = $this->workflow()->refresh((int)$item['uid']);

        self::assertSame([RemediationStatus::FAILED, 'interrupted'], [$result['status'], $result['status_reason']]);
        self::assertSame(self::CONTACT, $this->bodytext(10));
    }

    private function interrupt(int $itemUid): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_remediation_item')
            ->update('tx_a11y_remediation_item', ['status' => RemediationStatus::APPLYING, 'tstamp' => time() - 3600], ['uid' => $itemUid]);
    }
}

/** Records what would be written and writes nothing (an apply that stops at the write). */
final class PlanRecordingWriter extends RichTextRecordWriter
{
    public function write(string $table, int $uid, string $field, string $expected, string $value): string
    {
        RemediationDirectApplySafetyFunctionalTest::$planned = [$table, $uid, $field, $expected, $value];
        return self::WRITE_FAILED;
    }
}

/** A DataHandler hook that changes the field on save (eval, a third-party hook…). */
final class RewritingDatamapHook
{
    /** @param array<string,mixed> $fieldArray */
    public function processDatamap_postProcessFieldArray(string $status, string $table, string|int $id, array &$fieldArray, DataHandler $dataHandler): void
    {
        if ($table === 'tt_content' && isset($fieldArray['bodytext'])) {
            $fieldArray['bodytext'] .= "\n<p>added by a hook</p>";
        }
    }
}
