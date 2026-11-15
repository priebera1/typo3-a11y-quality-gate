<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Assignment;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Assignment\IssuesListState;

/**
 * MI-02..MI-06 (owner QA 2026-11-14): one sanitised My issues state for the query, the active-filter chips, the page
 * links and the way back from Page Detail. Nothing from the request survives unless it is a known value.
 */
final class IssuesListStateTest extends TestCase
{
    #[Test]
    public function defaultsAreMineOpenAllSitesTwentyFivePerPage(): void
    {
        $state = IssuesListState::fromArray([]);

        self::assertSame(['me', 'open', '', '', 1, 25], [$state->assignee, $state->state, $state->site, $state->query, $state->page, $state->perPage]);
        self::assertSame([], $state->toParameters());
        self::assertFalse($state->hasActiveFilters());
    }

    #[Test]
    public function onlyKnownValuesPass(): void
    {
        $state = IssuesListState::fromArray([
            'assignee' => '../evil', 'state' => 'deleted', 'site' => 'a b<script>', 'q' => str_repeat('x', 300),
            'page' => '-4', 'perPage' => '1000',
        ]);

        self::assertSame('me', $state->assignee);
        self::assertSame('open', $state->state);
        self::assertSame('', $state->site);
        self::assertSame(100, mb_strlen($state->query));
        self::assertSame(1, $state->page);
        self::assertSame(25, $state->perPage, 'Page sizes are 10, 25 or 50 (MI-03).');
        self::assertSame('me', IssuesListState::fromArray(['assignee' => ['x']])->assignee);
        self::assertSame('17', IssuesListState::fromArray(['assignee' => '17'])->assignee);
    }

    #[Test]
    public function aChipRemovesExactlyItsFilterAndReturnsToPageOne(): void
    {
        $state = IssuesListState::fromArray(['assignee' => 'unassigned', 'state' => 'any', 'site' => 'main', 'q' => 'button', 'page' => '3', 'perPage' => '50']);

        self::assertSame(['assignee' => 'unassigned', 'state' => 'any', 'site' => 'main', 'perPage' => 50], $state->without('q')->toParameters());
        self::assertSame(['state' => 'any', 'site' => 'main', 'q' => 'button', 'perPage' => 50], $state->without('assignee')->toParameters());
        self::assertSame(['perPage' => 50], $state->cleared()->toParameters(), 'Clear all keeps the page size, a preference.');
        self::assertTrue($state->hasActiveFilters());
    }

    #[Test]
    public function theReturnValueRoundTripsAndIsResanitised(): void
    {
        $state = IssuesListState::fromArray(['assignee' => 'all', 'state' => 'done', 'q' => 'alt text', 'page' => '2', 'perPage' => '10']);
        $back = IssuesListState::fromReturnValue($state->toReturnValue());

        self::assertNotNull($back);
        self::assertSame($state->toParameters(), $back->toParameters());
        // The value is data, never a URL: whatever it holds, the list is rebuilt from known fields only.
        $hostile = IssuesListState::fromReturnValue('assignee=https://evil.example&state=open&returnUrl=https://evil.example&token=x');
        self::assertSame([], $hostile?->toParameters());
        self::assertNull(IssuesListState::fromReturnValue(''));
        self::assertNull(IssuesListState::fromReturnValue(str_repeat('a', 1201)));
        $german = IssuesListState::fromArray(['assignee' => 'all', 'state' => 'any', 'site' => 'region-sued', 'perPage' => '50', 'q' => str_repeat('Überschrift ', 9)]);
        self::assertSame($german->toParameters(), IssuesListState::fromReturnValue($german->toReturnValue())?->toParameters(), 'A long German search still leads back.');
    }
}
