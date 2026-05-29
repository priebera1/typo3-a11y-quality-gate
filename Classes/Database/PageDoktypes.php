<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Database;

use TYPO3\CMS\Core\Domain\Repository\PageRepository;

final class PageDoktypes
{
    public const STANDARD_PAGE = PageRepository::DOKTYPE_DEFAULT; // 1
    public const ADVANCED_PAGE = 2;
    public const EXTERNAL_URL = PageRepository::DOKTYPE_LINK; // 3
    public const SHORTCUT = PageRepository::DOKTYPE_SHORTCUT; // 4
    public const BACKEND_USER_SECTION = PageRepository::DOKTYPE_BE_USER_SECTION; // 6
    public const MOUNTPOINT = PageRepository::DOKTYPE_MOUNTPOINT; // 7
    public const SPACER = PageRepository::DOKTYPE_SPACER; // 199, also labelled Menu Separator in the page tree
    public const SYS_FOLDER = PageRepository::DOKTYPE_SYSFOLDER; // 254

    /**
     * Backwards-compatible aliases used by older AQG code.
     */
    public const MENU_SEPARATOR = self::SPACER;

    /**
     * Page doktypes that are not regular frontend pages.
     *
     * AQG intentionally uses a denylist here instead of an allowlist. Large TYPO3
     * projects often register custom page doktypes that still render normal
     * frontend output, and rendered checks should support those by default.
     *
     * Skipped for rendered checks:
     * - 3: External URL
     * - 4: Shortcut
     * - 6: Backend user section
     * - 7: Mountpoint
     * - 199: Spacer / Menu Separator
     * - 254: Folder
     *
     * @var int[]
     */
    public const NON_FRONTEND_PAGE_DOKTYPES = [
        self::EXTERNAL_URL,
        self::SHORTCUT,
        self::BACKEND_USER_SECTION,
        self::MOUNTPOINT,
        self::SPACER,
        self::SYS_FOLDER,
    ];

    public static function supportsRenderedCheck(int $doktype): bool
    {
        return !self::isNonFrontendPageDoktype($doktype);
    }

    public static function isNonFrontendPageDoktype(int $doktype): bool
    {
        return in_array($doktype, self::NON_FRONTEND_PAGE_DOKTYPES, true);
    }
}
