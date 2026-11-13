<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\QualityGate\Contract;

use TYPO3\CMS\Core\Site\Entity\Site;

/** Whether the Quality Gate may block publishing on a site: blocking needs an active licence, warning does not. */
interface GateLicenceInterface
{
    public function canBlock(Site $site): bool;
}
