<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Backend\ToolbarItems\A11yScanToolbarItem;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Core\Http\HtmlResponse;

#[AsController]
final class ToolbarScanController
{
    public function __construct(
        private readonly A11yScanToolbarItem $a11yScanToolbarItem,
    ) {
    }

    public function renderMenuAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->a11yScanToolbarItem->setRequest($request);

        return new HtmlResponse($this->a11yScanToolbarItem->getDropDown());
    }
}
