<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\FreePreview;

/**
 * The AQG service's confirmation that an administrator of this installation accepted the FREE Remote Preview terms for
 * a site's domain, as this installation keeps it (FreePreviewActivationStore).
 *
 * Every fact here comes from the service's answer and is checked against what was sent: the acceptance id, the terms
 * version, the installation and domain it was recorded for, and `acceptedAt` — the service's own UTC timestamp. Nothing
 * is filled in locally, so no TYPO3 clock, form field or setting can create or backdate an acceptance. The only local
 * facts are whether FREE is switched on for the domain (`enabled`), who submitted it here (`acceptedByUserUid`) and when
 * the status was last checked with the service.
 */
final class FreeTermsAcceptance
{
    public const STATUS_VALID = 'valid';
    public const STATUS_OUTDATED = 'outdated';
    public const STATUS_REVOKED = 'revoked';
    public const STATUS_EXPIRED = 'expired';
    private const STATUSES = [self::STATUS_VALID, self::STATUS_OUTDATED, self::STATUS_REVOKED, self::STATUS_EXPIRED];

    /**
     * @param list<array{type:string,language:string,version:string,sha256:string}> $documents
     */
    public function __construct(
        public readonly string $acceptanceId,
        public readonly string $status,
        public readonly string $domain,
        public readonly string $siteIdentifier,
        public readonly string $installationId,
        public readonly string $termsVersion,
        public readonly array $documents,
        public readonly string $statementVersion,
        public readonly string $statementLanguage,
        public readonly string $statementSha256,
        public readonly string $acceptedAt,
        public readonly int $acceptedAtTimestamp,
        public readonly int $validUntilTimestamp,
        public readonly string $organisationName,
        public readonly string $acceptedByName,
        public readonly int $acceptedByUserUid,
        public readonly bool $enabled,
        public readonly int $statusCheckedAt,
    ) {
    }

    /**
     * The service's answer to POST /free-preview/acceptances, accepted only when it confirms exactly what was sent.
     *
     * @param array<string, mixed> $response the decoded body
     * @param array<string, mixed> $request what was sent
     */
    public static function fromConfirmation(array $response, array $request, int $acceptedByUserUid, int $now): ?self
    {
        $acceptance = is_array($response['acceptance'] ?? null) ? $response['acceptance'] : null;
        if ($acceptance === null || ($response['success'] ?? true) === false) {
            return null;
        }
        $id = trim((string)($acceptance['id'] ?? ''));
        $acceptedAt = self::utcTimestamp($acceptance['acceptedAt'] ?? null);
        if (preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $id) !== 1
            || strtolower(trim((string)($acceptance['status'] ?? ''))) !== self::STATUS_VALID
            || (string)($acceptance['termsVersion'] ?? '') !== (string)($request['termsVersion'] ?? '')
            || (string)($acceptance['installationId'] ?? '') !== (string)($request['installationId'] ?? '')
            || strtolower((string)($acceptance['domain'] ?? '')) !== strtolower((string)($request['domain'] ?? ''))
            || $acceptedAt === null
        ) {
            return null;
        }
        $statement = is_array($request['statement'] ?? null) ? $request['statement'] : [];
        $validUntil = self::utcTimestamp($acceptance['validUntil'] ?? null);

