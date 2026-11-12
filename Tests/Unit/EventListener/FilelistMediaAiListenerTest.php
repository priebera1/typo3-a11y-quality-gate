<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\EventListener;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Media\MediaAiAvailability;
use Priebera\A11yQualityGate\Ai\Media\MediaAiFileGuard;
use Priebera\A11yQualityGate\Ai\Media\MediaAiMetadataEditLink;
use Priebera\A11yQualityGate\EventListener\FilelistMediaAiListener;
use Priebera\A11yQualityGate\Service\BackendJavaScriptModuleService;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Template\Components\ActionGroup;
use TYPO3\CMS\Backend\Template\Components\Buttons\LinkButton;
use TYPO3\CMS\Backend\Template\Components\ComponentGroup;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Filelist\Event\ProcessFileListActionsEvent;

/**
 * Atlas audit F-01: on TYPO3 13 the Filelist list view died with "Call to undefined method LinkButton::setAttributes()"
 * for every folder with an AI-eligible image. The listener runs here against the installed TYPO3 (13 or 14 event shape)
 * and the row action must render as a link with its data attributes.
 */
final class FilelistMediaAiListenerTest extends TestCase
{
    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();
        parent::tearDown();
    }

    #[Test]
    public function anImageGetsTheMetadataRowActionWithItsDataAttributes(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getUid')->willReturn(42);
        $file->method('getMimeType')->willReturn('image/jpeg');

        $event = $this->createEvent($file);
        $this->createListener()->addFileAction($event);

        $button = $this->addedButton($event);
        self::assertInstanceOf(LinkButton::class, $button);
        self::assertSame(['aqg-media-ai' => 'open-form', 'aqg-file-uid' => '42'], $button->getDataAttributes());
        $markup = (string)$button;
        self::assertStringContainsString('data-aqg-media-ai="open-form"', $markup);
        self::assertStringContainsString('data-aqg-file-uid="42"', $markup);
        self::assertStringContainsString('href="/typo3/record/edit?file=42"', $markup);
    }

    #[Test]
    public function aNonImageFileGetsNoRowAction(): void
    {
        $file = $this->createMock(File::class);
        $file->method('getUid')->willReturn(7);
        $file->method('getMimeType')->willReturn('application/zip');

        $event = $this->createEvent($file);
        $this->createListener()->addFileAction($event);

        self::assertNull($this->addedButton($event));
    }

    #[Test]
    public function theListenerOnlyUsesButtonMethodsThatTypo3ThirteenProvides(): void
    {
        // TYPO3 13's LinkButton has no setAttributes() (TYPO3 14 added it to AbstractButton); guard the source so a
        // TYPO3 14-only test run cannot hide the regression.
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/Classes/EventListener/FilelistMediaAiListener.php');
        self::assertDoesNotMatchRegularExpression('/\$button->setAttributes\(/', $source);
    }

    private function createListener(): FilelistMediaAiListener
    {
        $availability = $this->createMock(MediaAiAvailability::class);
        $availability->method('forFilelist')->willReturn(true);
        $guard = $this->createMock(MediaAiFileGuard::class);
        $guard->method('editableFields')->willReturn(['alternative']);
        $editLink = $this->createMock(MediaAiMetadataEditLink::class);
        $editLink->method('url')->willReturnCallback(static fn(int $uid): string => '/typo3/record/edit?file=' . $uid);
        $icon = $this->createMock(Icon::class);
        $icon->method('render')->willReturn('<span class="icon"></span>');
        $iconFactory = $this->createMock(IconFactory::class);
        $iconFactory->method('getIcon')->willReturn($icon);

        return new FilelistMediaAiListener(
            $availability,
            $guard,
            $editLink,
            $iconFactory,
            $this->createMock(PageRenderer::class),
            $this->createMock(BackendJavaScriptModuleService::class),
        );
    }

    private function createEvent(File $file): ProcessFileListActionsEvent
    {
        if (method_exists(ProcessFileListActionsEvent::class, 'setAction')) {
            return new ProcessFileListActionsEvent(
                new ComponentGroup('primary'),
                new ComponentGroup('secondary'),
                $file,
                $this->createMock(ServerRequestInterface::class),
            );
        }

        return new ProcessFileListActionsEvent($file, ['metadata' => '']);
    }

    private function addedButton(ProcessFileListActionsEvent $event): mixed
    {
        if (method_exists($event, 'getAction')) {
            return $event->getAction('aqgMediaAi', ActionGroup::secondary);
        }

        return $event->getActionItems()['aqgMediaAi'] ?? null;
    }
}
