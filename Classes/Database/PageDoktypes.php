<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Database;

final class PageDoktypes
{
    public const RECYCLER = 199;
    public const SYS_FOLDER = 254;
    public const MENU_SEPARATOR = 255;

    /**
     * Document types that we do not want to consider as regular frontend pages.
     *
     * @var int[]
     */
    public const NON_FRONTEND_PAGE_DOKTYPES = [
        self::RECYCLER,
        self::SYS_FOLDER,
        self::MENU_SEPARATOR,
    ];
}