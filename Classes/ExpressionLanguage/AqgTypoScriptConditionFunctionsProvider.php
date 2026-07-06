<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\ExpressionLanguage;

use Priebera\A11yQualityGate\Service\RenderedCheckNonceService;
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
        $request = self::resolveRequest($request);

        if (self::requestAttributeIsTrue($request, 'aqgDebugMarkers')) {
            return true;
        }
        if (self::requestAttributeIsTrue($request, 'aqgScannerPreviewTokenValid')) {
            return true;
        }

        $queryParams = self::getQueryParams($request);
        $debugRequested = (string)($queryParams['aqgDebug'] ?? '') === '1';
        if (!$debugRequested) {
            return false;
        }

        $isRenderedCheckRequest = (string)($queryParams['tx_aqg_rendered_check'] ?? '') === '1';
        if ($isRenderedCheckRequest) {
            return GeneralUtility::makeInstance(RenderedCheckNonceService::class)->isValidParameters(
                (int)($queryParams['_aqg_page'] ?? $queryParams['id'] ?? 0),
                (int)($queryParams['_aqg_lang'] ?? $queryParams['L'] ?? 0),
                trim((string)($queryParams['_aqg_nonce'] ?? ''))
            );
        }

        return self::isBackendUserLoggedIn();
    }


    private static function resolveRequest(mixed $request): mixed
    {
        if (is_object($request)) {
            return $request;
        }

        return $GLOBALS['TYPO3_REQUEST'] ?? null;
    }

    private static function requestAttributeIsTrue(mixed $request, string $attributeName): bool
    {
        if (is_object($request) && method_exists($request, 'getAttribute')) {
            return $request->getAttribute($attributeName, false) === true;
        }

        return false;
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
