<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Service\BackendFlashMessageService;
use TYPO3\CMS\Core\Core\ApplicationContext;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageQueue;
use TYPO3\CMS\Core\Messaging\FlashMessageService;

/**
 * A DataHandler run on the CLI (impexp import, deployment script, Scheduler) saved content, the Quality Gate hook
 * reported its findings as a session flash message, and the session-less _cli_ user ended the run with
 * "Call to a member function set() on null" after the records were already written.
 */
final class BackendFlashMessageServiceTest extends TestCase
{
    #[Test]
    public function messagesAreNotStoredInASessionOnTheCli(): void
    {
        Environment::initialize(new ApplicationContext('Testing'), true, true, '/var/www/html', '/var/www/html/public', '/var/www/html/var', '/var/www/html/config', '/var/www/html/vendor/bin/typo3', 'UNIX');

        $enqueued = [];
        $queue = $this->createMock(FlashMessageQueue::class);
        $queue->method('enqueue')->willReturnCallback(static function (FlashMessage $message) use (&$enqueued): void {
            $enqueued[] = $message;
        });
        $flashMessageService = $this->createMock(FlashMessageService::class);
        $flashMessageService->method('getMessageQueueByIdentifier')->willReturn($queue);

        (new BackendFlashMessageService($flashMessageService))->addMessage('This content element has 2 accessibility issue(s).');

        self::assertCount(1, $enqueued);
        self::assertFalse($enqueued[0]->isSessionMessage());
    }
}
