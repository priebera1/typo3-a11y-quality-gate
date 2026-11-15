<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Backend\ToolbarItems\Assistant\Ask;

use TYPO3\CMS\Core\Crypto\HashService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The numbered list of problems one Ask AQG answer showed ("1. Missing alt text …, 2. …"), signed by the server:
 * `s1.<target>.<issuedAt>.<finding uids>.<hmac>`.
 *
 * "Fix problem 1" must mean exactly the finding that was number 1 in that answer — not whatever sorts first now, after a
 * new scan, in another conversation or on another page. So the numbers never travel alone: the browser sends back the
 * token of the list it showed, and the server maps the number to the finding uid in it. The signature binds the list to
 * the backend user, the login session, the resolved context (site, page, language, record, workspace — the same binding
 * as the conversation id) and the conversation, so a list can be used neither by another user or login, nor on another
 * page or record, nor in another conversation. A list older than a conversation is refused. A number that is not in the
 * list, or a finding that is no longer open in the selected target, is never replaced by another one: Ask AQG shows the
 * current list and asks the editor to choose again (AskAqgRepairService::plan()).
 */
final class AskAqgSelection
{
    private const SECRET = 'aqg-toolbar-ask-selection';
    public const MAX_ITEMS = 50;

    /** @param list<int> $findingUids */
    private function __construct(
        public readonly string $target,
        public readonly array $findingUids,
        public readonly int $issuedAt,
    ) {}

    /**
     * @param list<int> $findingUids in the order the answer numbered them (number = index + 1)
     */
    public static function issue(string $target, array $findingUids, int $beUserUid, string $binding, string $conversationId, ?int $now = null): string
    {
        $uids = array_slice(array_values(array_filter(array_map('intval', $findingUids), static fn (int $uid): bool => $uid > 0)), 0, self::MAX_ITEMS);
        $issuedAt = $now ?? time();
        $target = self::cleanTarget($target);
        $list = implode('-', $uids);

        return implode('.', ['s1', $target, $issuedAt, $list, self::sign($target, $issuedAt, $list, $beUserUid, $binding, $conversationId)]);
    }

    /**
     * The list the token names when it was issued for this user, binding and conversation and has not expired; null
     * otherwise (a missing, foreign, altered or expired list is never "repaired" into another one).
     */
    public static function verify(string $token, int $beUserUid, string $binding, string $conversationId, ?int $now = null): ?self
    {
        $now ??= time();
        if ($conversationId === '' || preg_match('/^s1\.(record|page|file)\.([0-9]{1,12})\.((?:[1-9][0-9]{0,9})(?:-[1-9][0-9]{0,9}){0,49})?\.([a-f0-9]{40,128})$/', $token, $matches) !== 1) {
            return null;
        }
        $issuedAt = (int)$matches[2];
        $list = (string)$matches[3];
        if ($issuedAt > $now || $issuedAt <= $now - AskAqgLimits::CONVERSATION_TTL_SECONDS
            || !hash_equals(self::sign($matches[1], $issuedAt, $list, $beUserUid, $binding, $conversationId), $matches[4])) {
            return null;
        }
        $uids = $list === '' ? [] : array_map('intval', explode('-', $list));

        return new self($matches[1], $uids, $issuedAt);
    }

    /**
     * The finding uids of the given 1-based numbers, or null when any number is not in the list (never a partial or
     * shifted answer).
     *
     * @param list<int> $numbers
     * @return array<int,int>|null number => finding uid
     */
    public function resolve(array $numbers): ?array
    {
        if ($numbers === []) {
            return null;
        }
        $resolved = [];
        foreach ($numbers as $number) {
            $uid = $this->findingUids[$number - 1] ?? 0;
            if ($number < 1 || $uid <= 0) {
                return null;
            }
            $resolved[$number] = $uid;
        }

        return $resolved;
    }

    /** The number a finding had in this list (0 when it was not in it). */
    public function numberOf(int $findingUid): int
    {
        $index = array_search($findingUid, $this->findingUids, true);

        return $index === false ? 0 : $index + 1;
    }

    private static function cleanTarget(string $target): string
    {
        return in_array($target, ['record', 'page', 'file'], true) ? $target : 'page';
    }

    private static function sign(string $target, int $issuedAt, string $list, int $beUserUid, string $binding, string $conversationId): string
    {
        // HashService::hmac() exists with this signature on TYPO3 13 and 14.
        return GeneralUtility::makeInstance(HashService::class)->hmac(
            implode('|', [$target, $issuedAt, $list, $beUserUid, $binding, $conversationId]),
            self::SECRET,
        );
    }
}
