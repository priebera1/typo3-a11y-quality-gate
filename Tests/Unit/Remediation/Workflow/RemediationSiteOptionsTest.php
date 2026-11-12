<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Remediation\Workflow;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationSiteOptions;
use TYPO3\CMS\Core\Site\Entity\Site;

final class RemediationSiteOptionsTest extends TestCase
{
    #[Test]
    public function aSiteIsNamedByItsTitleAndByItsIdentifierOnlyWithoutOne(): void
    {
        self::assertSame('Stadtwerke Musterstadt', RemediationSiteOptions::title($this->site('main', 'Stadtwerke Musterstadt')));
        self::assertSame('Default language title', RemediationSiteOptions::title($this->site('main', '', 'Default language title')));
        self::assertSame('main', RemediationSiteOptions::title($this->site('main', '')));
    }

    #[Test]
    public function optionsAreSortedByTitleMarkTheSelectedSiteAndKeepEqualTitlesApart(): void
    {
        $options = RemediationSiteOptions::options([
            $this->site('zoo', 'Zoo'),
            $this->site('b', 'Stadtwerke'),
            $this->site('a', 'Stadtwerke'),
        ], 'b');

        self::assertSame([
            ['value' => 'a', 'label' => 'Stadtwerke (a)', 'selected' => false],
            ['value' => 'b', 'label' => 'Stadtwerke (b)', 'selected' => true],
            ['value' => 'zoo', 'label' => 'Zoo', 'selected' => false],
        ], $options);
    }

    private function site(string $identifier, string $title, string $languageTitle = ''): Site
    {
        $configuration = [
            'base' => 'https://' . $identifier . '.example/',
            'rootPageId' => 1,
            'languages' => [[
                'languageId' => 0,
                'title' => 'English',
                'locale' => 'en_US.UTF-8',
                'base' => '/',
                'websiteTitle' => $languageTitle,
            ]],
        ];
        if ($title !== '') {
            $configuration['websiteTitle'] = $title;
        }

        return new Site($identifier, 1, $configuration);
    }
}
