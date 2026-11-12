<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Hook;

use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\QualityGate\Contract\GateLicenceInterface;
use Priebera\A11yQualityGate\QualityGate\QualityGateChecker;
use Priebera\A11yQualityGate\QualityGate\QualityGateVerdict;
use Priebera\A11yQualityGate\QualityGate\WorkspacePublishEvaluator;
use Priebera\A11yQualityGate\Service\BackendContextService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Priebera\A11yQualityGate\Utility\BackendLabelUtility;
use Priebera\A11yQualityGate\Workspace\PublishPreviewContext;
use Priebera\A11yQualityGate\Workspace\WorkspacePublishSet;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\SysLog\Action\Database as DatabaseAction;
use TYPO3\CMS\Core\SysLog\Error as SystemLogErrorClassification;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

/**
 * The Quality Gate for publishing a workspace (EXT:workspaces "Publish", stage "Publish", publish the whole workspace,
 * auto-publish): every path is a DataHandler command map with `version` → `publish`/`swap` commands.
 *
 * It runs in processCmdmap_beforeStart(), after EXT:workspaces resolved the dependencies of the command map
 * (WorkspacePublishGateHookOrder keeps this hook last) and before DataHandler processes any command. When the request
 * makes a page visible — the trigger of the live gate — the content that this very request leaves on that page is
 * checked (PublishPreviewContext, nothing stored). A blocking verdict removes every publish command of the request:
 * nothing is published, the live records stay as they are, and the reason is logged on the DataHandler (shown by the
 * Workspaces module). A warning lets the publish happen and says why. As for the live gate, a check that cannot run
 * does not stop the publish, and blocking needs an active licence.
 */
