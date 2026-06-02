<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Scheduler;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Scheduler\A11yScanTask;

final class A11yScanTaskTest extends TestCase
{
    #[Test]
    public function setTaskParametersMapsNativeTcaFields(): void
    {
        $task = self::createTask();

        $task->setTaskParameters([
            A11yScanTask::PARAM_PAGE_UID => 123,
            A11yScanTask::PARAM_ROOT_PID => 456,
            A11yScanTask::PARAM_DEPTH => 7,
            A11yScanTask::PARAM_LANGUAGE_UID => 2,
            A11yScanTask::PARAM_CHANGED_ONLY => true,
        ]);

        self::assertSame(123, $task->pageUid);
        self::assertSame(456, $task->rootPid);
        self::assertSame(7, $task->depth);
        self::assertSame(2, $task->languageUid);
        self::assertTrue($task->changedOnly);
    }

    #[Test]
    public function setTaskParametersMapsLegacyAdditionalFieldProviderFields(): void
    {
        $task = self::createTask();

        $task->setTaskParameters([
            'task_a11y_pageUid' => 11,
            'task_a11y_rootPid' => 22,
            'task_a11y_depth' => 3,
            'task_a11y_languageUid' => -1,
            'task_a11y_changedOnly' => '1',
        ]);

        self::assertSame(11, $task->pageUid);
        self::assertSame(22, $task->rootPid);
        self::assertSame(3, $task->depth);
        self::assertSame(-1, $task->languageUid);
        self::assertTrue($task->changedOnly);
    }

    #[Test]
    public function getTaskParametersWritesNativeTcaFields(): void
    {
        $task = self::createTask();
        $task->pageUid = 12;
        $task->rootPid = 34;
        $task->depth = 5;
        $task->languageUid = 1;
        $task->changedOnly = true;

        self::assertSame([
            A11yScanTask::PARAM_PAGE_UID => 12,
            A11yScanTask::PARAM_ROOT_PID => 34,
            A11yScanTask::PARAM_DEPTH => 5,
            A11yScanTask::PARAM_LANGUAGE_UID => 1,
            A11yScanTask::PARAM_CHANGED_ONLY => true,
        ], $task->getTaskParameters());
    }

    private static function createTask(): A11yScanTask
    {
        $reflection = new \ReflectionClass(A11yScanTask::class);

        /** @var A11yScanTask $task */
        $task = $reflection->newInstanceWithoutConstructor();

        return $task;
    }
}
