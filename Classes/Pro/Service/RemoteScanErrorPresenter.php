<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Service;

use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;
use Priebera\A11yQualityGate\Pro\Exception\ProNotConfiguredException;
use Priebera\A11yQualityGate\Pro\Exception\TokenRefreshException;
use Priebera\A11yQualityGate\Utility\BackendLabelUtility;

/**
 * The public side of a failed remote scan request.
 *
 * Exception messages carry request internals — crawler URLs, payload keys, token lengths, transport and
 * database errors — so they only go to the log. The browser, the shared scan status and stored scans get a
 * bounded code and a translated message, chosen from the HTTP status and the API error code of the failed
 * request, never from the message text.
 */
final class RemoteScanErrorPresenter
{
    public const TARGET_NOT_PUBLIC_MESSAGE = 'The AQG scanner runs outside your TYPO3 installation and only reaches sites on the public internet. This site\'s address points to a local or private network (for example DDEV, localhost, a VPN or internal DNS), so the scan was refused as a security safeguard, not because of the licence. Content scans keep working. Give the site a publicly reachable base URL in its Site Configuration (a base variant for this application context works too), or scan the site\'s public installation.';
    public const TARGET_NOT_FOUND_MESSAGE = 'The AQG scanner could not find this site\'s host name on the internet. A host name that only resolves inside a VPN or internal DNS cannot be scanned from outside. Check that the base URL in the site\'s Site Configuration is its public address.';

    /**
     * @return array{status:int,code:string,title:string,message:string,retryAfter:int|null}
     */
    public function present(\Throwable $exception, string $fallbackCode = 'remote_crawler_request_failed'): array
    {
        $apiException = $this->findApiException($exception);
        $httpStatus = $apiException?->httpStatus ?? 0;
        $apiCode = trim((string)($apiException?->apiErrorCode ?? ''));

        if ($exception instanceof ProNotConfiguredException) {
            return $this->result(403, 'pro_crawler_required', 'notAllowed', null);
        }

        if (!$apiException instanceof ApiRequestFailedException) {
            // A token refresh the licence API refused without an HTTP failure is a licence problem.
            return $exception instanceof TokenRefreshException
                ? $this->result(403, 'token_refresh_failed', 'auth', null)
                : $this->result(500, $fallbackCode, 'failed', null);
        }

        $kind = match (true) {
            $apiCode === 'trial_crawl_limit' => 'trialLimit',
            $httpStatus === 429 || in_array($apiCode, ['too_many_active_jobs', 'rate_limit_exceeded', 'crawler_rate_limited'], true) => 'limit',
            // A revoked token or a licence refusal on token refresh (removed project, expired licence) is a
            // licence state to fix in Settings, not a missing permission.
            in_array($apiCode, ['invalid_token', 'token_expired', 'token_revoked', 'token_rejected', 'unauthorized', 'missing_license_subject', 'installation_identity_mismatch', 'site_identity_mismatch'], true)
                || str_starts_with($apiCode, 'licence_')
                || $httpStatus === 401 => 'auth',
            // The licence is fine but this site's domain is not covered: activate it, free a slot, or fix the site's
            // base. Each names its own next step instead of a generic refusal.
            // The crawler compares the scanned host with the token's licence domain.
            $apiCode === 'domain_not_activated', $apiCode === 'domain_mismatch' => 'domainNotActivated',
            $apiCode === 'domain_not_detected' => 'domainNotDetected',
            $apiCode === 'domain_limit_reached' => 'domainLimit',
            // A job submitted under another licence key or TYPO3 project: the feature is fine, the scan is not theirs.
            in_array($apiCode, ['forbidden_resource', 'job_site_mismatch'], true) => 'scanNotAccessible',
            in_array($apiCode, ['feature_not_available', 'feature_not_enabled'], true) || $httpStatus === 403 => 'notAllowed',
            // The scanner works from the public internet and refuses local and private network targets (its SSRF
            // boundary). That is the site's configuration to fix, not a licence or scanner fault; the resolved
            // address stays in the log.
            $apiCode === 'private_network_blocked' => 'targetNotPublic',
            $apiCode === 'dns_lookup_failed' => 'targetNotFound',
            $apiCode === 'cross_host_redirect_blocked' => 'redirectBlocked',
            in_array($apiCode, ['invalid_start_url', 'invalid_site_id', 'missing_sitemap_url', 'missing_site_url', 'unsafe_url', 'invalid_url', 'invalid_url_scheme', 'invalid_url_host', 'invalid_url_port', 'ip_literal_blocked', 'credentials_not_allowed'], true) => 'invalidTarget',
            $apiCode === 'not_found' || $httpStatus === 404 => 'notFound',
            $httpStatus === 409 => 'conflict',
            $httpStatus === 0 || $httpStatus >= 500 => 'unavailable',
            default => 'failed',
        };

        $status = match ($kind) {
            'trialLimit', 'limit' => 429,
            'auth', 'notAllowed', 'scanNotAccessible', 'domainNotActivated', 'domainNotDetected', 'domainLimit' => 403,
            'invalidTarget', 'targetNotPublic', 'targetNotFound', 'redirectBlocked' => 400,
            'notFound' => 404,
            'conflict' => 409,
            'unavailable' => 503,
            default => $httpStatus >= 400 && $httpStatus <= 599 ? $httpStatus : 500,
        };

        return $this->result(
            $status,
            $apiCode !== '' && preg_match('/^[a-z0-9_]{1,64}$/', $apiCode) === 1 ? $apiCode : $fallbackCode,
            $kind,
            $kind === 'limit' || $kind === 'trialLimit' ? $apiException->retryAfter : null,
        );
    }

