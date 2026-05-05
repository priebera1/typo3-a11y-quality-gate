<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Export;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

final class PdfTemplateRenderer
{
    public function __construct(
        private readonly ViewFactoryInterface $viewFactory,
    ) {
    }

    /**
     * @param array<string, mixed> $variables
     */
    public function render(
        string $templateName,
        array $variables,
        ?ServerRequestInterface $request = null,
    ): string {
        $templatePathAndFilename = GeneralUtility::getFileAbsFileName(
            'EXT:a11y_quality_gate/Resources/Private/Templates/' . $templateName . '.html'
        );

        $view = $this->viewFactory->create(
            new ViewFactoryData(
                templateRootPaths: [
                    GeneralUtility::getFileAbsFileName('EXT:a11y_quality_gate/Resources/Private/Templates/'),
                ],
                partialRootPaths: [
                    GeneralUtility::getFileAbsFileName('EXT:a11y_quality_gate/Resources/Private/Partials/'),
                ],
                layoutRootPaths: [
                    GeneralUtility::getFileAbsFileName('EXT:a11y_quality_gate/Resources/Private/Layouts/'),
                ],
                templatePathAndFilename: $templatePathAndFilename,
                request: $request,
            )
        );

        $view->assignMultiple($variables);

        return $view->render();
    }
}