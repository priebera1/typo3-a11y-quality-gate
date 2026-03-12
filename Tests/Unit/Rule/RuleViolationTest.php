<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Rule;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleViolation;

/**
 * Unit tests for RuleViolation::fingerprint() and normalizeForFingerprint().
 *
 * These tests guard the core deduplication contract:
 *   - Same violation + same context = same fingerprint (idempotent rescan)
 *   - Different site/lang/source/rule/path = different fingerprint
 *   - Whitespace noise in snippet does NOT change fingerprint
 */
class RuleViolationTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Fingerprint stability
    // -------------------------------------------------------------------------

    #[Test]
    public function fingerprintIsIdempotent(): void
    {
        $violation = $this->violation(snippet: '<img src="x.jpg">', path: 'div > img');
        $ctx       = $this->ctx();

        self::assertSame($violation->fingerprint($ctx), $violation->fingerprint($ctx));
    }

    #[Test]
    public function fingerprintIsDeterministicAcrossInstances(): void
    {
        $v1  = $this->violation(snippet: '<img src="x.jpg">', path: 'div > img');
        $v2  = $this->violation(snippet: '<img src="x.jpg">', path: 'div > img');
        $ctx = $this->ctx();

        self::assertSame($v1->fingerprint($ctx), $v2->fingerprint($ctx));
    }

    // -------------------------------------------------------------------------
    // Whitespace normalization
    // -------------------------------------------------------------------------

    #[Test]
    public function fingerprintIgnoresLeadingTrailingWhitespaceInSnippet(): void
    {
        $v1  = $this->violation(snippet: '<img src="x.jpg">', path: 'img');
        $v2  = $this->violation(snippet: '  <img src="x.jpg">  ', path: 'img');
        $ctx = $this->ctx();

        self::assertSame($v1->fingerprint($ctx), $v2->fingerprint($ctx));
    }

    #[Test]
    public function fingerprintCollapsesInternalWhitespaceInSnippet(): void
    {
        $v1  = $this->violation(snippet: '<img  src="x.jpg">', path: 'img');
        $v2  = $this->violation(snippet: "<img\n src=\"x.jpg\">", path: 'img');
        $ctx = $this->ctx();

        self::assertSame($v1->fingerprint($ctx), $v2->fingerprint($ctx));
    }

    #[Test]
    public function fingerprintIsCaseInsensitiveForSnippet(): void
    {
        $v1  = $this->violation(snippet: '<IMG SRC="x.jpg">', path: 'img');
        $v2  = $this->violation(snippet: '<img src="x.jpg">', path: 'img');
        $ctx = $this->ctx();

        self::assertSame($v1->fingerprint($ctx), $v2->fingerprint($ctx));
    }

    // -------------------------------------------------------------------------
    // Context differentiation
    // -------------------------------------------------------------------------

    #[Test]
    public function fingerprintDiffersForDifferentSites(): void
    {
        $violation = $this->violation();
        $ctxA      = $this->ctx(site: 'site-a');
        $ctxB      = $this->ctx(site: 'site-b');

        self::assertNotSame($violation->fingerprint($ctxA), $violation->fingerprint($ctxB));
    }

    #[Test]
    public function fingerprintDiffersForDifferentLanguages(): void
    {
        $violation = $this->violation();
        $ctxEn     = $this->ctx(lang: 0);
        $ctxDe     = $this->ctx(lang: 1);

        self::assertNotSame($violation->fingerprint($ctxEn), $violation->fingerprint($ctxDe));
    }

    #[Test]
    public function fingerprintDiffersForDifferentSourceFields(): void
    {
        $violation   = $this->violation();
        $ctxBodytext = $this->ctx(field: 'bodytext');
        $ctxHeader   = $this->ctx(field: 'header');

        self::assertNotSame($violation->fingerprint($ctxBodytext), $violation->fingerprint($ctxHeader));
    }

    #[Test]
    public function fingerprintDiffersForDifferentRules(): void
    {
        $v1  = $this->violation(ruleId: 'rte.img_alt_missing');
        $v2  = $this->violation(ruleId: 'rte.empty_link');
        $ctx = $this->ctx();

        self::assertNotSame($v1->fingerprint($ctx), $v2->fingerprint($ctx));
    }

    #[Test]
    public function fingerprintDiffersForDifferentContextPaths(): void
    {
        // This is the key test: two identical <img> in the same field
        // must get different fingerprints via their contextPath
        $v1  = $this->violation(snippet: '<img src="x.jpg">', path: 'div > p[1] > img');
        $v2  = $this->violation(snippet: '<img src="x.jpg">', path: 'div > p[2] > img');
        $ctx = $this->ctx();

        self::assertNotSame(
            $v1->fingerprint($ctx),
            $v2->fingerprint($ctx),
            'Identical snippets in different DOM positions must produce different fingerprints'
        );
    }

    #[Test]
    public function fingerprintDiffersForDifferentSourceUids(): void
    {
        $violation = $this->violation();
        $ctx1      = $this->ctx(uid: 10);
        $ctx2      = $this->ctx(uid: 20);

        self::assertNotSame($violation->fingerprint($ctx1), $violation->fingerprint($ctx2));
    }

    // -------------------------------------------------------------------------
    // Truncation: snippets longer than 100 chars still produce stable fingerprint
    // -------------------------------------------------------------------------

    #[Test]
    public function fingerprintIsTruncatedStablyForLongSnippets(): void
    {
        $longSnippet = '<img src="' . str_repeat('a', 200) . '.jpg">';
        $v1          = $this->violation(snippet: $longSnippet, path: 'img');
        $v2          = $this->violation(snippet: $longSnippet, path: 'img');
        $ctx         = $this->ctx();

        self::assertSame($v1->fingerprint($ctx), $v2->fingerprint($ctx));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function violation(
        string $ruleId = 'rte.img_alt_missing',
        string $snippet = '<img src="x.jpg">',
        string $path = 'div > img',
    ): RuleViolation {
        return new RuleViolation(
            ruleId:         $ruleId,
            severity:       Severity::Critical,
            message:        'Test message',
            hint:           'Test hint',
            contextSnippet: $snippet,
            contextPath:    $path,
        );
    }

    private function ctx(
        string $site = 'main',
        int $lang = 0,
        int $uid = 42,
        string $field = 'bodytext',
    ): CheckContext {
        return new CheckContext(
            siteIdentifier: $site,
            pageUid:        1,
            sourceLangUid:  $lang,
            sourceTable:    Tables::TT_CONTENT,
            sourceUid:      $uid,
            sourceField:    $field,
            content:        '<img src="x.jpg">',
        );
    }
}
