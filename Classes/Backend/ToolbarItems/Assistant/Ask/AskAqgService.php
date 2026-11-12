<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Backend\ToolbarItems\Assistant\Ask;

use Priebera\A11yQualityGate\Ai\Editor\EditorAiAccessService;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiErrorResponse;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiException;
use Priebera\A11yQualityGate\Ai\Exception\AiProviderException;
use Priebera\A11yQualityGate\Ai\Settings\AiSiteSettingsService;
use Priebera\A11yQualityGate\Ai\Task\AiStructuredRequest;
use Priebera\A11yQualityGate\Ai\Task\AiStructuredTaskRunner;
use Priebera\A11yQualityGate\Ai\Task\AiTask;
use Priebera\A11yQualityGate\Ai\Usage\AiActionCostEstimator;
use Priebera\A11yQualityGate\Backend\ToolbarItems\Assistant\ToolbarAssistantContext;
use Priebera\A11yQualityGate\Backend\ToolbarItems\Assistant\ToolbarAssistantService;
use Priebera\A11yQualityGate\Backend\ToolbarItems\Assistant\ToolbarFindingGrouper;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiUsageRepositoryInterface;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Domain\Repository\ScanRepository;
use Priebera\A11yQualityGate\Service\BackendContextService;
use Priebera\A11yQualityGate\Service\BackendRecordAccessService;
use Priebera\A11yQualityGate\Service\BackendUserService;
use Priebera\A11yQualityGate\Service\IssueLocationService;
use Priebera\A11yQualityGate\Service\LocalIssueGuidanceService;
use Priebera\A11yQualityGate\Service\ScopeAccessService;
use Priebera\A11yQualityGate\Utility\BackendTimeUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Locking\Exception\LockAcquireWouldBlockException;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;

/**
 * "Ask AQG": questions about the stored AQG findings of what the editor has open (page scope) or of the website pages
 * they may access (site scope).
 *
 * 1. Deterministic answers first. "What should I fix first?", "Why is this an accessibility issue?", "Where can I fix
 *    this?" and "What should I improve on this website?" are answered from the authorised findings, AQG's rule
 *    guidance and server-built links, without any AI request — also on FREE and without an AI configuration.
 * 2. Other questions go to the site's configured provider as ONE structured request through AiStructuredTaskRunner
 *    (licence, privacy switches, rate limit, verified model and acknowledgement, budget reservation under lock,
 *    one usage row as feature "assistant"). No tool loop, no retry, no model fallback.
 *
 * Scope and permissions come only from the server-resolved context (ToolbarAssistantContextResolver) and the user's
 * TYPO3 permissions, re-checked on every request. The browser holds the conversation; the server stores none of it.
 * Earlier turns the browser sends back are bounded (AskAqgLimits), treated as untrusted data, and accepted only with the
 * turn token this server issued for that answer in the same conversation; the conversation id itself is bound to the
 * backend user, the login session and the resolved context (conversationBinding()), so history of another user, login,
 * page, language or site is dropped before anything is sent (PI-01/PI-02). Each AI question
 * belongs to a server-signed conversation whose request count and estimated spend are summed from the usage rows and
 * capped (a usability limit: "New conversation" starts a new one); the hard limits are the per-user daily AI question
 * cap, also from the usage rows, the site's monthly budget, its rate limit, and one AI question per user at a time. Nothing here writes content: answers are text, and any change goes
 * through the existing Fix with AI / AI fixes review.
 */
final class AskAqgService
{
    public const SCOPE_PAGE = 'page';
    public const SCOPE_SITE = 'site';
    public const INTENT_FIRST = 'first';
    public const INTENT_WHY = 'why';
    public const INTENT_WHERE = 'where';
    public const INTENT_SITE = 'site';
    public const INTENTS = [self::INTENT_FIRST, self::INTENT_WHY, self::INTENT_WHERE, self::INTENT_SITE];
    /** An explicit request to fix content: answered by AskAqgRepairService (no AI request; actions only on a click). */
    public const INTENT_REPAIR = 'repair';
    public const MAX_GROUPS = 12;
    public const MAX_DETERMINISTIC_ITEMS = 3;
    public const MAX_DETERMINISTIC_SITE_ITEMS = 5;
    private const MAX_GUIDANCE_LENGTH = 400;

    /**
     * The example questions, normalised (lowercase, letters/digits/spaces), that are answered deterministically when
     * typed exactly. Anything else is a free-form AI question.
     */
    private const INTENT_PHRASES = [
        'what should i fix first' => self::INTENT_FIRST,
        'which issues are most important' => self::INTENT_FIRST,
        'was sollte ich zuerst beheben' => self::INTENT_FIRST,
        'welche probleme sind am wichtigsten' => self::INTENT_FIRST,
        'why is this an accessibility issue' => self::INTENT_WHY,
        'warum ist das ein barrierefreiheitsproblem' => self::INTENT_WHY,
        'where can i fix these problems' => self::INTENT_WHERE,
        'where can i fix this problem' => self::INTENT_WHERE,
        'where can i fix the missing alt text' => self::INTENT_WHERE,
        'show me where i can fix the missing alt text' => self::INTENT_WHERE,
        'wo kann ich diese probleme beheben' => self::INTENT_WHERE,
        'wo kann ich den fehlenden alternativtext ergänzen' => self::INTENT_WHERE,
        'what should i improve on this website' => self::INTENT_SITE,
        'how can i improve the accessibility of this website' => self::INTENT_SITE,
        'was sollte ich auf dieser website verbessern' => self::INTENT_SITE,
    ];

    public function __construct(
        private readonly ToolbarAssistantService $assistantService,
        private readonly EditorAiAccessService $editorAiAccessService,
        private readonly AiStructuredTaskRunner $taskRunner,
        private readonly AiSiteSettingsService $siteSettingsService,
        private readonly AiUsageRepositoryInterface $usageRepository,
        private readonly IssueRepository $issueRepository,
        private readonly ScanRepository $scanRepository,
        private readonly ScopeAccessService $scopeAccessService,
        private readonly BackendRecordAccessService $backendRecordAccessService,
        private readonly BackendUserService $backendUserService,
        private readonly BackendContextService $backendContextService,
        private readonly LocalIssueGuidanceService $localIssueGuidanceService,
        private readonly IssueLocationService $issueLocationService,
        private readonly ?AiActionCostEstimator $costEstimator = null,
        private readonly ?LockFactory $lockFactory = null,
        private readonly ?AskAqgRepairService $repairService = null,
    ) {}

