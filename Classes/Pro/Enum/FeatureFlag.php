<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Enum;

enum FeatureFlag: string
{
    case ProRules = 'pro_rules';
    case ExportPdf = 'export_pdf';
    case Crawler = 'crawler';
    case MultiSite = 'multi_site';
    case ApiAccess = 'api_access';
    case WhiteLabel = 'white_label';
    case ScreenshotCapture = 'screenshot_capture';
}