        return new self(
            acceptanceId: $id,
            status: self::STATUS_VALID,
            domain: strtolower((string)$request['domain']),
            siteIdentifier: (string)($request['siteIdentifier'] ?? ''),
            installationId: (string)$request['installationId'],
            termsVersion: (string)$request['termsVersion'],
            documents: self::documentsFrom($request['documents'] ?? []),
            statementVersion: (string)($statement['version'] ?? ''),
            statementLanguage: (string)($statement['language'] ?? ''),
            statementSha256: (string)($statement['sha256'] ?? ''),
            acceptedAt: gmdate('Y-m-d\TH:i:s\Z', $acceptedAt),
            acceptedAtTimestamp: $acceptedAt,
            validUntilTimestamp: $validUntil ?? 0,
            organisationName: (string)(is_array($request['organisation'] ?? null) ? ($request['organisation']['name'] ?? '') : ''),
            acceptedByName: (string)(is_array($request['acceptedBy'] ?? null) ? ($request['acceptedBy']['name'] ?? '') : ''),
            acceptedByUserUid: $acceptedByUserUid,
            enabled: true,
            statusCheckedAt: $now,
        );
    }

    /** @param array<string, mixed> $data as stored */
    public static function fromArray(array $data): ?self
    {
        $id = trim((string)($data['acceptanceId'] ?? ''));
        $status = (string)($data['status'] ?? '');
        if ($id === '' || !in_array($status, self::STATUSES, true) || (int)($data['acceptedAtTimestamp'] ?? 0) <= 0) {
            return null;
        }

        return new self(
            acceptanceId: $id,
            status: $status,
            domain: (string)($data['domain'] ?? ''),
            siteIdentifier: (string)($data['siteIdentifier'] ?? ''),
            installationId: (string)($data['installationId'] ?? ''),
            termsVersion: (string)($data['termsVersion'] ?? ''),
            documents: self::documentsFrom($data['documents'] ?? []),
            statementVersion: (string)($data['statementVersion'] ?? ''),
            statementLanguage: (string)($data['statementLanguage'] ?? ''),
            statementSha256: (string)($data['statementSha256'] ?? ''),
            acceptedAt: (string)($data['acceptedAt'] ?? ''),
            acceptedAtTimestamp: (int)$data['acceptedAtTimestamp'],
            validUntilTimestamp: (int)($data['validUntilTimestamp'] ?? 0),
            organisationName: (string)($data['organisationName'] ?? ''),
            acceptedByName: (string)($data['acceptedByName'] ?? ''),
            acceptedByUserUid: (int)($data['acceptedByUserUid'] ?? 0),
            enabled: (bool)($data['enabled'] ?? false),
            statusCheckedAt: (int)($data['statusCheckedAt'] ?? 0),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    public function withEnabled(bool $enabled): self
    {
        return new self(...array_merge($this->toArray(), ['enabled' => $enabled]));
    }

    /**
     * The status the service reported for this acceptance. Only the service can make it valid again, and only for the
     * same acceptance id (the caller checks that).
     */
    public function withStatus(string $status, int $checkedAt, ?int $validUntilTimestamp = null): self
    {
        $status = in_array($status, self::STATUSES, true) ? $status : $this->status;

        return new self(...array_merge($this->toArray(), [
            'status' => $status,
            'statusCheckedAt' => $checkedAt,
            'validUntilTimestamp' => $validUntilTimestamp ?? $this->validUntilTimestamp,
        ]));
    }

    public function isExpiredAt(int $now): bool
    {
        return $this->validUntilTimestamp > 0 && $this->validUntilTimestamp <= $now;
    }

    /** An ISO 8601 instant with an explicit time zone, as a Unix timestamp; null for anything else. */
    public static function utcTimestamp(mixed $value): ?int
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/', trim($value)) !== 1) {
            return null;
        }
        try {
            $timestamp = (new \DateTimeImmutable(trim($value)))->getTimestamp();
        } catch (\Exception) {
            return null;
        }

        return $timestamp > 0 ? $timestamp : null;
    }

    /** @return list<array{type:string,language:string,version:string,sha256:string}> */
    private static function documentsFrom(mixed $documents): array
    {
        $result = [];
        foreach (is_array($documents) ? $documents : [] as $document) {
            if (is_array($document)) {
                $result[] = [
                    'type' => (string)($document['type'] ?? ''),
                    'language' => (string)($document['language'] ?? ''),
                    'version' => (string)($document['version'] ?? ''),
                    'sha256' => (string)($document['sha256'] ?? ''),
                ];
            }
        }

        return $result;
    }
}