    /** The site a question about this context is answered for ('' when there is none). */
    public static function siteOf(ToolbarAssistantContext $context, array $issues = []): string
    {
        return $context->siteIdentifier !== '' ? $context->siteIdentifier : (string)($issues[0]['site_identifier'] ?? '');
    }

    /**
     * The findings stored for one site (a file context collects the findings of every site that uses the file).
     *
     * @param list<array<string, mixed>> $issues
     * @return list<array<string, mixed>>
     */
    public static function issuesOfSite(array $issues, string $siteIdentifier): array
    {
        return array_values(array_filter($issues, static fn (array $issue): bool => (string)($issue['site_identifier'] ?? '') === $siteIdentifier));
    }

    /** Whether a website-wide answer may be given: the site root is readable and so is the language. */
    public function canAskSite(ToolbarAssistantContext $context): bool
    {
        $user = $this->backendUserService->getBackendUser();

        return $user instanceof BackendUserAuthentication
            && $context->siteIdentifier !== ''
            && in_array($context->type, [ToolbarAssistantContext::TYPE_PAGE, ToolbarAssistantContext::TYPE_RECORD], true)
            && $this->scopeAccessService->canReadSiteIdentifier($context->siteIdentifier)
            && $user->checkLanguageAccess(max(0, $context->languageUid));
    }

    /** The deterministic intent of an explicit intent id or of a typed example question, '' for a free-form question. */
    public static function intentOf(string $intent, string $question): string
    {
        if (in_array($intent, self::INTENTS, true)) {
            return $intent;
        }
        $normalised = trim((string)preg_replace('/\s+/u', ' ', (string)preg_replace('/[^\p{L}\p{N}\s]/u', ' ', mb_strtolower($question))));

        return self::INTENT_PHRASES[$normalised] ?? '';
    }

    /**
     * @param list<mixed> $history earlier turns held by the browser: [{question, answer}]
     * @return array<string,mixed>
     * @throws EditorAiException with a stable code for every refusal
     */
    public function ask(ToolbarAssistantContext $context, string $question, string $scope, string $intent = '', array $history = [], string $conversationId = '', string $repairTarget = ''): array
    {
        if (!$this->backendUserService->isLoggedIn()) {
            throw new EditorAiException('session_expired', 'Not logged in.', 401);
        }
        // A request to fix content is recognised deterministically (no AI request, no cost) and answered with the repair
        // workflow's plan: what can be prepared through AI Fixes, what needs manual work and why. Information questions
        // continue below unchanged.
        if ($this->repairService !== null && !in_array($intent, self::INTENTS, true)) {
            $repair = AskAqgRepairIntent::detect($question);
            if ($intent === self::INTENT_REPAIR || $repair['repair']) {
                return $this->repairService->plan($context, $scope, $question, $repair, $repairTarget);
            }
        }
        $deterministic = self::intentOf($intent, $question);
        if ($deterministic !== '') {
            return $this->answerDeterministically($context, $deterministic, $question);
        }
        // Without usable AI (FREE, not set up, paused, budget, the user's group) every question is answered from the
        // findings: routed by its words, never refused for lack of AI and never sent anywhere.
        $state = $this->aiStateFor($context);
        if ($state !== EditorAiAccessService::STATE_READY) {
            if (self::normaliseQuestion($question) === '') {
                throw new EditorAiException('invalid_input', 'Ask a question of 3 to 400 characters.', 422);
            }
            $routed = self::keywordIntent($question);
            if ($routed === self::INTENT_SITE && !$this->canAskSite($context)) {
                $routed = self::INTENT_FIRST;
            }
            $answer = $this->answerDeterministically($context, $routed, $question);
            $answer['notes'][] = $this->t('toolbar.ask.note.noAi', 'Answered from your AQG findings without AI.');

            return $answer;
        }

        try {
            return $this->answerWithAi($context, $question, $scope, $history, $conversationId);
        } catch (AiProviderException $exception) {
            // The attempt was admitted and settled by AiUsageRecorder (its cost and the caps count as for any attempt), but
            // no usable AI answer came back. The question is answered from the findings instead, marked as such (source
            // "aqg", "no AI used") with a note naming what failed, and nothing further is sent. Earlier 3.0.0 candidates
            // answered only a rejected key or model this way; a timeout, an unreachable provider, its rate limit or
            // a billing problem surfaced as a 502 that proxies in front of TYPO3 may replace with their own page, and the
            // toolbar then showed only "This did not work". A rejected key or model (VERIFICATION_LOST_CODES) has already
            // reset the stored verification, so later questions skip AI until an administrator fixes the connection.
            $failure = EditorAiErrorResponse::providerCode((int)$exception->getCode());
            $answer = $this->answerDeterministically($context, $this->fallbackIntent($context, $question, $scope), $question);
            $answer['notes'][] = $this->providerFailureNote($failure);
            $answer['aiFailure'] = $failure;

            return $answer;
        }
    }

    /**
     * The findings answer that stands in for an AI answer: the scope the user chose ("This website" with access to the
     * site root answers about the website), else routed by the question's words like the answers without AI.
     */
    private function fallbackIntent(ToolbarAssistantContext $context, string $question, string $scope): string
    {
        if ($scope === self::SCOPE_SITE && $this->canAskSite($context)) {
            return self::INTENT_SITE;
        }
        $routed = self::keywordIntent($question);

        return $routed === self::INTENT_SITE && !$this->canAskSite($context) ? self::INTENT_FIRST : $routed;
    }

