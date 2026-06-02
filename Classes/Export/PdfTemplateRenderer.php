<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Export;

use Psr\Http\Message\ServerRequestInterface;
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
        $normalizedTemplateName = ltrim($templateName, '/');
        if (str_ends_with($normalizedTemplateName, '.html')) {
            $normalizedTemplateName = substr($normalizedTemplateName, 0, -5);
        }

        $view = $this->viewFactory->create(
            new ViewFactoryData(
                templateRootPaths: [
                    'EXT:a11y_quality_gate/Resources/Private/Templates/',
                ],
                partialRootPaths: [
                    'EXT:a11y_quality_gate/Resources/Private/Partials/',
                ],
                layoutRootPaths: [
                    'EXT:a11y_quality_gate/Resources/Private/Layouts/',
                ],
                request: $request,
            )
        );

        $view->assignMultiple($variables);

        return $view->render($normalizedTemplateName);
    }
}
