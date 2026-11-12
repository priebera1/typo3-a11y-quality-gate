<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Contract\AiConfigurationResolverInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiFeatureAccessServiceInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiProviderInterface;
use Priebera\A11yQualityGate\Ai\Dto\AiAltSuggestionRequest;
use Priebera\A11yQualityGate\Ai\Dto\AiAltSuggestionResult;
use Priebera\A11yQualityGate\Ai\Dto\AiImagePayload;
use Priebera\A11yQualityGate\Ai\Dto\AiProviderConfiguration;
use Priebera\A11yQualityGate\Ai\Exception\AiConfigurationException;
use Priebera\A11yQualityGate\Ai\Service\AiAltSuggestionRequestBuilder;
use Priebera\A11yQualityGate\Ai\Service\AiAltSuggestionService;
use Priebera\A11yQualityGate\Ai\Service\AiAltSuggestionValidator;
use Priebera\A11yQualityGate\Ai\Service\AiImagePayloadBuilder;
use Priebera\A11yQualityGate\Ai\Service\AiRateLimiter;
use Priebera\A11yQualityGate\Ai\Settings\AiSiteSettingsService;
use Priebera\A11yQualityGate\Ai\Usage\AiUsage;
use Priebera\A11yQualityGate\Ai\Usage\AiUsageMeter;
use Priebera\A11yQualityGate\Ai\Usage\AiUsageRecorder;
use Priebera\A11yQualityGate\Contract\BackendUserServiceInterface;
use Priebera\A11yQualityGate\Tests\Unit\Ai\Usage\AiUsageRepositoryDouble;
use Priebera\A11yQualityGate\Remediation\Contract\ImageFindingContextResolverInterface;
use Priebera\A11yQualityGate\Remediation\Contract\ImageFindingVersionTokenServiceInterface;
use Priebera\A11yQualityGate\Remediation\Contract\ImageRemediationPermissionServiceInterface;
use Priebera\A11yQualityGate\Remediation\ImageFindingContext;
use Priebera\A11yQualityGate\Tests\Unit\Ai\Fixtures\InMemoryAiConfigurationRepository;
use Priebera\A11yQualityGate\Tests\Unit\Ai\Fixtures\InMemoryAiSiteSettingRepository;

/**
 * Site privacy settings decide what reaches the provider; every provider request leaves one usage row.
 */
final class AiSuggestionPrivacyAndUsageTest extends TestCase
{
    #[Test]
    public function siteThatDisallowsImagesNeverBuildsOrSendsAnImage(): void
    {
        $imageBuilder = $this->createMock(AiImagePayloadBuilder::class);
        $imageBuilder->expects(self::never())->method('build');
        $provider = $this->createMock(AiProviderInterface::class);
        $provider->expects(self::never())->method('suggestAltText');

        try {
            $this->service($imageBuilder, $provider, false, true)->suggest(12);
            self::fail('Expected images to be refused.');
        } catch (AiConfigurationException $exception) {
            self::assertSame(1771002403, $exception->getCode());
        }
    }

    #[Test]
    public function pageTitleIsRemovedWhenTheSiteDoesNotAllowItAndUsageIsRecorded(): void
    {
        $imageBuilder = $this->createMock(AiImagePayloadBuilder::class);
        $imageBuilder->method('build')->willReturn(new AiImagePayload('data:image/jpeg;base64,AAAA', 'image/jpeg'));
        $sent = null;
        $rows = [];
        $meter = new AiUsageMeter();
        $provider = $this->createMock(AiProviderInterface::class);
        $provider->method('supports')->willReturn(true);
        $provider->method('suggestAltText')->willReturnCallback(static function (AiAltSuggestionRequest $request) use (&$sent, $meter): AiAltSuggestionResult {
            $sent = $request;
            $meter->report(new AiUsage(700, 12, true));

            return new AiAltSuggestionResult('suggestion', 'Bridge', 'anthropic', 'claude-haiku-5-5', 'v3');
        });

        $result = $this->service($imageBuilder, $provider, true, false, $meter, $rows)->suggest(12);

        self::assertSame('Bridge', $result['result']->suggestion);
        self::assertInstanceOf(AiAltSuggestionRequest::class, $sent);
        self::assertNull($sent->pageTitle);
        self::assertSame('Element title', $sent->contentTitle);
        self::assertArrayNotHasKey('page_title', $sent->contextPayload());
        self::assertCount(1, $rows);
        self::assertSame(['alt_text', 'success', 700], [$rows[0]['feature'], $rows[0]['outcome'], $rows[0]['input_tokens']]);
    }

    /** @param list<array<string,mixed>> $rows */
    private function service(
        AiImagePayloadBuilder $imageBuilder,
        AiProviderInterface $provider,
        bool $allowImages,
        bool $allowPageTitle,
        ?AiUsageMeter $meter = null,
        array &$rows = [],
    ): AiAltSuggestionService {
        $context = new ImageFindingContext(
            issue: ['rule_id' => 'structured.file_reference_alt'],
            fileReference: ['alternative' => ''],
            siteIdentifier: 'main',
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
        $resolver = $this->createMock(ImageFindingContextResolverInterface::class);
        $resolver->method('resolve')->willReturn($context);
        $featureAccess = $this->createMock(AiFeatureAccessServiceInterface::class);
        $featureAccess->method('isAvailable')->willReturn(true);
        $configuration = $this->createMock(AiConfigurationResolverInterface::class);
        $configuration->method('resolve')->willReturn(new AiProviderConfiguration('anthropic', 'sk-ant-secret', 'claude-haiku-5-5', 'site', 'fp'));
        $requestBuilder = $this->createMock(AiAltSuggestionRequestBuilder::class);
        $requestBuilder->method('build')->willReturn(new AiAltSuggestionRequest(
            dataUrl: 'data:image/jpeg;base64,AAAA',
            mimeType: 'image/jpeg',
            targetLocale: 'en',
            findingType: 'missing',
            pageTitle: 'Private page title',
            contentTitle: 'Element title',
        ));
        $validator = $this->createMock(AiAltSuggestionValidator::class);
        $validator->method('validate')->willReturnArgument(0);
        $settingRepository = new InMemoryAiSiteSettingRepository();
        $settingRepository->savePrivacy('main', $allowImages, $allowPageTitle);
        $usage = new AiUsageRepositoryDouble(static function (array $row) use (&$rows): void {
            $rows[] = $row;
        });

        return new AiAltSuggestionService(
            resolver: $resolver,
            permissionService: $this->createMock(ImageRemediationPermissionServiceInterface::class),
            featureAccessService: $featureAccess,
            configurationResolver: $configuration,
            imagePayloadBuilder: $imageBuilder,
            requestBuilder: $requestBuilder,
            suggestionValidator: $validator,
            rateLimiter: $this->createMock(AiRateLimiter::class),
            versionTokenService: $this->createMock(ImageFindingVersionTokenServiceInterface::class),
            providers: [$provider],
            usageRecorder: new AiUsageRecorder($usage, $meter ?? new AiUsageMeter(), $this->createMock(BackendUserServiceInterface::class)),
            siteSettingsService: new AiSiteSettingsService($settingRepository, new InMemoryAiConfigurationRepository()),
        );
    }
}