final class WorkspacePublishGateHook
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly PublishPreviewContext $publishPreview,
        private readonly WorkspacePublishEvaluator $evaluator,
        private readonly QualityGateChecker $qualityGateChecker,
        private readonly SiteResolutionService $siteResolutionService,
        private readonly GateLicenceInterface $gateLicence,
        private readonly BackendContextService $backendContextService,
    ) {
    }

    public function processCmdmap_beforeStart(DataHandler $dataHandler): void
    {
        if (WorkspacePublishSet::publishCommands($dataHandler->cmdmap) === []) {
            return;
        }
        $rawRow = fn (string $table, int $uid): ?array => $this->rawRow($table, $uid);
        $set = WorkspacePublishSet::fromCommandMap($dataHandler->cmdmap, $rawRow);
        if ($set === null) {
            return;
        }

        $blocked = [];
        foreach ($this->withTranslationsGoingLive($set, $set->pagesBecomingVisible($rawRow)) as $page) {
            $languageUid = (int)($page['sys_language_uid'] ?? 0);
            $pageUid = $languageUid > 0 && (int)($page['l10n_parent'] ?? 0) > 0 ? (int)$page['l10n_parent'] : (int)$page['uid'];
            $site = $this->resolveSite($pageUid);
            if (!$site instanceof Site || !$this->qualityGateChecker->isEnabledForSite($site->getIdentifier())) {
                continue;
            }
            $title = trim((string)($page['title'] ?? '')) ?: ('#' . $pageUid);
            try {
                $counts = $this->publishPreview->run($set, fn (): array => $this->evaluator->countsFor($site->getIdentifier(), $pageUid, $languageUid));
            } catch (\Throwable) {
                $this->flash(
                    sprintf($this->translate('publish.workspace.notChecked', 'The Quality Gate could not check page "%s" before publishing. It was published without a decision; run a content scan of it.'), $title),
                    ContextualFeedbackSeverity::WARNING,
                );
                continue;
            }
            $verdict = $this->qualityGateChecker->verdictForCounts($site->getIdentifier(), $counts);
            if ($verdict->isPassed()) {
                continue;
            }
            if ($verdict->isBlockingMode() && !$verdict->isWarningOnly() && $this->gateLicence->canBlock($site)) {
                $blocked[] = [(int)$page['uid'], $title, $verdict];
                continue;
            }
            $this->flash(
                sprintf($this->translate('publish.workspace.warning', 'Page "%s" was published with accessibility issues: %s.'), $title, $this->reasons($verdict)),
                ContextualFeedbackSeverity::WARNING,
            );
        }

        if ($blocked === []) {
            return;
        }
        // Nothing of this request is published: its commands were resolved as one consistent set (dependencies), so a
        // part of it could leave hanging records. Other commands of the map are kept.
        $dataHandler->cmdmap = WorkspacePublishSet::withoutPublishCommands($dataHandler->cmdmap);
        foreach ($blocked as [$recordUid, $title, $verdict]) {
            $dataHandler->log(
                Tables::PAGES,
                $recordUid,
                DatabaseAction::PUBLISH,
                null,
                SystemLogErrorClassification::USER_ERROR,
                $this->translate('publish.workspace.blocked', 'AQG Quality Gate: publishing stopped for page "{title}": {reasons}. Nothing of this publish request was published. Fix the issues in the workspace and publish again.'),
                null,
                ['title' => $title, 'reasons' => $this->reasons($verdict)],
            );
            // The Workspaces module does not show DataHandler errors of a single publish; the queued message appears
            // on the next backend page (and the refusal stays in the Log module).
            $this->backendContextService->addFlashMessage(
                sprintf($this->translate('publish.workspace.blockedNotice', 'Publishing stopped for page "%s": %s. Nothing of this publish request was published. Fix the issues in the workspace and publish again.'), $title, $this->reasons($verdict)),
                ContextualFeedbackSeverity::ERROR,
                'AQG Quality Gate',
                true,
            );
        }
    }

    /**
     * ML-05 (as ML-01 for the live gate): a hidden default-language page keeps its translations offline, so publishing
     * it visible also puts every visible live translation online. Those translations are judged too, each in its own
     * language. A translation this publish changes is taken as published; hidden, deleted and draft translations and
     * languages the site does not serve stay offline and are not judged; a translation already in the set is not
     * judged twice.
     *
     * @param list<array<string, mixed>> $pages
     * @return list<array<string, mixed>>
     */
    private function withTranslationsGoingLive(WorkspacePublishSet $set, array $pages): array
    {
        $listed = [];
        foreach ($pages as $page) {
            $listed[(int)($page['uid'] ?? 0)] = true;
        }

        $all = $pages;
        foreach ($pages as $page) {
            $pageUid = (int)($page['uid'] ?? 0);
            if ($pageUid <= 0 || (int)($page['sys_language_uid'] ?? 0) !== 0) {
                continue;
            }
            $site = $this->resolveSite($pageUid);
            if (!$site instanceof Site) {
                continue;
            }
            try {
                $connection = $this->connectionPool->getConnectionForTable(Tables::PAGES);
                $translations = $connection->fetchAllAssociative(
                    'SELECT * FROM ' . $connection->quoteIdentifier(Tables::PAGES)
                    . ' WHERE l10n_parent = ? AND sys_language_uid > 0 AND deleted = 0 AND t3ver_wsid = 0 ORDER BY sys_language_uid',
                    [$pageUid],
                );
            } catch (\Throwable) {
                continue;
            }
            foreach ($translations as $translation) {
                $translationUid = (int)($translation['uid'] ?? 0);
                // A translation this publish deletes goes offline with it: not judged.
                if ($translationUid <= 0 || isset($listed[$translationUid]) || $set->deletes(Tables::PAGES, $translationUid)) {
                    continue;
                }
                $published = $set->publishedVersionOf(Tables::PAGES, $translationUid) ?? $translation;
                if ((int)($published['hidden'] ?? 0) !== 0 || (int)($published['deleted'] ?? 0) !== 0) {
                    continue;
                }
                try {
                    if (!$site->getLanguageById((int)$translation['sys_language_uid'])->isEnabled()) {
                        continue;
                    }
                } catch (\Throwable) {
                    // Not a language of this site: the frontend does not serve it, so nothing goes live.
                    continue;
                }
                $listed[$translationUid] = true;
                $all[] = $published;
            }
        }

        return $all;
    }

    private function reasons(QualityGateVerdict $verdict): string
    {
        $reasons = [];
        foreach ($verdict->reasonDetails as $detail) {
            $singular = (int)$detail['count'] === 1;
            $template = $detail['severity'] === 'critical'
                ? ($singular
                    ? $this->translate('publish.flash.reason.critical.singular', '%1$d critical issue exceeds threshold %2$d')
                    : $this->translate('publish.flash.reason.critical.plural', '%1$d critical issues exceed threshold %2$d'))
                : ($singular
                    ? $this->translate('publish.flash.reason.warning.singular', '%1$d warning exceeds threshold %2$d')
                    : $this->translate('publish.flash.reason.warning.plural', '%1$d warnings exceed threshold %2$d'));
            $reasons[] = sprintf($template, $detail['count'], $detail['threshold']);
        }

        return implode('; ', $reasons !== [] ? $reasons : $verdict->reasons);
    }

    /**
     * The site of a page. A page created in the workspace may not be found from the live context (auto-publish, CLI);
     * its live parents name the site then.
     */
    private function resolveSite(int $pageUid): ?Site
    {
        for ($uid = $pageUid, $depth = 0; $uid > 0 && $depth < 50; $depth++) {
            $site = $this->siteResolutionService->resolveSiteByPageId($uid);
            if ($site instanceof Site) {
                return $site;
            }
            $uid = (int)($this->rawRow(Tables::PAGES, $uid)['pid'] ?? 0);
        }

        return null;
    }

    /** @return array<string, mixed>|null the row as stored, without any restriction */
    private function rawRow(string $table, int $uid): ?array
    {
        if ($uid <= 0) {
            return null;
        }
        // Not Connection::select(): its default restrictions would hide the hidden live page this gate is about.
        $connection = $this->connectionPool->getConnectionForTable($table);
        $row = $connection->fetchAssociative('SELECT * FROM ' . $connection->quoteIdentifier($table) . ' WHERE uid = ?', [$uid]);

        return is_array($row) ? $row : null;
    }

    private function flash(string $message, ContextualFeedbackSeverity $severity): void
    {
        $this->backendContextService->addFlashMessage($message, $severity, $this->translate('publish.flash.warningTitle', 'AQG Warning'), true);
    }

    private function translate(string $key, string $fallback): string
    {
        $translated = BackendLabelUtility::translate($key, $fallback);

        return $translated !== '' ? $translated : $fallback;
    }
}
