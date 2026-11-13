<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Backend\ToolbarItems\Assistant\Ask;

use TYPO3\CMS\Core\Crypto\HashService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * A server-issued, signed conversation id: `v2.<ref>.<issuedAt>.<hmac>`. The signature binds the id to the backend user,
 * the site and a binding: the backend session (hashed) and the server-resolved context (ToolbarAssistantContext type,
 * workspace, site, page, language, record, file), see AskAqgService::conversationBinding(). So a browser cannot continue
 * another user's or another login's conversation, carry one into another page/site/language, or invent a ref with a
 * lower count. Only `ref` (random, no content) is written to the usage rows; the conversation's questions and spend are
 * summed from them. Starting a new conversation is always allowed, so the per-conversation caps are a usability limit;
 * the per-user daily cap (AskAqgLimits::MAX_AI_QUESTIONS_PER_USER_PER_DAY) and the site budget are the hard limits.
 *
 * Earlier turns the browser sends back are accepted only with the turn token the server returned with that answer
 * (turnToken(): HMAC over the conversation id and the exact history text), so history is provably this conversation's
 * own server output — never another context's, user's or session's, and never text the browser made up (PI-02).
 */
final class AskAqgConversation
{
    private const SECRET = 'aqg-toolbar-ask-conversation';
    private const TURN_SECRET = 'aqg-toolbar-ask-turn';
    private const SCOPE_SECRET = 'aqg-toolbar-ask-scope';

    private function __construct(
        public readonly string $id,
        public readonly string $ref,
        public readonly bool $issued,
    ) {}

    public static function issue(int $beUserUid, string $siteIdentifier, ?int $now = null, string $binding = ''): self
    {
        $ref = bin2hex(random_bytes(12));
        $issuedAt = $now ?? time();

        return new self('v2.' . $ref . '.' . $issuedAt . '.' . self::sign($ref, $issuedAt, $beUserUid, $siteIdentifier, $binding), $ref, true);
    }

    /**
     * The given id when it is valid for this user, site and binding (session + context) and not expired, else a new one.
     * A new conversation never inherits earlier turns: their tokens were signed for another id.
     */
    public static function resume(string $id, int $beUserUid, string $siteIdentifier, ?int $now = null, string $binding = ''): self
    {
        $now ??= time();
        if (preg_match('/^v2\.([a-f0-9]{24})\.([0-9]{1,12})\.([a-f0-9]{40,128})$/', $id, $matches) === 1) {
            $issuedAt = (int)$matches[2];
            if ($issuedAt <= $now && $issuedAt > $now - AskAqgLimits::CONVERSATION_TTL_SECONDS
                && hash_equals(self::sign($matches[1], $issuedAt, $beUserUid, $siteIdentifier, $binding), $matches[3])) {
                return new self($id, $matches[1], false);
            }
        }

        return self::issue($beUserUid, $siteIdentifier, $now, $binding);
    }

    public static function issuedAt(string $id): int
    {
        return preg_match('/^v2\.[a-f0-9]{24}\.([0-9]{1,12})\./', $id, $matches) === 1 ? (int)$matches[1] : 0;
    }

    /** The token of one earlier turn, as the server returned it: valid only for this conversation id and this exact text. */
    public static function turnToken(string $conversationId, string $question, string $answer): string
    {
        return self::hmac(implode("\0", [$conversationId, $question, $answer]), self::TURN_SECRET);
    }

    /** Whether an earlier turn the browser sent back is this conversation's own server output. */
    public function verifiesTurn(string $question, string $answer, string $token): bool
    {
        return !$this->issued && $token !== '' && hash_equals(self::turnToken($this->id, $question, $answer), $token);
    }

    /**
     * An opaque browser storage key part: HMAC of the given parts (user, hashed session, context). It reveals neither
     * the session nor the identifiers and is no credential: the server re-checks every request on its own.
     */
    public static function scopeKey(string ...$parts): string
    {
        return substr(self::hmac(implode("\0", $parts), self::SCOPE_SECRET), 0, 40);
    }

    private static function sign(string $ref, int $issuedAt, int $beUserUid, string $siteIdentifier, string $binding): string
    {
        return self::hmac(implode('|', [$ref, $issuedAt, $beUserUid, $siteIdentifier, $binding]), self::SECRET);
    }

    private static function hmac(string $data, string $secret): string
    {
        // HashService::hmac() exists with this signature on TYPO3 13 and 14 (GeneralUtility::hmac() is gone in 14).
        return GeneralUtility::makeInstance(HashService::class)->hmac($data, $secret);
    }
}
