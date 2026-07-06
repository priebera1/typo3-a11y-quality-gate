<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Contract\AiConfigurationResolverInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiFeatureAccessServiceInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiProviderInterface;
use Priebera\A11yQualityGate\Ai\Dto\AiAltSuggestionRequest;
use Priebera\A11yQualityGate\Ai\Dto\AiImagePayload;
use Priebera\A11yQualityGate\Ai\Dto\AiProviderConfiguration;
use Priebera\A11yQualityGate\Ai\Exception\AiConfigurationException;
use Priebera\A11yQualityGate\Ai\Service\AiAltSuggestionRequestBuilder;
use Priebera\A11yQualityGate\Ai\Service\AiAltSuggestionService;
use Priebera\A11yQualityGate\Ai\Service\AiAltSuggestionValidator;
use Priebera\A11yQualityGate\Ai\Service\AiImagePayloadBuilder;
use Priebera\A11yQualityGate\Ai\Service\AiRateLimiter;
use Priebera\A11yQualityGate\Remediation\Contract\ImageFindingContextResolverInterface;
use Priebera\A11yQualityGate\Remediation\Contract\ImageFindingVersionTokenServiceInterface;
use Priebera\A11yQualityGate\Remediation\Contract\ImageRemediationPermissionServiceInterface;
use Priebera\A11yQualityGate\Remediation\ImageFindingContext;

final class AiAltSuggestionServiceTest extends TestCase
{
    #[Test]
    public function freeOrExpiredLicenceIsRejectedBeforeConfigurationImageOrProviderWork(): void
    {
        $context = $this->context();
        $resolver = $this->createMock(ImageFindingContextResolverInterface::class);
        $resolver->expects(self::once())->method('resolve')->with(12)->willReturn($context);

        $permission = $this->createMock(ImageRemediationPermissionServiceInterface::class);
        $permission->expects(self::once())->method('assertCanModify')->with($context, [], 'ai-alt-suggestion');

        $featureAccess = $this->createMock(AiFeatureAccessServiceInterface::class);
        $featureAccess->expects(self::once())->method('isAvailable')->with('test')->willReturn(false);

        $configuration = $this->createMock(AiConfigurationResolverInterface::class);
        $configuration->expects(self::never())->method('resolve');

        $subject = new AiAltSuggestionService(
            resolver: $resolver,
            permissionService: $permission,
            featureAccessService: $featureAccess,
            configurationResolver: $configuration,
            imagePayloadBuilder: $this->withoutConstructor(AiImagePayloadBuilder::class),
            requestBuilder: $this->withoutConstructor(AiAltSuggestionRequestBuilder::class),
            suggestionValidator: $this->withoutConstructor(AiAltSuggestionValidator::class),
            rateLimiter: $this->withoutConstructor(AiRateLimiter::class),
            versionTokenService: $this->createMock(ImageFindingVersionTokenServiceInterface::class),
            providers: [],
        );

        $this->expectException(AiConfigurationException::class);
        $this->expectExceptionCode(1771002401);

        $subject->suggest(12);
    }

    #[Test]
    public function linkedImageWithoutPurposeReturnsNeedsReviewWithoutProviderCall(): void
    {
        $context = $this->context();
        $resolver = $this->createMock(ImageFindingContextResolverInterface::class);
        $resolver->method('resolve')->with(12)->willReturn($context);
        $permission = $this->createMock(ImageRemediationPermissionServiceInterface::class);
        $featureAccess = $this->createMock(AiFeatureAccessServiceInterface::class);
        $featureAccess->method('isAvailable')->willReturn(true);
        $configurationResolver = $this->createMock(AiConfigurationResolverInterface::class);
        $configurationResolver->method('resolve')->willReturn(new AiProviderConfiguration(
            'openai',
            'secret',
            'gpt-5.4-mini',
            'site',
        ));
        $imageBuilder = $this->createMock(AiImagePayloadBuilder::class);
        $imagePayload = new AiImagePayload('data:image/jpeg;base64,AAAA', 'image/jpeg');
        $imageBuilder->method('build')->with($context)->willReturn($imagePayload);
        $requestBuilder = $this->createMock(AiAltSuggestionRequestBuilder::class);
        $requestBuilder->method('build')->with($context, $imagePayload)->willReturn(new AiAltSuggestionRequest(
            dataUrl: $imagePayload->dataUrl,
            mimeType: $imagePayload->mimeType,
            targetLocale: 'en-GB',
            findingType: 'missing',
            isLinked: true,
            linkPurpose: null,
        ));
        $rateLimiter = $this->createMock(AiRateLimiter::class);
        $versionService = $this->createMock(ImageFindingVersionTokenServiceInterface::class);
        $versionService->method('create')->with($context)->willReturn('version-token');
        $provider = $this->createMock(AiProviderInterface::class);
        $provider->expects(self::never())->method('suggestAltText');

        $result = (new AiAltSuggestionService(
            resolver: $resolver,
            permissionService: $permission,
            featureAccessService: $featureAccess,
            configurationResolver: $configurationResolver,
            imagePayloadBuilder: $imageBuilder,
            requestBuilder: $requestBuilder,
            suggestionValidator: $this->withoutConstructor(AiAltSuggestionValidator::class),
            rateLimiter: $rateLimiter,
            versionTokenService: $versionService,
            providers: [$provider],
        ))->suggest(12);

        self::assertSame('needs_review', $result['result']->status);
        self::assertSame('', $result['result']->suggestion);
        self::assertSame('version-token', $result['version']);
    }

    /** @template T of object @param class-string<T> $className @return T */
    private function withoutConstructor(string $className): object
    {
        return (new \ReflectionClass($className))->newInstanceWithoutConstructor();
    }

    private function context(): ImageFindingContext
    {
        return new ImageFindingContext(
            issue: ['rule_id' => 'structured.file_reference_alt'],
            fileReference: ['alternative' => ''],
            siteIdentifier: 'test',
            pageUid: 10,
            languageUid: 0,
            sourceTable: 'tt_content',
            sourceUid: 42,
            sourceField: 'image',
            fileReferenceUid: 44,
            fileUid: 55,
            fingerprint: 'abc',
            issueTimestamp: 100,
            fileReferenceTimestamp: 200,
        );
    }
}
