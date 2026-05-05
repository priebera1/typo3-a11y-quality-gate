<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Enum;

enum RemoteScanSourceType: string
{
    case Sitemap = 'sitemap';
    case Crawl = 'crawl';
    case SinglePage = 'single_page';
}