    /**
     * One English line for a scan row or the shared status, which every AQG user of the installation can see.
     */
    public function storedMessage(\Throwable $exception, string $context): string
    {
        $presented = $this->present($exception);

        return sprintf('%s (%s)', $context, $presented['code']);
    }

    private function findApiException(\Throwable $exception): ?ApiRequestFailedException
    {
        $current = $exception;
        do {
            if ($current instanceof ApiRequestFailedException) {
                return $current;
            }
            $current = $current->getPrevious();
        } while ($current instanceof \Throwable);

        return null;
    }

    /**
     * @return array{status:int,code:string,title:string,message:string,retryAfter:int|null}
     */
    private function result(int $status, string $code, string $kind, ?int $retryAfter): array
    {
        [$titleFallback, $messageFallback] = match ($kind) {
            'trialLimit' => ['Trial limit reached', 'The trial frontend scan limit has been reached. Choose a PRO or Agency plan to keep scanning.'],
            'limit' => ['Remote scan limit reached', 'The AQG scanner is not accepting more scans from this installation right now. Try again later.'],
            'auth' => ['Licence authentication failed', 'The AQG scanner did not accept the licence. Revalidate the licence in the AQG settings.'],
            'notAllowed' => ['Remote scan not allowed', 'The current licence does not include this frontend scan feature.'],
            'domainNotActivated' => ['Domain not activated', 'This site\'s domain is not activated for the licence. An administrator can activate it under Licence domains in the AQG settings or in the customer portal.'],
            'domainNotDetected' => ['Domain not in this installation', 'No site of this TYPO3 installation uses this domain, so the licence does not cover it. Check the base URL in the site\'s Site Configuration.'],
            'domainLimit' => ['Domain limit reached', 'Every domain of the licence is in use. Deactivate a domain under Licence domains in the AQG settings or in the customer portal, or choose Agency for unlimited domains.'],
            'invalidTarget' => ['Remote scan target rejected', 'The AQG scanner rejected the page or site address. Check the site configuration.'],
            'targetNotPublic' => ['Site not reachable from the internet', self::TARGET_NOT_PUBLIC_MESSAGE],
            'targetNotFound' => ['Site address not found', self::TARGET_NOT_FOUND_MESSAGE],
            'redirectBlocked' => ['Page redirects to another host', 'The page redirects to another host name, and the AQG scanner does not follow redirects to other hosts. Scan the address the page redirects to, or correct the redirect or the site\'s base URL.'],
            'scanNotAccessible' => ['Scan not available to this licence', 'This frontend scan was made with another licence key or for another TYPO3 project, so the current licence cannot read its details. Start a new frontend scan.'],
            'notFound' => ['Remote scan not found', 'The AQG scanner no longer has this scan.'],
            'conflict' => ['Remote scan conflict', 'Another frontend scan for this site is still running. Wait for it to finish.'],
            'unavailable' => ['Remote scanner unavailable', 'The AQG scanner could not be reached or is temporarily unavailable. Try again later.'],
            default => ['Remote scan failed', 'The frontend scan request failed. Details are in the TYPO3 log.'],
        };

        return [
            'status' => $status,
            'code' => $code,
            'title' => BackendLabelUtility::translate('remoteScanError.' . $kind . '.title', $titleFallback),
            'message' => BackendLabelUtility::translate('remoteScanError.' . $kind . '.message', $messageFallback),
            'retryAfter' => $retryAfter,
        ];
    }
}