    /**
     * What failed, for the note on a findings answer that replaced an AI answer: one sentence per stable provider
     * code (EditorAiErrorResponse::providerCode()), never the provider's own message.
     */
    private function providerFailureNote(string $safeCode): string
    {
        if ($safeCode === 'paused') {
            // 401 key rejected, 404 model unavailable, 403 model not permitted (AiUsageRecorder::VERIFICATION_LOST_CODES).
            return $this->t('toolbar.ask.note.aiRejected', 'AI is paused: the AI provider rejected the key or model of this site. Answered from your AQG findings without AI. An administrator can check the connection in Accessibility › Settings › AI suggestions.');
        }

        return match ($safeCode) {
            'provider_timeout' => $this->t('toolbar.ask.note.aiTimeout', 'The AI provider did not answer in time. Answered from your AQG findings without AI. Ask again later for an AI answer.'),
            'provider_rate_limited' => $this->t('toolbar.ask.note.aiRateLimited', 'The AI provider is limiting requests right now. Answered from your AQG findings without AI. Ask again in a few minutes.'),
            'provider_credit' => $this->t('toolbar.ask.note.aiCredit', 'The AI provider account has no credit left or a billing problem. Answered from your AQG findings without AI. An administrator can check the account with the AI provider.'),
            'refused' => $this->t('toolbar.ask.note.aiRefused', 'The AI provider declined to answer this question. Answered from your AQG findings without AI.'),
            'invalid_response' => $this->t('toolbar.ask.note.aiInvalid', 'The AI answer could not be used. Answered from your AQG findings without AI. Ask again or rephrase.'),
            default => $this->t('toolbar.ask.note.aiUnavailable', 'The AI provider could not be reached. Answered from your AQG findings without AI. Ask again later for an AI answer.'),
        };
    }

    /** The user-aware AI state for the site of this context ('none' without a site). */
    private function aiStateFor(ToolbarAssistantContext $context): string
    {
        $site = self::siteOf($context, $context->siteIdentifier === '' && $context->type === ToolbarAssistantContext::TYPE_FILE ? $this->assistantService->openIssues($context) : []);

        return $site !== '' ? $this->editorAiAccessService->state($site) : 'none';
    }

    /** The deterministic answer for a free question when no AI is used: why, where, website, else what to fix first. */
    public static function keywordIntent(string $question): string
    {
        $text = ' ' . mb_strtolower($question) . ' ';

        return match (true) {
            preg_match('/\b(why|warum|wieso|weshalb|matter|wichtig)\b/u', $text) === 1 => self::INTENT_WHY,
            preg_match('/\b(where|wo|alt|alternativ\w*|image|images|bild\w*|find|finde\w*)\b/u', $text) === 1 => self::INTENT_WHERE,
            preg_match('/\b(website|site|webseite|whole|gesamte\w*|all pages|alle seiten)\b/u', $text) === 1 => self::INTENT_SITE,
            default => self::INTENT_FIRST,
        };
    }

    /**
     * An answer from the authorised findings, AQG's rule guidance and server-built links. No AI request.
     *
     * @return array<string,mixed>
     */
    public function answerDeterministically(ToolbarAssistantContext $context, string $intent, string $question = ''): array
    {
        $language = $this->language();
        if ($intent === self::INTENT_SITE) {
            if (!$this->canAskSite($context)) {
                throw new EditorAiException('scope_restricted', 'A website-wide answer needs access to the site root.', 403);
            }
            $prepared = $this->siteFindings($context->siteIdentifier, max(0, $context->languageUid), $language);
            $notes = $this->siteNotes($context->siteIdentifier, max(0, $context->languageUid));
            $items = array_slice($prepared['findings'], 0, self::MAX_DETERMINISTIC_SITE_ITEMS);
            $lines = [];
            foreach ($items as $finding) {
                $link = $prepared['links'][$finding['ref']];
                $lines[] = '- ' . sprintf(
                    $this->t('toolbar.ask.det.siteItem', '%1$s: %2$s, %3$d occurrences on %4$d pages.'),
                    $link['severityLabel'],
                    $finding['title'],
                    $finding['occurrences'],
                    $finding['pages'],
                ) . ($finding['how_to_fix'] !== '' ? ' ' . $finding['how_to_fix'] : '');
            }
            $answer = $lines === []
                ? $this->t('toolbar.ask.det.siteNone', 'AQG stores no open content findings for the pages you may access. That does not prove the website is accessible; a new content scan shows the current state.')
                : $this->t('toolbar.ask.det.siteIntro', 'Start with these issue types, most severe first:') . "\n" . implode("\n", $lines);

            return $this->deterministicPayload($intent, self::SCOPE_SITE, $question, $answer, $lines === [] ? '' : $this->t('toolbar.ask.det.siteNext', 'Open the first issue type on the page with the most occurrences.'), array_map(static fn (array $finding): string => $finding['ref'], $items), $prepared, $notes);
        }

        if (!in_array($context->type, [ToolbarAssistantContext::TYPE_PAGE, ToolbarAssistantContext::TYPE_RECORD, ToolbarAssistantContext::TYPE_FILE], true)) {
            throw new EditorAiException('no_context', 'Open a page, a content element or a file first.', 422);
        }
        $issues = $this->assistantService->openIssues($context);
        $prepared = $this->pageFindings($issues, $context, $language);
        $notes = $this->pageNotes($context);
        $findings = $prepared['findings'];
        $altOnly = $intent === self::INTENT_WHERE && preg_match('/\b(alt|alternativ\w*|image|images|bild\w*)\b/iu', $question) === 1;
        if ($altOnly) {
            $findings = array_values(array_filter($findings, static fn (array $finding): bool => preg_match('/alt|image|img/i', (string)$finding['rule_id']) === 1));
        }
        $items = array_slice($findings, 0, self::MAX_DETERMINISTIC_ITEMS);

        if ($items === []) {
            $answer = $altOnly
                ? $this->t('toolbar.ask.det.noAlt', 'AQG stores no open finding about missing alternative text for this.')
                : $this->t('toolbar.ask.det.none', 'AQG stores no open issues for this that you can access. That does not prove it is accessible; a new content check shows the current state.');

            return $this->deterministicPayload($intent, self::SCOPE_PAGE, $question, $answer, '', [], $prepared, $notes);
        }

        $lines = [];
        foreach ($items as $finding) {
            $link = $prepared['links'][$finding['ref']];
            $count = $finding['occurrences'] > 1 ? ' (' . sprintf($this->t('toolbar.assistant.spread.occurrences', '%d occurrences'), $finding['occurrences']) . ')' : '';
            $lines[] = '- ' . match ($intent) {
                self::INTENT_WHY => $finding['title'] . ': ' . ($finding['why_it_matters'] !== '' ? $finding['why_it_matters'] : $this->t('toolbar.ask.det.noWhy', 'Page Detail explains this rule.')),
                self::INTENT_WHERE => $finding['title'] . ': ' . ($link['location'] !== '' ? $link['location'] : $link['sourceLabel']) . $count,
                default => $link['severityLabel'] . ': ' . $finding['title'] . $count . ($finding['how_to_fix'] !== '' ? '. ' . $finding['how_to_fix'] : '.'),
            };
        }
        $intro = match ($intent) {
            self::INTENT_WHY => $this->t('toolbar.ask.det.whyIntro', 'Why these issues matter:'),
            self::INTENT_WHERE => $this->t('toolbar.ask.det.whereIntro', 'Where to fix them (use Edit or Details below):'),
            default => $this->t('toolbar.ask.det.firstIntro', 'Fix these first, most severe first:'),
        };
        $next = match ($intent) {
            self::INTENT_WHERE => $this->t('toolbar.ask.det.whereNext', 'Use Edit to open the element, or Details for every occurrence in Page Detail.'),
            default => $this->t('toolbar.ask.det.firstNext', 'Open the first one in Page Detail, fix it, then check the saved page again.'),
        };

        return $this->deterministicPayload($intent, self::SCOPE_PAGE, $question, $intro . "\n" . implode("\n", $lines), $next, array_map(static fn (array $finding): string => $finding['ref'], $items), $prepared, $notes);
    }

