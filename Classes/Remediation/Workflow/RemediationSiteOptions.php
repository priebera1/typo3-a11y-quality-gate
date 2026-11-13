<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Remediation\Workflow;

use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Sites as the AI fixes view names them: the site's title (site configuration, else its default language), and the
 * identifier only where no title exists. The caller passes only sites the person may read and that carry the licence.
 */
final class RemediationSiteOptions
{
    public static function title(Site $site): string
    {
        $title = trim((string)($site->getConfiguration()['websiteTitle'] ?? ''));
        if ($title === '') {
            try {
                $title = trim($site->getDefaultLanguage()->getWebsiteTitle());
            } catch (\Throwable) {
                $title = '';
            }
        }

        return $title !== '' ? $title : $site->getIdentifier();
    }

    /**
     * The site filter: one option per site, sorted by title; the selected one marked. Two sites with the same title
     * keep their identifier in brackets, so the choice stays unambiguous.
     *
     * @param list<Site> $sites
     * @return list<array{value:string,label:string,selected:bool}>
     */
    public static function options(array $sites, string $selected): array
    {
        $titles = [];
        foreach ($sites as $site) {
            $titles[$site->getIdentifier()] = self::title($site);
        }
        $counts = array_count_values($titles);
        $options = [];
        foreach ($titles as $identifier => $title) {
            $options[] = [
                'value' => (string)$identifier,
                'label' => $counts[$title] > 1 ? $title . ' (' . $identifier . ')' : $title,
                'selected' => (string)$identifier === $selected,
            ];
        }
        usort($options, static fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));

        return $options;
    }
}
