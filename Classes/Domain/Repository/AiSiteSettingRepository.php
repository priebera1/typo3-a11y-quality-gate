<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Domain\Repository;

use Priebera\A11yQualityGate\Ai\Provider\AiProviderCatalog;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiSiteSettingRepositoryInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use TYPO3\CMS\Core\Database\Connection;

/**
 * Site-level AI settings: the active provider, what may be sent to it, and the link/iframe suggestion switch. A row is
 * created on the first change; until then a site behaves as in AQG 2.x.
 */
final class AiSiteSettingRepository extends AbstractRepository implements AiSiteSettingRepositoryInterface
{
    public function findBySiteIdentifier(string $siteIdentifier): ?array
    {
        $siteIdentifier = trim($siteIdentifier);
        if ($siteIdentifier === '') {
            return null;
        }

        $qb = $this->getQueryBuilder(Tables::AI_SITE_SETTING);
        $row = $qb->select('*')->from(Tables::AI_SITE_SETTING)
            ->where($qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    public function saveProvider(string $siteIdentifier, string $provider, int $backendUserUid): void
    {
        $provider = AiProviderCatalog::normalize($provider);
        if ($provider === '') {
            throw new \InvalidArgumentException('Unsupported AI provider.');
        }

        $now = time();
        $this->upsert($siteIdentifier, [
            'active_provider' => $provider,
            'provider_changed_at' => $now,
            'provider_changed_by' => max(0, $backendUserUid),
            // The acknowledgement covers one provider (UX Atlas 01 F): a switch always asks for it again.
            'processing_ack_provider' => '',
            'processing_ack_by' => 0,
            'processing_ack_at' => 0,
        ], $now);
    }

    public function saveBudget(string $siteIdentifier, int $budgetMicroUsd, int $warningPercent, bool $hardStop, int $backendUserUid): void
    {
        $now = time();
        $budgetMicroUsd = max(0, $budgetMicroUsd);
        $this->upsert($siteIdentifier, [
            'budget_micro_usd' => $budgetMicroUsd,
            'budget_warning_percent' => $budgetMicroUsd > 0 ? max(0, min(100, $warningPercent)) : 0,
            'budget_hard_stop' => $budgetMicroUsd > 0 && $hardStop ? 1 : 0,
            'budget_changed_at' => $now,
            'budget_changed_by' => max(0, $backendUserUid),
        ], $now);
    }

    public function saveBudgetAlertEmail(string $siteIdentifier, string $email): void
    {
        $this->upsert($siteIdentifier, ['budget_alert_email' => substr(trim($email), 0, 255)], time());
    }

    public function saveAcknowledgement(string $siteIdentifier, string $provider, int $backendUserUid): void
    {
        $provider = AiProviderCatalog::normalize($provider);
        if ($provider === '') {
            throw new \InvalidArgumentException('Unsupported AI provider.');
        }

        $now = time();
        $this->upsert($siteIdentifier, [
            'processing_ack_provider' => $provider,
            'processing_ack_by' => max(0, $backendUserUid),
            'processing_ack_at' => $now,
        ], $now);
    }

    public function savePrivacy(string $siteIdentifier, bool $allowImageInput, bool $allowPageTitle): void
    {
        $this->upsert($siteIdentifier, [
            'allow_image_input' => $allowImageInput ? 1 : 0,
            'allow_page_title' => $allowPageTitle ? 1 : 0,
        ], time());
    }

    public function saveLinkTextSuggestionsEnabled(string $siteIdentifier, bool $enabled): void
    {
        $this->upsert($siteIdentifier, [
            'link_text_suggestions_enabled' => $enabled ? 1 : 0,
        ], time());
    }

    /** @param array<string,int|string> $values */
    private function upsert(string $siteIdentifier, array $values, int $now): void
    {
        $siteIdentifier = trim($siteIdentifier);
        if ($siteIdentifier === '') {
            throw new \InvalidArgumentException('Site identifier is required.');
        }

        $values['tstamp'] = $now;
        $existing = $this->findBySiteIdentifier($siteIdentifier);
        $connection = $this->getConnection(Tables::AI_SITE_SETTING);
        if ($existing !== null) {
            $connection->update(Tables::AI_SITE_SETTING, $values, ['uid' => (int)$existing['uid']]);
            return;
        }

        // The first row carries the 2.x state forward: the site used OpenAI, and its link-text switch lived on the
        // OpenAI configuration row.
        $legacy = $this->findLegacyOpenAiRow($siteIdentifier);
        try {
            $connection->insert(Tables::AI_SITE_SETTING, array_replace([
                'pid' => 0,
                'site_identifier' => $siteIdentifier,
                'active_provider' => $legacy !== null ? AiProviderCatalog::LEGACY_DEFAULT : '',
                'allow_image_input' => 1,
                'allow_page_title' => 1,
                'link_text_suggestions_enabled' => (int)($legacy['link_text_suggestions_enabled'] ?? 0) === 1 ? 1 : 0,
                'provider_changed_at' => 0,
                'provider_changed_by' => 0,
                'crdate' => $now,
            ], $values));
        } catch (UniqueConstraintViolationException) {
            // Another request created the row in the meantime (unique site_identifier): apply this change to it.
            $connection->update(Tables::AI_SITE_SETTING, $values, ['site_identifier' => $siteIdentifier]);
        }
    }

    /** @return array<string,mixed>|null */
    private function findLegacyOpenAiRow(string $siteIdentifier): ?array
    {
        $qb = $this->getQueryBuilder(Tables::AI_CONFIGURATION);
        $row = $qb->select('uid', 'link_text_suggestions_enabled')->from(Tables::AI_CONFIGURATION)
            ->where(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->eq('provider', $qb->createNamedParameter(AiProviderCatalog::OPENAI)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }
}
