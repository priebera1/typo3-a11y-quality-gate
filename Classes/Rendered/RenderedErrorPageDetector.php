<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rendered;

final class RenderedErrorPageDetector
{
    public function detect(string $html): RenderedErrorPageDetectionResult
    {
        $trimmedHtml = trim($html);
        if ($trimmedHtml === '') {
            return new RenderedErrorPageDetectionResult(false);
        }

        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML($trimmedHtml, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $xpath = new \DOMXPath($dom);

        foreach ([
            '//*[contains(concat(" ", normalize-space(@class), " "), " typo3-exception ")]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " sf-reset ")]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " sf-body ")]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " exception-message ")]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " stacktrace ")]',
            '//*[contains(concat(" ", normalize-space(@class), " "), " xdebug-error ")]',
        ] as $query) {
            $nodes = $xpath->query($query);
            if ($nodes instanceof \DOMNodeList && $nodes->length > 0) {
                return new RenderedErrorPageDetectionResult(true, 'technical_exception_markup');
            }
        }

        $body = $xpath->query('//body')->item(0);
        $bodyText = trim(preg_replace('/\s+/', ' ', $body?->textContent ?? ''));
        if ($bodyText === '') {
            return new RenderedErrorPageDetectionResult(false);
        }

        $hasTitle = $this->hasMeaningfulNodeText($xpath, '//head/title');
        $hasPageStructure = $this->hasAnyNode($xpath, '//main|//*[@role="main"]|//header|//nav|//footer|//*[@id="main"]|//*[@class="main"]');
        $bodyElementChildren = $this->countElementChildren($body);

        $technicalErrorReason = $this->technicalErrorReason($bodyText);
        if (!$hasTitle && !$hasPageStructure && $bodyElementChildren <= 2 && $technicalErrorReason !== '') {
            return new RenderedErrorPageDetectionResult(true, $technicalErrorReason);
        }

        if (!$hasPageStructure && $bodyElementChildren <= 3 && $this->looksLikePhpOrSymfonyError($bodyText)) {
            return new RenderedErrorPageDetectionResult(true, 'php_or_symfony_error_output');
        }

        return new RenderedErrorPageDetectionResult(false);
    }

    private function hasMeaningfulNodeText(\DOMXPath $xpath, string $query): bool
    {
        $nodes = $xpath->query($query);
        if (!$nodes instanceof \DOMNodeList) {
            return false;
        }

        foreach ($nodes as $node) {
            if (trim((string)$node->textContent) !== '') {
                return true;
            }
        }

        return false;
    }

    private function hasAnyNode(\DOMXPath $xpath, string $query): bool
    {
        $nodes = $xpath->query($query);
        return $nodes instanceof \DOMNodeList && $nodes->length > 0;
    }

    private function countElementChildren(?\DOMNode $node): int
    {
        if (!$node instanceof \DOMNode) {
            return 0;
        }

        $count = 0;
        foreach ($node->childNodes as $childNode) {
            if ($childNode instanceof \DOMElement) {
                $count++;
            }
        }

        return $count;
    }

    private function technicalErrorReason(string $bodyText): string
    {
        foreach ([
            'Application exception' => 'application_exception',
            'Technical error' => 'technical_error',
            'Uncaught TYPO3 Exception' => 'typo3_exception',
            'TYPO3 Exception' => 'typo3_exception',
            'Core: Exception handler' => 'typo3_exception_handler',
            'Oops, an error occurred!' => 'generic_error_page',
            'PHP Fatal error:' => 'php_fatal_error',
            'Fatal error:' => 'php_fatal_error',
            'Symfony Component ErrorHandler Exception' => 'symfony_error_handler_exception',
            'cURL error' => 'curl_error',
            'Client error:' => 'http_client_error',
            'upstream timed out' => 'upstream_timeout',
            'Connection refused' => 'connection_refused',
            'Name or service not known' => 'dns_resolution_failed',
        ] as $needle => $reason) {
            if (str_contains($bodyText, $needle)) {
                return $reason;
            }
        }

        if ($this->containsAllPatterns($bodyText, ['/\bException\b/i', '/Server error:/i'])) {
            return 'exception_server_error';
        }
        if ($this->containsAllPatterns($bodyText, ['/\bException\b/i', '/connect(?:ion)?\s+fail/i'])) {
            return 'exception_connection_failed';
        }
        if ($this->containsAllPatterns($bodyText, ['/\bException\b/i', '/GET\s+https?:\/\//i'])) {
            return 'exception_http_request_error';
        }
        if ($this->containsAllPatterns($bodyText, ['/\bException\b/i', '/Stack trace:/i'])) {
            return 'exception_stack_trace';
        }
        if ($this->containsAllPatterns($bodyText, ['/Server error:/i', '/GET\s+https?:\/\//i'])) {
            return 'server_error_http_request';
        }

        return '';
    }

    /**
     * @param list<string> $patterns
     */
    private function containsAllPatterns(string $text, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text) !== 1) {
                return false;
            }
        }

        return true;
    }

    private function looksLikePhpOrSymfonyError(string $bodyText): bool
    {
        return (bool)preg_match('/(?:Fatal error|Parse error|Warning): .* in .* on line \d+/i', $bodyText)
            || (str_contains($bodyText, 'Stack trace:') && str_contains($bodyText, 'thrown in'));
    }
}
