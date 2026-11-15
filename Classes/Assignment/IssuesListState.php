<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Assignment;

/**
 * The filters, search and page of Accessibility › My issues (MI-02..MI-06, manual QA 2026-11-14).
 *
 * One sanitised value object for the list request, the active-filter chips, the pagination links and the way back from
 * Page Detail. Only known values pass: a return to My issues is always rebuilt from these fields through the backend
 * route builder, never from a URL the request supplied (no open redirect).
 */
final class IssuesListState
{
    public const PER_PAGE_OPTIONS = [10, 25, 50];
    public const DEFAULT_PER_PAGE = 25;

    /** Page Detail query parameter that carries the list state of the My issues view the task was opened from. */
    public const RETURN_PARAMETER = 'issuesReturn';

    private function __construct(
        public readonly string $assignee,
        public readonly string $state,
        public readonly string $site,
        public readonly string $query,
        public readonly int $page,
        public readonly int $perPage,
    ) {
    }

    /**
     * @param array<string, mixed> $parameters query parameters of a My issues request (or a decoded return value)
     */
    public static function fromArray(array $parameters): self
    {
        $assignee = is_scalar($parameters['assignee'] ?? null) ? (string)$parameters['assignee'] : AssignmentService::FILTER_ME;
        if (!in_array($assignee, [AssignmentService::FILTER_ME, AssignmentService::FILTER_UNASSIGNED, AssignmentService::FILTER_ALL], true)
            && !(ctype_digit($assignee) && strlen($assignee) <= 10 && (int)$assignee > 0)
        ) {
            $assignee = AssignmentService::FILTER_ME;
        }
        $state = is_scalar($parameters['state'] ?? null) ? (string)$parameters['state'] : AssignmentService::STATE_OPEN;
        if (!in_array($state, [AssignmentService::STATE_OPEN, AssignmentService::STATE_DONE, 'any'], true)) {
            $state = AssignmentService::STATE_OPEN;
        }
        $site = is_scalar($parameters['site'] ?? null) ? (string)$parameters['site'] : '';
        if (preg_match('/^[A-Za-z0-9_.-]{1,100}$/', $site) !== 1) {
            $site = '';
        }
        $query = is_scalar($parameters['q'] ?? null) ? trim(mb_substr((string)$parameters['q'], 0, 100)) : '';
        $page = is_scalar($parameters['page'] ?? null) ? max(1, min(100000, (int)$parameters['page'])) : 1;
        $perPage = is_scalar($parameters['perPage'] ?? null) ? (int)$parameters['perPage'] : self::DEFAULT_PER_PAGE;
        if (!in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            $perPage = self::DEFAULT_PER_PAGE;
        }

        return new self($assignee, $state, $site, $query, $page, $perPage);
    }

    /** The state encoded into one Page Detail parameter (decoded and re-sanitised with fromReturnValue()). */
    public function toReturnValue(): string
    {
        return http_build_query($this->toParameters(), '', '&', PHP_QUERY_RFC3986);
    }

    public static function fromReturnValue(string $value): ?self
    {
        // The encoded form of 100 multibyte search characters plus the filters stays well below this bound.
        if ($value === '' || strlen($value) > 1200) {
            return null;
        }
        parse_str($value, $parameters);

        return self::fromArray($parameters);
    }

    public function withAssignee(string $assignee): self
    {
        return new self($assignee, $this->state, $this->site, $this->query, 1, $this->perPage);
    }

    public function withSite(string $site): self
    {
        return new self($this->assignee, $this->state, $site, $this->query, 1, $this->perPage);
    }

    public function withPage(int $page): self
    {
        return new self($this->assignee, $this->state, $this->site, $this->query, max(1, $page), $this->perPage);
    }

    /**
     * Only the non-default values, so a plain "My issues" link stays short and comparable.
     *
     * @return array<string, string|int>
     */
    public function toParameters(): array
    {
        return array_filter([
            'assignee' => $this->assignee !== AssignmentService::FILTER_ME ? $this->assignee : null,
            'state' => $this->state !== AssignmentService::STATE_OPEN ? $this->state : null,
            'site' => $this->site !== '' ? $this->site : null,
            'q' => $this->query !== '' ? $this->query : null,
            'page' => $this->page > 1 ? $this->page : null,
            'perPage' => $this->perPage !== self::DEFAULT_PER_PAGE ? $this->perPage : null,
        ], static fn ($value): bool => $value !== null);
    }

    /** The same list with one filter back at its default (the × of an active-filter chip); page 1. */
    public function without(string $filter): self
    {
        return new self(
            $filter === 'assignee' ? AssignmentService::FILTER_ME : $this->assignee,
            $filter === 'state' ? AssignmentService::STATE_OPEN : $this->state,
            $filter === 'site' ? '' : $this->site,
            $filter === 'q' ? '' : $this->query,
            1,
            $this->perPage,
        );
    }

    /** Every filter back at its default; the page size is a preference, not a filter, and is kept. */
    public function cleared(): self
    {
        return new self(AssignmentService::FILTER_ME, AssignmentService::STATE_OPEN, '', '', 1, $this->perPage);
    }

    public function hasActiveFilters(): bool
    {
        return $this->assignee !== AssignmentService::FILTER_ME || $this->state !== AssignmentService::STATE_OPEN || $this->site !== '' || $this->query !== '';
    }
}