    /**
     * @param list<mixed> $history
     * @return array<string,mixed>
     */
    private function answerWithAi(ToolbarAssistantContext $context, string $question, string $scope, array $history, string $conversationId): array
    {
        $question = self::normaliseQuestion($question);
        if ($question === '') {
            throw new EditorAiException('invalid_input', 'Ask a question of 3 to 400 characters.', 422);
        }
        if (!in_array($scope, [self::SCOPE_PAGE, self::SCOPE_SITE], true)) {
            throw new EditorAiException('invalid_input', 'Unknown scope.', 422);
        }
        $turns = self::validHistory($history);
        if (!in_array($context->type, [ToolbarAssistantContext::TYPE_PAGE, ToolbarAssistantContext::TYPE_RECORD, ToolbarAssistantContext::TYPE_FILE], true)) {
            throw new EditorAiException('no_context', 'Open a page, a content element or a file first.', 422);
        }

        $pageIssues = $scope === self::SCOPE_PAGE ? $this->assistantService->openIssues($context) : [];
        $siteIdentifier = self::siteOf($context, $pageIssues);
        if ($siteIdentifier === '') {
            throw new EditorAiException('no_context', 'No site is known for this context.', 422);
        }
        // A file can be used on several sites: only the findings of the site whose AI settings, privacy switches and
        // budget decide this request may go to its provider.
        $pageIssues = self::issuesOfSite($pageIssues, $siteIdentifier);
        // The user-aware gate first (licence, ai.editor, module access, site readiness); the runner checks the site again.
        $state = $this->editorAiAccessService->state($siteIdentifier);
        if ($state !== EditorAiAccessService::STATE_READY) {
            throw new EditorAiException($state, 'AI is not available here.', in_array($state, [EditorAiAccessService::STATE_NO_LICENCE, EditorAiAccessService::STATE_NO_PERMISSION], true) ? 403 : 422);
        }
        if ($scope === self::SCOPE_SITE && !$this->canAskSite($context)) {
            throw new EditorAiException('scope_restricted', 'A website-wide answer needs access to the site root.', 403);
        }

        $beUser = $this->backendUserService->getBackendUserUid();
        $bound = $this->questionBound($siteIdentifier);
        // One AI question per user at a time: parallel requests cannot pass the conversation caps together.
        $lock = $this->acquireUserLock($beUser);
        try {
            // The hard per-user limit: every AI question of the last 24 hours, whatever conversation it belonged to.
            if ($this->usageRepository->countUserFeatureRequests($siteIdentifier, $beUser, AiTask::ASSISTANT, time() - 86400) >= AskAqgLimits::MAX_AI_QUESTIONS_PER_USER_PER_DAY) {
                throw new EditorAiException('daily_limit', 'The daily limit of AI questions is reached.', 429);
            }
            $conversation = AskAqgConversation::resume($conversationId, $beUser, $siteIdentifier, null, $this->conversationBinding($context));
            // Only turns this server answered in this very conversation (same user, login, site and context) are used.
            $turns = self::verifiedTurns($conversation, $turns);
            $since = AskAqgConversation::issuedAt($conversation->id);
            $spent = $this->usageRepository->summarizeConversation($siteIdentifier, $beUser, $conversation->ref, $since);
            if ($spent['requests'] >= AskAqgLimits::MAX_AI_QUESTIONS_PER_CONVERSATION) {
                throw new EditorAiException('conversation_limit', 'This conversation reached its question limit.', 429);
            }
            if ($bound !== null && $spent['cost_micro_usd'] + $bound > AskAqgLimits::MAX_CONVERSATION_COST_MICRO_USD) {
                throw new EditorAiException('conversation_budget', 'This conversation reached its cost limit.', 429);
            }

            $language = $this->language();
            $prepared = $scope === self::SCOPE_SITE
                ? $this->siteFindings($siteIdentifier, max(0, $context->languageUid), $language)
                : $this->pageFindings($pageIssues, $context, $language);
            $notes = $scope === self::SCOPE_SITE ? $this->siteNotes($siteIdentifier, max(0, $context->languageUid)) : $this->pageNotes($context);
            $allowPageTitle = $this->siteSettingsService->get($siteIdentifier)->allowPageTitle;

            $untrusted = [
                'question' => $question,
                'scope' => $scope,
                'target_locale' => $language,
                'subject' => ['kind' => $this->subjectKind($context), 'language_uid' => max(0, $context->languageUid)],
                'data_notes' => array_keys($notes),
                'totals' => $prepared['totals'],
                'findings' => $prepared['findings'],
                'previous_turns' => $turns,
            ];
            $sent = ['question', 'rules', 'guidance', 'notes'];
            if ($scope === self::SCOPE_PAGE && $allowPageTitle && $context->pageTitle !== '') {
                // Top level, so AiStructuredRequest marks the request and the runner re-checks the site switch.
                $untrusted['page_title'] = mb_substr($context->pageTitle, 0, 200);
                $sent[] = 'pageTitle';
            }
            if ($prepared['fields']) {
                $sent[] = 'fields';
            }
            $untrusted = self::withinContextBudget($untrusted);
            if ($untrusted['previous_turns'] !== []) {
                $sent[] = 'turns';
            }

            try {
                $request = AiStructuredRequest::withUntrustedContext(
                    AiTask::ASSISTANT,
                    AskAqgPrompts::PROMPT_VERSION,
                    AskAqgPrompts::instructions(),
                    $untrusted,
                    AskAqgPrompts::outputFormat(),
                    AskAqgLimits::MAX_OUTPUT_TOKENS,
                );
            } catch (\InvalidArgumentException) {
                throw new EditorAiException('invalid_input', 'The question could not be prepared.', 422);
            }
            // Exactly one provider request; its usage row carries the conversation ref (a random id, no content). The row is
            // admitted (written as "pending") before the provider is contacted; the check below then counts it together
            // with every other admitted or unsettled attempt, so a lost settlement write can never free a question (PI-03).
            $response = $this->taskRunner->run($siteIdentifier, $request, $conversation->ref, function () use ($siteIdentifier, $beUser, $conversation, $since): void {
                $this->assertAdmittedWithinLimits($siteIdentifier, $beUser, $conversation, $since);
            });
            $after = $this->usageRepository->summarizeConversation($siteIdentifier, $beUser, $conversation->ref, $since);
        } finally {
            $lock?->release();
        }

        $status = (string)($response->data['status'] ?? '');
        $status = in_array($status, [AskAqgPrompts::STATUS_ANSWER, AskAqgPrompts::STATUS_NEEDS_MORE_DATA, AskAqgPrompts::STATUS_OUT_OF_SCOPE], true) ? $status : 'invalid_response';
        $answer = self::plain((string)($response->data['answer'] ?? ''), AskAqgLimits::MAX_ANSWER_CHARS);
        $nextStep = self::plain((string)($response->data['next_step'] ?? ''), 300);
        if ($answer === '') {
            $status = 'invalid_response';
        }
        $citations = [];
        if ($status !== AskAqgPrompts::STATUS_OUT_OF_SCOPE && $status !== 'invalid_response') {
            foreach (self::citedRefs((string)($response->data['cited_refs'] ?? ''), array_column($untrusted['findings'], 'ref')) as $ref) {
                $citations[] = ['ref' => $ref] + $prepared['links'][$ref];
            }
        }

        return [
            'status' => $status,
            'source' => 'ai',
            'scope' => $scope,
            'question' => $question,
            'answer' => $status === 'invalid_response' ? '' : $answer,
            'nextStep' => $status === 'invalid_response' ? '' : $nextStep,
            'generalAdvice' => ($response->data['general_advice'] ?? false) === true,
            'basedOnFindings' => $citations !== [],
            'citations' => $citations,
            'notes' => array_values($notes),
            'totals' => $prepared['totals'],
            'sent' => array_map(fn (string $key): string => $this->t('toolbar.ask.sent.' . $key, $key), $sent),
            'conversation' => self::conversationState($conversation, $after, $bound),
            'history' => $status === 'invalid_response' ? null : self::historyTurn($conversation, $question, $answer),
            'promptVersion' => AskAqgPrompts::PROMPT_VERSION,
        ];
    }

