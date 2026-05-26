<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\ExpressionLanguage;

use Priebera\A11yQualityGate\Service\RenderedCheckNonceService;
use Priebera\A11yQualityGate\Service\ScannerAccessTokenService;
use Symfony\Component\ExpressionLanguage\ExpressionFunction;
use Symfony\Component\ExpressionLanguage\ExpressionFunctionProviderInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class AqgTypoScriptConditionFunctionsProvider implements ExpressionFunctionProviderInterface
{
    public function getFunctions(): array
    {
        return [
            new ExpressionFunction(
                'aqgDebugMarkers',
                static function (string $request = 'null'): string {
                    return '\\' . self::class . '::evaluate(' . $request . ')';
                },
                static function (array $arguments, mixed $request = null): bool {
                    return self::evaluate($request);
                }
            ),
        ];
    }

    public static function evaluate(mixed $request = null): bool
    {
        $queryParams = self::getQueryParams($request);
        if ((string)($queryParams['aqgDebug'] ?? '') !== '1') {
            return false;
        }

        $isRenderedCheckRequest = (string)($queryParams['tx_aqg_rendered_check'] ?? '') === '1';
        $scannerToken = self::getScannerToken($request);
        $hasValidScannerToken = $scannerToken !== ''
            && GeneralUtility::makeInstance(ScannerAccessTokenService::class)->isValidToken($scannerToken);

        if ($isRenderedCheckRequest) {
            $nonceIsValid = GeneralUtility::makeInstance(RenderedCheckNonceService::class)->isValidParameters(
                (int)($queryParams['_aqg_page'] ?? $queryParams['id'] ?? 0),
                (int)($queryParams['_aqg_lang'] ?? $queryParams['L'] ?? 0),
                trim((string)($queryParams['_aqg_nonce'] ?? ''))
            );

            return $nonceIsValid || $hasValidScannerToken;
        }

        return self::isBackendUserLoggedIn() || $hasValidScannerToken;
    }

    /**
     * @return array<string, mixed>
     */
    private static function getQueryParams(mixed $request): array
    {
        if (is_object($request) && method_exists($request, 'getQueryParams')) {
            $queryParams = $request->getQueryParams();
            return is_array($queryParams) ? $queryParams : [];
        }

        return [];
    }

    private static function getScannerToken(mixed $request): string
    {
        if (is_object($request) && method_exists($request, 'getHeaderLine')) {
            return trim((string)$request->getHeaderLine('X-AQG-Scanner-Token'));
        }

        return '';
    }

    private static function isBackendUserLoggedIn(): bool
    {
        try {
            return (bool)GeneralUtility::makeInstance(Context::class)->getPropertyFromAspect(
                'backend.user',
                'isLoggedIn',
                false
            );
        } catch (\Throwable) {
            return false;
        }
    }
}
