<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\ViewHelpers;

use Priebera\A11yQualityGate\Export\PdfGenerator;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractConditionViewHelper;

/**
 * Renders its then-branch only where PDF export can work in this installation.
 *
 * PDF export is Composer-only in 3.0.0: mPDF is a Composer dependency and the TER (Classic mode) package does not
 * bundle it. Views offer the PDF controls only when it loads and otherwise say why, instead of linking to an export
 * that can only answer with a notice. The licence check stays with the caller; the export actions check both again.
 */
final class PdfExportAvailableViewHelper extends AbstractConditionViewHelper
{
    public static function verdict(array $arguments, RenderingContextInterface $renderingContext): bool
    {
        return PdfGenerator::isAvailable();
    }
}
