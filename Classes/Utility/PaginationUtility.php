<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Utility;

final class PaginationUtility
{
    public static function buildPagination(int $totalItems, int $currentPage, int $perPage): array
    {
        $totalPages = max(1, (int)ceil($totalItems / $perPage));
        $currentPage = min(max(1, $currentPage), $totalPages);

        return [
            'currentPage' => $currentPage,
            'totalPages' => $totalPages,
            'totalItems' => $totalItems,
            'offset' => ($currentPage - 1) * $perPage,
            'hasPrevious' => $currentPage > 1,
            'hasNext' => $currentPage < $totalPages,
        ];
    }

    /**
     * @param array<int, string> $pageUrls
     * @return array<int, array<string, mixed>>
     */
    public static function buildPaginationItems(
        int $currentPage,
        int $totalPages,
        array $pageUrls,
        int $maxVisibleItems = 5,
    ): array {
        if ($totalPages <= $maxVisibleItems) {
            $items = [];

            for ($page = 1; $page <= $totalPages; $page++) {
                $items[] = [
                    'type' => 'page',
                    'label' => (string)$page,
                    'url' => $pageUrls[$page] ?? '#',
                    'active' => $page === $currentPage,
                ];
            }

            return $items;
        }

        $pages = [1, $totalPages, $currentPage - 1, $currentPage, $currentPage + 1];
        $pages = array_values(array_unique(array_filter(
            $pages,
            static fn(int $page): bool => $page >= 1 && $page <= $totalPages
        )));
        sort($pages);

        $items = [];
        $lastPage = null;

        foreach ($pages as $page) {
            if ($lastPage !== null && $page > $lastPage + 1) {
                $items[] = [
                    'type' => 'ellipsis',
                    'label' => '…',
                    'url' => '',
                    'active' => false,
                ];
            }

            $items[] = [
                'type' => 'page',
                'label' => (string)$page,
                'url' => $pageUrls[$page] ?? '#',
                'active' => $page === $currentPage,
            ];

            $lastPage = $page;
        }

        return $items;
    }
}