    /**
     * After this attempt's usage row was admitted (before the provider request): the hard per-user daily cap and the
     * conversation question cap, counted again with the admitted row and every pending or unsettled one. Concurrent
     * admissions that together pass a cap are all refused (conservative); a refused attempt was never sent.
     */
    private function assertAdmittedWithinLimits(string $siteIdentifier, int $beUser, AskAqgConversation $conversation, int $since): void
    {
        if ($this->usageRepository->countUserFeatureRequests($siteIdentifier, $beUser, AiTask::ASSISTANT, time() - 86400) > AskAqgLimits::MAX_AI_QUESTIONS_PER_USER_PER_DAY) {
            throw new EditorAiException('daily_limit', 'The daily limit of AI questions is reached.', 429);
        }
        if ($this->usageRepository->summarizeConversation($siteIdentifier, $beUser, $conversation->ref, $since)['requests'] > AskAqgLimits::MAX_AI_QUESTIONS_PER_CONVERSATION) {
            throw new EditorAiException('conversation_limit', 'This conversation reached its question limit.', 429);
        }
    }

    /**
     * What the browser may send back with a later question of this conversation: the question and the answer as they
     * fit the history limits, and the token that proves they are this server's answer in this conversation.
     *
     * @return array{question:string,answer:string,token:string}
     */
    public static function historyTurn(AskAqgConversation $conversation, string $question, string $answer): array
    {
        $answer = self::plain($answer, AskAqgLimits::MAX_HISTORY_ANSWER_CHARS);

        return ['question' => $question, 'answer' => $answer, 'token' => AskAqgConversation::turnToken($conversation->id, $question, $answer)];
    }

    /**
     * The earlier turns whose token this conversation issued; every other turn (another context, user, login or site,
     * an expired or forged conversation, edited text) is dropped and never reaches the provider.
     *
     * @param list<array{question:string,answer:string,token:string}> $turns
     * @return list<array{question:string,answer:string}>
     */
    public static function verifiedTurns(AskAqgConversation $conversation, array $turns): array
    {
        $verified = [];
        foreach ($turns as $turn) {
            if ($conversation->verifiesTurn($turn['question'], $turn['answer'], $turn['token'])) {
                $verified[] = ['question' => $turn['question'], 'answer' => $turn['answer']];
            }
        }

        return $verified;
    }

    /**
     * What a conversation id is bound to besides user and site: the login session (hashed, never sent anywhere) and the
     * server-resolved context. A new login, page, language, record, file, workspace or site starts a new conversation.
     */
    public function conversationBinding(ToolbarAssistantContext $context): string
    {
        return implode('|', [$this->sessionHash(), self::contextKey($context)]);
    }

