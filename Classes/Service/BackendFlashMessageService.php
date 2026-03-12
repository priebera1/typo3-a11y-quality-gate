<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

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
        $this->getQueue()->enqueue(
            new FlashMessage(
                $message,
                $title,
                $severity,
                $storeInSession,
            )
        );
    }

    private function getQueue(): FlashMessageQueue
    {
        return $this->flashMessageService->getMessageQueueByIdentifier(self::QUEUE_IDENTIFIER);
    }
}
