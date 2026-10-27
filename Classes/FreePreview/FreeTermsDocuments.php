<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\FreePreview;

/**
 * The FREE Remote Preview terms the AQG service has published (GET /free-preview/terms): their version, the documents
 * (FREE terms and DPA, per language, each with URL and SHA-256) and the hash of the acceptance statement per language.
 * Only a complete answer is a set of terms; anything else is treated as "not published" and activation stays closed.
 */
final class FreeTermsDocuments
{
    /**
     * @param list<array{type:string,language:string,title:string,version:string,url:string,sha256:string}> $documents
     * @param array<string, string> $statementHashes language => sha256
     */
    private function __construct(
        public readonly string $version,
        public readonly string $effectiveAt,
        public readonly array $documents,
        public readonly array $statementHashes,
    ) {
    }

    /** @param array<string, mixed> $payload the decoded API body */
    public static function fromApi(array $payload): ?self
    {
        $terms = is_array($payload['terms'] ?? null) ? $payload['terms'] : null;
        if ($terms === null || ($payload['success'] ?? true) === false) {
            return null;
        }
        $version = trim((string)($terms['version'] ?? ''));
        if (!self::isSafeToken($version)) {
            return null;
        }

        $documents = [];
        foreach (is_array($terms['documents'] ?? null) ? $terms['documents'] : [] as $document) {
            if (!is_array($document)) {
                continue;
            }
            $url = trim((string)($document['url'] ?? ''));
            $sha256 = strtolower(trim((string)($document['sha256'] ?? '')));
            $type = trim((string)($document['type'] ?? ''));
            $language = strtolower(trim((string)($document['language'] ?? '')));
            $documentVersion = trim((string)($document['version'] ?? ''));
            // Only https documents with a hash: a link to an unpublished draft or to anything else is not offered.
            if (!in_array($type, ['free_terms', 'dpa'], true)
                || !in_array($language, ['en', 'de'], true)
                || !self::isSafeToken($documentVersion)
                || preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1
                || !str_starts_with($url, 'https://')
                || filter_var($url, FILTER_VALIDATE_URL) === false
            ) {
                continue;
            }
            $documents[] = [
                'type' => $type,
                'language' => $language,
                'title' => mb_substr(trim((string)($document['title'] ?? '')), 0, 200),
                'version' => $documentVersion,
                'url' => $url,
                'sha256' => $sha256,
            ];
        }

        $statementHashes = [];
        foreach (is_array($terms['statements'] ?? null) ? $terms['statements'] : [] as $language => $statement) {
            $hash = strtolower(trim((string)(is_array($statement) ? ($statement['sha256'] ?? '') : '')));
            if (in_array($language, ['en', 'de'], true) && preg_match('/^[0-9a-f]{64}$/', $hash) === 1) {
                $statementHashes[$language] = $hash;
            }
        }

        $types = array_unique(array_column($documents, 'type'));
        if (!in_array('free_terms', $types, true) || !in_array('dpa', $types, true) || $statementHashes === []) {
            return null;
        }

        return new self($version, trim((string)($terms['effectiveAt'] ?? '')), $documents, $statementHashes);
    }

    /**
     * The documents to link for a language: that language where published, otherwise English.
     *
     * @return list<array{type:string,language:string,title:string,version:string,url:string,sha256:string}>
     */
    public function documentsFor(string $language): array
    {
        $result = [];
        foreach (['free_terms', 'dpa'] as $type) {
            $match = null;
            foreach ($this->documents as $document) {
                if ($document['type'] !== $type) {
                    continue;
                }
                if ($document['language'] === $language) {
                    $match = $document;
                    break;
                }
                if ($document['language'] === 'en') {
                    $match ??= $document;
                }
            }
            if ($match !== null) {
                $result[] = $match;
            }
        }

        return $result;
    }

    /** Whether the statement this extension shows is the one the AQG service published for these terms. */
    public function acceptsStatement(string $language): bool
    {
        return hash_equals($this->statementHashes[$language] ?? '', FreePreviewStatement::sha256($language));
    }

    /** @return array<string, mixed> for the display cache */
    public function toArray(): array
    {
        return [
            'success' => true,
            'terms' => [
                'version' => $this->version,
                'effectiveAt' => $this->effectiveAt,
                'documents' => $this->documents,
                'statements' => array_map(static fn (string $hash): array => ['sha256' => $hash], $this->statementHashes),
            ],
        ];
    }

    private static function isSafeToken(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9._:-]{1,64}$/', $value) === 1;
    }
}