    /**
     * Opaque keys for the browser's conversation storage: `principal` changes with the user and the login session (the
     * toolbar then drops every stored conversation), `scope` additionally with the resolved context. Neither reveals the
     * session or the identifiers; the server checks every request on its own (conversationBinding(), turn tokens).
     *
     * @return array{principal:string,scope:string}
     */
    public function browserScope(ToolbarAssistantContext $context): array
    {
        $principal = AskAqgConversation::scopeKey('principal', (string)$this->backendUserService->getBackendUserUid(), $this->sessionHash());

        return ['principal' => $principal, 'scope' => AskAqgConversation::scopeKey('scope', $principal, self::contextKey($context))];
    }

    public static function contextKey(ToolbarAssistantContext $context): string
    {
        return implode(':', [$context->type, $context->workspace, $context->siteIdentifier, $context->pageUid, $context->languageUid, $context->table, $context->recordUid, $context->fileUid]);
    }

    /** SHA-256 of the backend session id ('' without a session); only ever an HMAC input. */
    private function sessionHash(): string
    {
        try {
            $identifier = (string)$this->backendUserService->getBackendUser()?->getSession()->getIdentifier();
        } catch (\Throwable) {
            $identifier = '';
        }

        return $identifier === '' ? '' : hash('sha256', $identifier);
    }

    /**
     * What the toolbar shows about the conversation, from the usage rows (never from the browser).
     *
     * @param array{requests:int,cost_micro_usd:int,cost_unknown:int} $spent
     * @return array<string,mixed>
     */
    public static function conversationState(AskAqgConversation $conversation, array $spent, ?int $bound): array
    {
        return [
            'id' => $conversation->id,
            'aiQuestions' => $spent['requests'],
            'maxAiQuestions' => AskAqgLimits::MAX_AI_QUESTIONS_PER_CONVERSATION,
            'spentMicroUsd' => $spent['cost_micro_usd'],
            'costUnknown' => $spent['cost_unknown'] > 0,
            'maxCostMicroUsd' => AskAqgLimits::MAX_CONVERSATION_COST_MICRO_USD,
            'limitReached' => $spent['requests'] >= AskAqgLimits::MAX_AI_QUESTIONS_PER_CONVERSATION
                || ($bound !== null && $spent['cost_micro_usd'] + $bound > AskAqgLimits::MAX_CONVERSATION_COST_MICRO_USD),
        ];
    }

    /** The upper bound of one question with the site's price (the cost hint's value), null without a price. */
    private function questionBound(string $siteIdentifier): ?int
    {
        try {
            return $this->costEstimator?->maxCostMicro($siteIdentifier, AiActionCostEstimator::ACTION_ASSISTANT);
        } catch (\Throwable) {
            return null;
        }
    }

    private function acquireUserLock(int $beUser): ?LockingStrategyInterface
    {
        if ($this->lockFactory === null) {
            return null;
        }
        $mode = LockingStrategyInterface::LOCK_CAPABILITY_EXCLUSIVE | LockingStrategyInterface::LOCK_CAPABILITY_NOBLOCK;
        try {
            $lock = $this->lockFactory->createLocker('aqg_toolbar_ask_' . $beUser, $mode);
            if (!$lock->acquire($mode)) {
                throw new EditorAiException('busy', 'Another question is still being answered.', 429);
            }

            return $lock;
        } catch (LockAcquireWouldBlockException) {
            throw new EditorAiException('busy', 'Another question is still being answered.', 429);
        }
    }

    /**
     * The earlier turns the browser sent, validated against AskAqgLimits. Over-long or malformed history is refused,
     * not silently cut, so the browser and the server never disagree about what the provider saw.
     *
     * @param list<mixed> $history
     * @return list<array{question:string,answer:string,token:string}>
     */
    public static function validHistory(array $history): array
    {
        if (!array_is_list($history) || count($history) > AskAqgLimits::MAX_HISTORY_TURNS) {
            throw new EditorAiException('history_too_long', 'Too many earlier turns.', 422);
        }
        $turns = [];
        $total = 0;
        foreach ($history as $turn) {
            if (!is_array($turn) || !is_string($turn['question'] ?? null) || !is_string($turn['answer'] ?? null)) {
                throw new EditorAiException('invalid_input', 'Malformed history.', 422);
            }
            $question = self::normaliseQuestion($turn['question']);
            $answer = self::plain($turn['answer'], PHP_INT_MAX);
            if ($question === '' || mb_strlen($answer) > AskAqgLimits::MAX_HISTORY_ANSWER_CHARS) {
                throw new EditorAiException('history_too_long', 'An earlier turn is too long.', 422);
            }
            $total += mb_strlen($question) + mb_strlen($answer);
            $token = $turn['token'] ?? '';
            $turns[] = ['question' => $question, 'answer' => $answer, 'token' => is_string($token) && preg_match('/^[a-f0-9]{40,128}$/', $token) === 1 ? $token : ''];
        }
        if ($total > AskAqgLimits::MAX_HISTORY_CHARS) {
            throw new EditorAiException('history_too_long', 'The history is too long.', 422);
        }

        return $turns;
    }

    /**
     * Keeps the context within the size the cost hint promises (AiActionCostEstimator::MAX_ASSISTANT_CONTEXT_BYTES):
     * first "why it matters", then the oldest earlier turns, then the least important finding groups are dropped, so
     * browser-supplied history never pushes AQG's findings out. Budgets use the real request either way.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public static function withinContextBudget(array $context): array
    {
        $size = static fn (array $value): int => strlen((string)json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        if ($size($context) <= AiActionCostEstimator::MAX_ASSISTANT_CONTEXT_BYTES) {
            return $context;
        }
        foreach ($context['findings'] as $index => $finding) {
            $context['findings'][$index]['why_it_matters'] = '';
        }
        while ($size($context) > AiActionCostEstimator::MAX_ASSISTANT_CONTEXT_BYTES && $context['previous_turns'] !== []) {
            array_shift($context['previous_turns']);
        }
        while ($size($context) > AiActionCostEstimator::MAX_ASSISTANT_CONTEXT_BYTES && $context['findings'] !== []) {
            array_pop($context['findings']);
        }

        return $context;
    }

    public static function normaliseQuestion(string $question): string
    {
        $question = trim((string)preg_replace('/\s+/u', ' ', (string)preg_replace('/[\x00-\x1F\x7F]/u', ' ', $question)));
        $length = mb_strlen($question);

        return $length >= AskAqgLimits::MIN_QUESTION_CHARS && $length <= AskAqgLimits::MAX_QUESTION_CHARS ? $question : '';
    }

    /** Model text as plain text: no markup, no control characters except line breaks, bounded. */
    public static function plain(string $text, int $limit): string
    {
        $text = strip_tags(str_replace(["\r\n", "\r"], "\n", $text));
        $text = (string)preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', '', $text);
        $text = trim((string)preg_replace("/\n{3,}/", "\n\n", $text));

        return mb_strlen($text) > $limit ? rtrim(mb_substr($text, 0, $limit - 1)) . '…' : $text;
    }

