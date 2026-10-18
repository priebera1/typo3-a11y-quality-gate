<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageQueue;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

final class BackendFlashMessageService
{
    private const QUEUE_IDENTIFIER = 'core.template.flashMessages';

    public function __construct(
        private readonly FlashMessageService $flashMessageService,
    ) {
    }

    public function addMessage(
        string $message,
        ContextualFeedbackSeverity $severity = ContextualFeedbackSeverity::OK,
        string $title = '',
        bool $storeInSession = true,
    ): void {
        // The CLI backend user (_cli_: imports, deployments, Scheduler, `a11y:scan`) has no session; storing a message
        // there is a fatal error that aborts the whole DataHandler run after the records were written.
        $this->getQueue()->enqueue(
            new FlashMessage(
                $message,
                $title,
                $severity,
                $storeInSession && !Environment::isCli(),
            )
        );
    }

    private function getQueue(): FlashMessageQueue
    {
        return $this->flashMessageService->getMessageQueueByIdentifier(self::QUEUE_IDENTIFIER);
    }
}
