<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\FreePreview;

use TYPO3\CMS\Core\Registry;

/**
 * Where this installation keeps the AQG service's confirmations of FREE Remote Preview terms acceptance, one per site
 * domain: in `sys_registry`, so they survive cache flushes, logouts and deployments of the same database, and are
 * never part of a cache that could be shared or stale. The acceptance record itself is kept by the AQG service.
 */
class FreePreviewActivationStore
{
    private const REGISTRY_NAMESPACE = 'tx_a11y_quality_gate';
    private const REGISTRY_KEY = 'freePreviewActivation';

    public function __construct(private readonly Registry $registry)
    {
    }

    public function find(string $domain): ?FreeTermsAcceptance
    {
        $all = $this->all();
        $data = $all[strtolower(trim($domain))] ?? null;

        return is_array($data) ? FreeTermsAcceptance::fromArray($data) : null;
    }

    public function save(FreeTermsAcceptance $acceptance): void
    {
        $all = $this->all();
        $all[strtolower($acceptance->domain)] = $acceptance->toArray();
        $this->registry->set(self::REGISTRY_NAMESPACE, self::REGISTRY_KEY, $all);
    }

    /** @return array<string, array<string, mixed>> */
    private function all(): array
    {
        $value = $this->registry->get(self::REGISTRY_NAMESPACE, self::REGISTRY_KEY, []);

        return is_array($value) ? array_filter($value, 'is_array') : [];
    }
}
