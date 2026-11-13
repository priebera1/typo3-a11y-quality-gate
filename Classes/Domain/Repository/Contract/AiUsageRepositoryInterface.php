<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Domain\Repository\Contract;

interface AiUsageRepositoryInterface
{
    /** @param array{site_identifier:string,be_user:int,feature:string,provider:string,model:string,outcome:string,input_tokens:int,output_tokens:int,cache_read_tokens?:int,cache_write_tokens?:int,usage_reported:int,estimated_cost_micro_usd?:int,cost_known?:int,cost_basis?:string,price_origin?:string,price_verified_at?:int,conversation_ref?:string,crdate:int} $row input_tokens = ordinary (uncached) input */
    public function add(array $row): void;

    /**
     * Admits one AI attempt before its provider request: inserts its "pending" row (outcome/cost_basis 'pending', the
     * worst case in reserved_cost_micro_usd, the budget reservation uid if any) and returns its uid. Throws when the row
     * cannot be written; the caller then sends nothing.
     *
     * @param array<string, int|string> $row
     */
    public function admit(array $row): int;

    /**
     * Settles an admitted attempt with its outcome, tokens and actual cost (reserved_cost_micro_usd 0). True only when the
     * pending row was updated; false/throw leaves it pending and counted.
     *
     * @param array<string, int|string> $fields
     */
    public function settle(int $uid, array $fields): bool;

    /** Removes an admitted attempt that was refused before its provider request (only a still pending row). */
    public function withdraw(int $uid): void;

    /**
     * @return list<array{feature:string,provider:string,model:string,requests:int,failed:int,input_tokens:int,cache_read_tokens:int,cache_write_tokens:int,output_tokens:int,unreported:int,cost_micro_usd:int,cost_unknown:int}>
     */
    public function summarize(string $siteIdentifier, int $from, int $until): array;

    /**
     * The site's usage rows of [$from, $until) grouped by backend user, feature, provider and model (AiUsageBreakdown):
     * outcomes (pending is neither success nor failure), tokens and cost buckets. The administrator's usage report
     * (site total, by person, by feature) sums these groups only. Rows hold no prompt, answer or key.
     *
     * @return list<array{be_user:int,feature:string,provider:string,model:string,requests:int,succeeded:int,failed:int,pending:int,input_tokens:int,cache_read_tokens:int,output_tokens:int,unreported:int,reported_micro_usd:int,bound_micro_usd:int,bound_requests:int,no_charge:int,unknown:int,reserved_micro_usd:int}>
     */
    public function breakdown(string $siteIdentifier, int $from, int $until): array;

    /**
     * Requests and estimated cost of one "Ask AQG" conversation of one backend user since $since (the conversation
     * id's issue time), pending and unsettled attempts included at their worst case. The ref is an opaque random id; the
     * rows hold no content.
     *
     * @return array{requests:int,cost_micro_usd:int,cost_unknown:int}
     */
    public function summarizeConversation(string $siteIdentifier, int $beUser, string $conversationRef, int $since): array;

    /** Requests of one feature by one backend user on a site since $since (all outcomes incl. pending, every conversation). */
    public function countUserFeatureRequests(string $siteIdentifier, int $beUser, string $feature, int $since): int;
}
