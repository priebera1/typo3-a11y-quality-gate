<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Usage;

use Priebera\A11yQualityGate\Utility\BackendLabelUtility;
use Priebera\A11yQualityGate\Utility\BackendLocaleFormatter;
use Symfony\Component\Mime\Address;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Mail\MailerInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Mail\MailMessage;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\MailUtility;

/**
 * The budget warning e-mail (F-39): plain text, like the monitoring e-mail. It names the site, the threshold, the
 * monthly budget, the recorded estimated usage, how many requests of the month have an unknown cost, and links to
 * Accessibility › Settings › AI suggestions when an absolute backend URL can be built (otherwise the link is left out; AQG never guesses a host).
 * It never contains an API key, prompt, editor content, request payload or the name of an editor.
 *
 * Language: the backend language of the user whose request crossed the threshold ($GLOBALS['LANG'], the same source
 * every AQG label uses); English without one (CLI, scheduler). Amounts follow the same language
 * (BackendLocaleFormatter).
 */
class AiBudgetAlertNotifier
{
    /**
     * The mailer is resolved only when an alert is sent: TYPO3's Mailer builds its transport in the constructor and throws on
     * an invalid mail configuration, which must fail the alert (caught by the caller), never the AI request whose
     * dependencies include this notifier. $mailerFactory exists for tests.
     *
     * @param (\Closure(): MailerInterface)|null $mailerFactory
     */
    public function __construct(
        private readonly UriBuilder $uriBuilder,
        private readonly SiteFinder $siteFinder,
        private readonly ?\Closure $mailerFactory = null,
    ) {}

    /**
     * @param array{siteIdentifier:string,month:string,thresholdPercent:int,budgetMicroUsd:int,spentMicroUsd:int,unknownCostRequests:int,hardStop:bool} $alert
     * @throws \Throwable when the message cannot be handed to the mailer (the caller records the failed attempt)
     */
    public function send(AiBudgetAlertRecipient $recipient, array $alert): void
    {
        if (!$recipient->exists()) {
            throw new \InvalidArgumentException('No budget alert recipient.');
        }
        [$subject, $text] = $this->compose($alert, $recipient->source);

        $message = new MailMessage();
        $from = MailUtility::getSystemFrom();
        if (is_array($from) && $from !== []) {
            $address = (string)array_key_first($from);
            $message->from(new Address(is_numeric($address) ? (string)reset($from) : $address, is_numeric($address) ? '' : (string)reset($from)));
        }
        $message->to($recipient->address)->subject($subject)->text($text);
        $mailer = $this->mailerFactory !== null ? ($this->mailerFactory)() : GeneralUtility::makeInstance(MailerInterface::class);
        $mailer->send($message);
    }