    /**
     * The refs the model cited that were actually sent, in order, without duplicates.
     *
     * @param list<string> $sentRefs
     * @return list<string>
     */
    public static function citedRefs(string $cited, array $sentRefs): array
    {
        preg_match_all('/\b([FS][1-9][0-9]?)\b/', $cited, $matches);

        return array_values(array_unique(array_intersect($matches[1] ?? [], $sentRefs)));
    }

    /**
     * @param list<string> $refs
     * @param array<string,mixed> $prepared
     * @param array<string,string> $notes
     * @return array<string,mixed>
     */
    private function deterministicPayload(string $intent, string $scope, string $question, string $answer, string $nextStep, array $refs, array $prepared, array $notes): array
    {
        $shown = self::normaliseQuestion($question);

        return [
            'status' => AskAqgPrompts::STATUS_ANSWER,
            'source' => 'aqg',
            'intent' => $intent,
            'scope' => $scope,
            'question' => $shown !== '' ? $shown : $this->t('toolbar.ask.example.' . $intent, $intent),
            'answer' => self::plain($answer, AskAqgLimits::MAX_ANSWER_CHARS),
            'nextStep' => $nextStep,
            'generalAdvice' => false,
            'basedOnFindings' => $refs !== [],
            'citations' => array_map(static fn (string $ref): array => ['ref' => $ref] + $prepared['links'][$ref], $refs),
            'notes' => array_values($notes),
            'totals' => $prepared['totals'],
            'sent' => [],
        ];
    }

    /**
     * @param list<array<string,mixed>> $issues
     * @return array{findings: list<array<string,mixed>>, links: array<string,array<string,mixed>>, totals: array{issue_types:int,occurrences:int}, fields: bool}
     */
    private function pageFindings(array $issues, ToolbarAssistantContext $context, string $language): array
    {
        $findings = [];
        $links = [];
        $fields = false;
        foreach (array_slice(ToolbarFindingGrouper::group($issues), 0, self::MAX_GROUPS) as $index => $group) {
            $ref = 'F' . ($index + 1);
            $lead = $group['issues'][0];
            $guidance = $this->localIssueGuidanceService->present($lead, $language);
            $title = $this->title($guidance, $lead);
            $where = [];
            $places = [];
            foreach ($group['issues'] as $issue) {
                $location = $this->issueLocationService->describe((string)$issue['source_table'], (int)$issue['source_uid'], (string)$issue['source_field']);
                $label = (string)($location['fieldLabel'] ?? '');
                if ($label !== '') {
                    $where[$label] = true;
                }
                $place = trim(implode(' · ', array_filter([(string)($location['title'] ?? ''), $label])));
                if ($place !== '') {
                    $places[$place] = true;
                }
            }
            $fields = $fields || $where !== [];
            $severity = Severity::fromInt($group['severity']);
            // Sent to the provider for AI questions: rule data and field labels only, no element titles or content.
            $findings[] = [
                'ref' => $ref,
                'rule_id' => $group['ruleId'],
                'title' => $title,
                'severity' => $severity->key(),
                'occurrences' => $group['count'],
                'elements' => $group['records'],
                'source' => $group['source'],
                'where' => implode(', ', array_slice(array_keys($where), 0, 4)),
                'how_to_fix' => self::guidanceText($guidance),
                'why_it_matters' => mb_substr((string)($guidance['whyItMatters'] ?? ''), 0, self::MAX_GUIDANCE_LENGTH),
            ];
            $table = (string)($lead['source_table'] ?? '');
            $recordUid = (int)($lead['source_uid'] ?? 0);
            $editingThis = $context->isEditingRecord() && $context->table === $table && $context->recordUid === $recordUid;
            $links[$ref] = [
                'title' => $title,
                'tone' => ToolbarAssistantService::tone($severity),
                'severityLabel' => $this->t('severity.' . $severity->key(), $severity->label()),
                'count' => $group['count'],
                'sourceLabel' => $this->assistantService->sourceLabel($group['source']),
                // Shown to the editor (deterministic "Where" answers), never sent to the provider.
                'location' => implode('; ', array_slice(array_keys($places), 0, 3)),
                'detailUrl' => $this->assistantService->pageDetailUrl($lead),
                'editUrl' => !$editingThis && $table !== '' && $recordUid > 0 && $this->backendRecordAccessService->canEditRecord($table, $recordUid)
                    ? $this->assistantService->editUrl($table, $recordUid)
                    : '',
                'pageTitle' => '',
            ];
        }

        return [
            'findings' => $findings,
            'links' => $links,
            'totals' => ['issue_types' => ToolbarFindingGrouper::issueTypes($issues), 'occurrences' => count($issues)],
            'fields' => $fields,
        ];
    }

