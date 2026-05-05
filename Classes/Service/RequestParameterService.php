<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Utility\FilterValueUtility;
use Psr\Http\Message\ServerRequestInterface;

final class RequestParameterService
{
    public function getPageUid(ServerRequestInterface $request): ?int
    {
        $routing = $request->getAttribute('routing');
        $routingPageUid = (int)($routing?->getArguments()['pageUid'] ?? 0);

        if ($routingPageUid > 0) {
            return $routingPageUid;
        }

        $queryParams = $request->getQueryParams();
        $queryPageUid = (int)($queryParams['pageUid'] ?? $queryParams['id'] ?? 0);

        if ($queryPageUid > 0) {
            return $queryPageUid;
        }

        $parsedBody = $request->getParsedBody();
        $bodyParams = is_array($parsedBody) ? $parsedBody : [];
        $bodyPageUid = (int)($bodyParams['pageUid'] ?? $bodyParams['id'] ?? 0);

        return $bodyPageUid > 0 ? $bodyPageUid : null;
    }

    public function getPageUidOrZero(ServerRequestInterface $request): int
    {
        return $this->getPageUid($request) ?? 0;
    }

    public function getStatus(
        ServerRequestInterface $request,
        string $default = 'open',
    ): string {
        return FilterValueUtility::normalizeStatus(
            (string)($request->getQueryParams()['status'] ?? $default)
        );
    }

    public function getSeverity(
        ServerRequestInterface $request,
        string $default = 'all',
    ): string {
        return FilterValueUtility::normalizeSeverity(
            (string)($request->getQueryParams()['severity'] ?? $default)
        );
    }

    public function getPageNumber(
        ServerRequestInterface $request,
        int $default = 1,
    ): int {
        return max(1, (int)($request->getQueryParams()['page'] ?? $default));
    }

    public function getSiteIdentifier(ServerRequestInterface $request): string
    {
        return trim((string)($request->getQueryParams()['site'] ?? ''));
    }

    public function hasQueryParam(ServerRequestInterface $request, string $name): bool
    {
        return array_key_exists($name, $request->getQueryParams());
    }

    public function getString(
        ServerRequestInterface $request,
        string $name,
        string $default = '',
    ): string {
        return trim((string)($request->getQueryParams()[$name] ?? $default));
    }

    public function getA11yModuleReturnParameters(ServerRequestInterface $request): array
    {
        $queryParams = $request->getQueryParams();
        $parsedBody = $request->getParsedBody();
        $bodyParams = is_array($parsedBody) ? $parsedBody : [];

        $allowedKeys = [
            'id',
            'pageUid',
            'site',
            'pageUid',
            'status',
            'severity',
            'page',
        ];

        $parameters = [];

        foreach ($allowedKeys as $key) {
            $value = $queryParams[$key] ?? $bodyParams[$key] ?? null;

            if ($value === '' || $value === null) {
                continue;
            }

            $parameters[$key] = $value;
        }

        return $parameters;
    }
}