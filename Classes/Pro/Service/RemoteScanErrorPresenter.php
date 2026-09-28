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
            in_array($apiCode, ['invalid_token', 'token_expired', 'token_revoked', 'unauthorized'], true)
                || str_starts_with($apiCode, 'licence_')
                || $httpStatus === 401 => 'auth',
            in_array($apiCode, ['feature_not_available', 'feature_not_enabled', 'forbidden_resource'], true) || $httpStatus === 403 => 'notAllowed',
            in_array($apiCode, ['invalid_start_url', 'invalid_site_id', 'missing_sitemap_url', 'missing_site_url', 'unsafe_url'], true) => 'invalidTarget',
            $apiCode === 'not_found' || $httpStatus === 404 => 'notFound',
            $httpStatus === 409 => 'conflict',
            $httpStatus === 0 || $httpStatus >= 500 => 'unavailable',
            default => 'failed',
        };

        $status = match ($kind) {
            'trialLimit', 'limit' => 429,
            'auth', 'notAllowed' => 403,
            'invalidTarget' => 400,
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
            'invalidTarget' => ['Remote scan target rejected', 'The AQG scanner rejected the page or site address. Check the site configuration.'],
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