    /**
     * @return array{findings: list<array<string,mixed>>, links: array<string,array<string,mixed>>, totals: array{issue_types:int,occurrences:int}, fields: bool}
     */
    private function siteFindings(string $siteIdentifier, int $languageUid, string $language): array
    {
        $rules = $this->issueRepository->summarizeOpenByRuleForSite($siteIdentifier, $languageUid, $this->scopeAccessService->readablePagesClause(), 500);
        $findings = [];
        $links = [];
        foreach (array_slice($rules, 0, self::MAX_GROUPS + 3) as $index => $rule) {
            $ref = 'S' . ($index + 1);
            $lead = ['rule_id' => $rule['rule_id'], 'severity' => $rule['severity'], 'message' => '', 'site_identifier' => $siteIdentifier, 'page_uid' => $rule['top_page_uid'], 'source_lang_uid' => $languageUid];
            $guidance = $this->localIssueGuidanceService->present($lead, $language);
            $severity = Severity::fromInt($rule['severity']);
            $title = $this->title($guidance, $lead);
            $findings[] = [
                'ref' => $ref,
                'rule_id' => $rule['rule_id'],
                'title' => $title,
                'severity' => $severity->key(),
                'occurrences' => $rule['occurrences'],
                'pages' => $rule['pages'],
                'how_to_fix' => self::guidanceText($guidance),
                'why_it_matters' => mb_substr((string)($guidance['whyItMatters'] ?? ''), 0, self::MAX_GUIDANCE_LENGTH),
            ];
            $links[$ref] = [
                'title' => $title,
                'tone' => ToolbarAssistantService::tone($severity),
                'severityLabel' => $this->t('severity.' . $severity->key(), $severity->label()),
                'count' => $rule['occurrences'],
                'pages' => $rule['pages'],
                'sourceLabel' => '',
                'location' => '',
                'detailUrl' => $rule['top_page_uid'] > 0 ? $this->assistantService->pageDetailUrl($lead) : '',
                'editUrl' => '',
                // The page with the most occurrences is a readable page: the summary is restricted to those.
                'pageTitle' => (string)$rule['top_page_title'],
            ];
        }

        return [
            'findings' => $findings,
            'links' => $links,
            'totals' => ['issue_types' => count($rules), 'occurrences' => array_sum(array_column($rules, 'occurrences'))],
            'fields' => false,
        ];
    }

    /**
     * Data notes: the key goes to the provider, the translated text to the editor.
     *
     * @return array<string,string>
     */
    private function pageNotes(ToolbarAssistantContext $context): array
    {
        $notes = [];
        $freshness = $this->assistantService->freshness($context);
        if ($freshness['known'] && $freshness['checkedAt'] === 0) {
            $notes['page_never_checked'] = $this->t('toolbar.ask.note.neverChecked', 'AQG has no completed content check of this page yet.');
        } elseif ($freshness['checkedAt'] > 0) {
            $notes['last_checked_' . date('Y-m-d', $freshness['checkedAt'])] = sprintf($this->t('toolbar.ask.note.checked', 'Based on the content check of %s.'), $freshness['label']);
            if ($freshness['stale']) {
                $notes['check_older_than_14_days'] = $this->t('toolbar.ask.note.stale', 'That check is older than 14 days; check the saved page again for current results.');
            }
        }
        if ($context->unsaved) {
            $notes['unsaved_edits_not_checked'] = $this->t('toolbar.ask.note.unsaved', 'Your unsaved changes are not part of the findings.');
        }
        if ($context->workspace > 0) {
            $notes['workspace_draft_not_checked'] = $this->t('toolbar.ask.note.workspace', 'Findings describe the live website, not your workspace draft.');
        }
        $notes['content_findings_only'] = $this->t('toolbar.ask.note.contentOnly', 'Only content scan findings are used; frontend scan results are in the Accessibility module.');

        return $notes;
    }

    /** @return array<string,string> */
    private function siteNotes(string $siteIdentifier, int $languageUid): array
    {
        $notes = [];
        try {
            $finished = (int)($this->scanRepository->findLastCompletedLocalScan($siteIdentifier, $languageUid)['finished_at'] ?? 0);
        } catch (\Throwable) {
            $finished = 0;
        }
        if ($finished === 0) {
            $notes['site_never_checked'] = $this->t('toolbar.ask.note.siteNeverChecked', 'AQG has no completed content scan of this website yet.');
        } else {
            $notes['last_site_check_' . date('Y-m-d', $finished)] = sprintf($this->t('toolbar.ask.note.siteChecked', 'Based on stored findings; the last content scan finished %s.'), BackendTimeUtility::formatDateTime($finished, 'd.m.Y H:i'));
            if ($finished < time() - ToolbarAssistantService::STALE_AFTER_SECONDS) {
                $notes['check_older_than_14_days'] = $this->t('toolbar.ask.note.siteStale', 'The last content scan is older than 14 days.');
            }
        }
        if ($this->scopeAccessService->readablePagesClause() !== '') {
            $notes['restricted_to_readable_pages'] = $this->t('toolbar.ask.note.restricted', 'Only pages you may access are included.');
        }
        $notes['content_findings_only'] = $this->t('toolbar.ask.note.contentOnly', 'Only content scan findings are used; frontend scan results are in the Accessibility module.');

        return $notes;
    }

    /**
     * AQG's rule guidance only: a finding's stored hint (the fallback) can quote site content and is not used.
     *
     * @param array<string,mixed> $guidance
     */
    private static function guidanceText(array $guidance): string
    {
        return ($guidance['howToFixIsFallback'] ?? false) === true ? '' : mb_substr((string)($guidance['howToFix'] ?? ''), 0, self::MAX_GUIDANCE_LENGTH);
    }

    private function subjectKind(ToolbarAssistantContext $context): string
    {
        return match ($context->type) {
            ToolbarAssistantContext::TYPE_RECORD => 'content_element',
            ToolbarAssistantContext::TYPE_FILE => 'file',
            default => 'page',
        };
    }

    /**
     * @param array<string,mixed> $guidance
     * @param array<string,mixed> $issue
     */
    private function title(array $guidance, array $issue): string
    {
        // Rule titles only: a stored message can quote site content and is not used.
        return (string)(($guidance['localizedTitle'] ?? '') !== '' ? $guidance['localizedTitle'] : (($guidance['title'] ?? '') !== '' ? $guidance['title'] : ($issue['rule_id'] ?? '')));
    }

    private function language(): string
    {
        return $this->backendContextService->getCurrentLanguageCode() === 'de' ? 'de' : 'en';
    }

    private function t(string $key, string $fallback): string
    {
        $translated = $this->backendContextService->translate($key);

        return $translated !== '' && $translated !== $key ? $translated : $fallback;
    }
}
