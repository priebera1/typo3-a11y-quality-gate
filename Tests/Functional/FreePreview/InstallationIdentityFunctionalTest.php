<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\FreePreview;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Service\InstallationIdentityService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Registry;

final class InstallationIdentityFunctionalTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function generatedIdentityPersistsAtInstallationLevel(): void
    {
        $service = $this->get(InstallationIdentityService::class);

        $first = $service->getOrCreateInstallationId();
        $second = $this->get(InstallationIdentityService::class)
            ->getOrCreateInstallationId();
        $stored = $this->get(Registry::class)->get(
            'a11y_quality_gate',
            'anonymous_installation_id',
        );

        self::assertSame($first, $second);
        self::assertSame($first, $stored);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $first,
        );
    }
}
