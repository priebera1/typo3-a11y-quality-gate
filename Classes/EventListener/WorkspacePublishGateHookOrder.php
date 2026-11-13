<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\EventListener;

use Priebera\A11yQualityGate\Hook\WorkspacePublishGateHook;
use TYPO3\CMS\Core\Core\Event\BootCompletedEvent;

/**
 * The workspace publish gate must see the command map after EXT:workspaces resolved its dependencies (both work in
 * processCmdmap_beforeStart(), in the order of the hook list). Package order alone does not guarantee that, so once all
 * ext_localconf.php files are loaded the gate's hook is moved to the end of the list.
 */
final class WorkspacePublishGateHookOrder
{
    public const HOOK_KEY = 'a11y_quality_gate_workspace_publish';

    public function __invoke(BootCompletedEvent $event): void
    {
        $hooks = &$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass'];
        if (!is_array($hooks) || ($hooks[self::HOOK_KEY] ?? null) !== WorkspacePublishGateHook::class) {
            return;
        }
        unset($hooks[self::HOOK_KEY]);
        $hooks[self::HOOK_KEY] = WorkspacePublishGateHook::class;
    }
}
