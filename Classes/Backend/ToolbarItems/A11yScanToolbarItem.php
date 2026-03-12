<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Backend\ToolbarItems;

use Priebera\A11yQualityGate\Service\AccessControlService;
use Priebera\A11yQualityGate\Service\ScanStatusService;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Toolbar\RequestAwareToolbarItemInterface;
use TYPO3\CMS\Backend\Toolbar\ToolbarItemInterface;
use TYPO3\CMS\Backend\View\BackendViewFactory;

final class A11yScanToolbarItem implements ToolbarItemInterface, RequestAwareToolbarItemInterface
{
    private ServerRequestInterface $request;

    /**
     * @var array<int, array<string, string>>
     */
    private array $toolbarInformation = [];

    public function __construct(
        private readonly BackendViewFactory $backendViewFactory,
        private readonly AccessControlService $accessControlService,
        private readonly ScanStatusService $scanStatusService,
    ) {
    }

    public function setRequest(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    public function checkAccess(): bool
    {
        return $this->accessControlService->canShowToolbarItem();
    }

    public function getItem(): string
    {
        if (!$this->checkAccess()) {
            return '';
        }

        $view = $this->createView();

        return $view->render('ToolbarItems/A11yScanToolbarItem');
    }

    public function hasDropDown(): bool
    {
        return true;
    }

    public function getDropDown(): string
    {
        if (!$this->checkAccess()) {
            return '';
        }

        $status = $this->scanStatusService->getStatus();
        $this->collectInformation($status);

        $view = $this->createView();
        $view->assignMultiple([
            'toolbarInformation' => $this->toolbarInformation,
            'scanStatus' => $status,
        ]);

        return $view->render('ToolbarItems/A11yScanToolbarDropDown');
    }

    public function getAdditionalAttributes(): array
    {
        return [];
    }

    public function getIndex(): int
    {
        return 60;
    }

    private function createView()
    {
        return $this->backendViewFactory->create(
            $this->request,
            ['a11y_quality_gate']
        );
    }

    /**
     * @param array<string, mixed> $status
     */
    private function collectInformation(array $status): void
    {
        $this->toolbarInformation = [];

        $isRunning = (bool)($status['running'] ?? false);

        $this->toolbarInformation[] = [
            'title' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:toolbar.status',
            'value' => $isRunning
                ? 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:toolbar.status.running'
                : 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:toolbar.status.idle',
            'iconIdentifier' => $isRunning ? 'actions-play' : 'actions-circle',
        ];

        if (!empty($status['trigger'])) {
            $this->toolbarInformation[] = [
                'title' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:toolbar.trigger',
                'value' => (string)$status['trigger'],
                'iconIdentifier' => 'actions-system-extension-configure',
            ];
        }

        if (!empty($status['triggeredBy'])) {
            $this->toolbarInformation[] = [
                'title' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:toolbar.triggeredBy',
                'value' => (string)$status['triggeredBy'],
                'iconIdentifier' => 'actions-user',
            ];
        }

        if (!empty($status['startedAt'])) {
            $this->toolbarInformation[] = [
                'title' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:toolbar.startedAt',
                'value' => date('d.m.Y H:i', (int)$status['startedAt']),
                'iconIdentifier' => 'actions-clock',
            ];
        }

        if (!empty($status['finishedAt'])) {
            $this->toolbarInformation[] = [
                'title' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:toolbar.lastFinished',
                'value' => date('d.m.Y H:i', (int)$status['finishedAt']),
                'iconIdentifier' => 'actions-check',
            ];
        }

        if (isset($status['summary']) && is_array($status['summary'])) {
            $summary = $status['summary'];

            $this->toolbarInformation[] = [
                'title' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:toolbar.lastResult',
                'value' => sprintf(
                    '%d new / %d resolved / %d ignored',
                    (int)($summary['issuesNew'] ?? 0),
                    (int)($summary['issuesResolved'] ?? 0),
                    (int)($summary['issuesIgnored'] ?? 0),
                ),
                'iconIdentifier' => 'status-dialog-information',
            ];
        }

        if (!empty($status['error'])) {
            $this->toolbarInformation[] = [
                'title' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang.xlf:toolbar.error',
                'value' => (string)$status['error'],
                'iconIdentifier' => 'actions-exclamation-circle-alt',
            ];
        }
    }
}
