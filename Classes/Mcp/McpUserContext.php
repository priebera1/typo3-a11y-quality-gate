<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Mcp;

use Priebera\A11yQualityGate\Service\AccessControlService;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Context\WorkspaceAspect;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Runs code as one TYPO3 backend user without a login session (atlas 13 · D, "act as user"): the agent's call is
 * executed with exactly that user's groups, web mounts, page permissions and User TSconfig, in the live workspace, and
 * never with the CLI user's admin rights. Every AQG access service reads $GLOBALS['BE_USER'], so the user is installed
 * there (and in the Context aspects) for the duration of the call and the previous user is restored afterwards.
 *
 * A user who is deleted, disabled, outside start/end time or not allowed to connect agents
 * (options.a11y_quality_gate.mcp.connect = 1, required explicitly for administrators too) or without access to the
 * Accessibility module gets no context at all.
 */
final class McpUserContext
{
    public const TSCONFIG_CONNECT = 'mcp.connect';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly Context $context,
        private readonly LanguageServiceFactory $languageServiceFactory,
        private readonly CacheManager $cacheManager,
        private readonly SiteFinder $siteFinder,
        private readonly AccessControlService $accessControlService,
    ) {
    }

    /**
     * @template T
     * @param callable(BackendUserAuthentication): T $callback
     * @return T
     * @throws McpCallRefused when the user cannot act (missing, disabled, not allowed to connect agents)
     */
    public function runAs(int $beUserUid, callable $callback): mixed
    {
        // The connector is a long-running process: rootlines, mounts and site configuration cached by an earlier call
        // (or another user) must not decide this one. Every call reads permissions and sites fresh.
        $this->resetRuntimeState();
        $backendUser = $this->buildUser($beUserUid);

        $previousUser = $GLOBALS['BE_USER'] ?? null;
        $previousLanguage = $GLOBALS['LANG'] ?? null;
        $previousUserAspect = $this->context->hasAspect('backend.user') ? $this->context->getAspect('backend.user') : null;
        $previousWorkspaceAspect = $this->context->hasAspect('workspace') ? $this->context->getAspect('workspace') : null;

        $GLOBALS['BE_USER'] = $backendUser;
        $GLOBALS['LANG'] = $this->languageServiceFactory->createFromUserPreferences($backendUser);
        $this->context->setAspect('backend.user', new UserAspect($backendUser));
        $this->context->setAspect('workspace', new WorkspaceAspect(0));

        try {
            return $callback($backendUser);
        } finally {
            $this->resetRuntimeState();
            $GLOBALS['BE_USER'] = $previousUser;
            $GLOBALS['LANG'] = $previousLanguage;
            if ($previousUserAspect !== null) {
                $this->context->setAspect('backend.user', $previousUserAspect);
            } else {
                $this->context->unsetAspect('backend.user');
            }
            if ($previousWorkspaceAspect !== null) {
                $this->context->setAspect('workspace', $previousWorkspaceAspect);
            } else {
                $this->context->unsetAspect('workspace');
            }
        }
    }

    /** Whether a (logged-in or constructed) backend user may connect agents. Administrators need it explicitly. */
    public static function mayConnectAgents(BackendUserAuthentication $backendUser): bool
    {
        $value = $backendUser->getTSConfig()['options.']['a11y_quality_gate.']['mcp.']['connect'] ?? null;

        return $value === 1 || $value === '1';
    }

    private function resetRuntimeState(): void
    {
        $this->cacheManager->getCache('runtime')->flush();
        $this->siteFinder->getAllSites(false);
    }

    private function buildUser(int $beUserUid): BackendUserAuthentication
    {
        if ($beUserUid <= 0 || !$this->isActiveUserRow($beUserUid)) {
            throw new McpCallRefused('user_not_active', 'The TYPO3 user of this agent is disabled or no longer exists.');
        }

        // No session and no cookie: setBeUserByUid() only loads the user row (start() is never called here). The cookie
        // flag is protected on TYPO3 13, so it is not touched.
        $backendUser = GeneralUtility::makeInstance(BackendUserAuthentication::class);
        $backendUser->setBeUserByUid($beUserUid);
        if (!is_array($backendUser->user) || (int)($backendUser->user['uid'] ?? 0) !== $beUserUid) {
            throw new McpCallRefused('user_not_active', 'The TYPO3 user of this agent is disabled or no longer exists.');
        }
        $backendUser->fetchGroupData();
        if (!$backendUser->setTemporaryWorkspace(0)) {
            throw new McpCallRefused('no_live_workspace', 'The TYPO3 user of this agent has no access to the live workspace.');
        }
        if (!self::mayConnectAgents($backendUser)) {
            throw new McpCallRefused('connect_not_allowed', 'The TYPO3 user of this agent may no longer connect agents.');
        }
        // Like every AQG backend route (inheritAccessFromModule web_a11y): a user who lost the module since the consent
        // gets no agent call either.
        if (!$this->accessControlService->canAccessModule($backendUser)) {
            throw new McpCallRefused('module_access_denied', 'The TYPO3 user of this agent no longer has access to the Accessibility module.');
        }

        return $backendUser;
    }

    private function isActiveUserRow(int $beUserUid): bool
    {
        $now = time();
        $row = $this->connectionPool->getConnectionForTable('be_users')->fetchAssociative(
            'SELECT uid FROM be_users WHERE uid = ? AND deleted = 0 AND disable = 0'
            . ' AND starttime <= ? AND (endtime = 0 OR endtime > ?)',
            [$beUserUid, $now, $now],
        );

        return is_array($row);
    }
}