    /**
     * @param array{siteIdentifier:string,month:string,thresholdPercent:int,budgetMicroUsd:int,spentMicroUsd:int,unknownCostRequests:int,hardStop:bool} $alert
     * @return array{0:string,1:string} subject, plain-text body
     */
    public function compose(array $alert, string $recipientSource, ?BackendLocaleFormatter $formatter = null): array
    {
        $formatter ??= BackendLocaleFormatter::forBackendUser();
        $siteIdentifier = (string)$alert['siteIdentifier'];
        $siteLabel = $this->siteLabel($siteIdentifier);
        $threshold = (string)(int)$alert['thresholdPercent'];
        $budget = $formatter->usd((int)$alert['budgetMicroUsd']);
        $spent = $formatter->usd((int)$alert['spentMicroUsd']);
        $percent = (string)AiMoney::percent((int)$alert['spentMicroUsd'], (int)$alert['budgetMicroUsd']);

        // U-05: the subject and intro name the actual share; a site at 112 % is not "at 80 %".
        $subject = self::label('subject', '[AQG] AI budget warning: %1$s is at %2$s%% of its monthly budget', $siteIdentifier, $percent);
        $lines = [
            self::label('intro', 'The recorded AI usage of the site %1$s has reached the warning threshold of %2$s%% of its monthly budget: it is at %3$s%%.', $siteLabel, $threshold, $percent),
            '',
            self::label('site', 'Site: %s', $siteLabel),
            self::label('month', 'Month: %s (server time)', (string)$alert['month']),
            self::label('threshold', 'Warning threshold: %s%%', $threshold),
            self::label('budget', 'Monthly budget: %s', $budget),
            self::label('usage', 'Recorded usage this month (estimated): %1$s (%2$s%% of the budget)', $spent, $percent),
            '',
            self::label('estimateNote', 'Amounts are estimates in USD, calculated from the model prices AQG knows. Provider invoices can differ.'),
        ];
        $unknown = max(0, (int)$alert['unknownCostRequests']);
        if ($unknown > 0) {
            $lines[] = self::label('unknownCost', '%s requests this month have an unknown cost (no price for their model). They are not counted as $0: the real spending may be higher.', (string)$unknown);
        }
        $lines[] = (bool)$alert['hardStop']
            ? self::label('hardStop', 'When the budget is reached, AI pauses for editors of this site until the next month.')
            : self::label('warnOnly', 'This budget only warns: AI stays available for editors when it is reached.');

        $link = $this->settingsLink($siteIdentifier);
        if ($link !== '') {
            $lines[] = '';
            $lines[] = self::label('link', 'AI settings of this site: %s', $link);
        }
        $lines[] = '';
        $lines[] = $recipientSource === AiBudgetAlertRecipient::SOURCE_TYPO3
            ? self::label('reasonTypo3', 'You receive this e-mail because no alert address is set for this site and this is the TYPO3 warning e-mail address ([BE][warning_email_addr]).')
            : self::label('reasonSite', 'You receive this e-mail because this address is the budget alert recipient of this site in the TYPO3 backend under Accessibility › Settings › AI suggestions.');
        $lines[] = self::label('once', 'AQG sends this alert once per site, month, warning threshold and budget.');

        return [$subject, implode("\n", $lines) . "\n"];
    }

    private static function label(string $key, string $fallback, string ...$arguments): string
    {
        $template = BackendLabelUtility::translate('mail.budgetAlert.' . $key, $fallback);
        if ($arguments === []) {
            return str_replace('%%', '%', $template);
        }
        try {
            return sprintf($template, ...$arguments);
        } catch (\Throwable) {
            return sprintf($fallback, ...$arguments);
        }
    }

    private function siteLabel(string $siteIdentifier): string
    {
        try {
            $site = $this->siteFinder->getSiteByIdentifier($siteIdentifier);
            $title = trim((string)($site->getConfiguration()['websiteTitle'] ?? ''));
            $host = (string)$site->getBase()->getHost();
            $details = implode(', ', array_filter([$title !== '' ? $siteIdentifier : '', $host]));
            $name = $title !== '' ? $title : $siteIdentifier;

            return $details !== '' ? $name . ' (' . $details . ')' : $name;
        } catch (\Throwable) {
            return $siteIdentifier;
        }
    }

    /** An absolute link to Accessibility › Settings › AI suggestions of the site, or '' when no absolute backend URL can be built. */
    private function settingsLink(string $siteIdentifier): string
    {
        try {
            $uri = (string)$this->uriBuilder->buildUriFromRoute('web_a11y.settings', ['tab' => 'ai', 'rulesetSite' => $siteIdentifier], UriBuilder::ABSOLUTE_URL);
        } catch (\Throwable) {
            return '';
        }

        if (preg_match('#^https?://[^/\s]+#i', $uri) !== 1) {
            return '';
        }

        // The backend route token belongs to the session of the user whose request crossed the threshold; it must not
        // leave the installation by e-mail. TYPO3 opens the module without it (and asks to log in first when needed).
        $parts = parse_url($uri);
        parse_str($parts['query'] ?? '', $query);
        unset($query['token']);
        $base = substr($uri, 0, strcspn($uri, '?#'));

        return $query === [] ? $base : $base . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
