<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai\Task;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Contract\AiConfigurationResolverInterface;
use Priebera\A11yQualityGate\Ai\Contract\AiFeatureAccessServiceInterface;
use Priebera\A11yQualityGate\Ai\Dto\AiProviderConfiguration;
use Priebera\A11yQualityGate\Ai\Exception\AiConfigurationException;
use Priebera\A11yQualityGate\Ai\Service\AiPromptDefinition;
use Priebera\A11yQualityGate\Ai\Service\AiRateLimiter;
use Priebera\A11yQualityGate\Ai\Settings\AiSiteSettingsService;
use Priebera\A11yQualityGate\Ai\Task\AiImageInput;
use Priebera\A11yQualityGate\Ai\Task\AiStructuredRequest;
use Priebera\A11yQualityGate\Ai\Task\AiStructuredResponse;
use Priebera\A11yQualityGate\Ai\Task\AiStructuredTaskExecutorInterface;
use Priebera\A11yQualityGate\Ai\Task\AiStructuredTaskRunner;
use Priebera\A11yQualityGate\Ai\Task\AiTask;
use Priebera\A11yQualityGate\Ai\Usage\AiUsage;
use Priebera\A11yQualityGate\Ai\Usage\AiUsageMeter;
use Priebera\A11yQualityGate\Ai\Usage\AiUsageRecorder;
use Priebera\A11yQualityGate\Contract\BackendUserServiceInterface;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiRateLimitRepositoryInterface;
use Priebera\A11yQualityGate\Tests\Unit\Ai\Usage\AiUsageRepositoryDouble;
use Priebera\A11yQualityGate\Tests\Unit\Ai\Fixtures\InMemoryAiConfigurationRepository;
use Priebera\A11yQualityGate\Tests\Unit\Ai\Fixtures\InMemoryAiSiteSettingRepository;

final class AiStructuredTaskRunnerTest extends TestCase
{
    #[Test]
    public function runsTheSiteProviderAndRecordsUsage(): void
    {
        $rows = [];
        $executor = $this->createMock(AiStructuredTaskExecutorInterface::class);
        $executor->method('supports')->willReturnCallback(static fn (string $p): bool => $p === 'anthropic');
        $executor->expects(self::once())->method('execute')->willReturn(new AiStructuredResponse(['status' => 'suggestion', 'alt_text' => 'x'], new AiUsage(5, 1, true), 'anthropic', 'claude-haiku-5-5'));

        $response = $this->runner([$executor], true, true, $rows)->run('main', $this->request(['caption' => 'c']));

        self::assertSame('x', $response->data['alt_text']);
        self::assertSame('alt_text', $rows[0]['feature']);
    }

    #[Test]
    public function siteSwitchesAreEnforcedBeforeAnyRequest(): void
    {
        $executor = $this->createMock(AiStructuredTaskExecutorInterface::class);
        $executor->expects(self::never())->method('execute');
        $rows = [];

        foreach ([
            [false, true, $this->request(['caption' => 'c'], true), 1771002403],
            [true, false, $this->request(['page_title' => 'Private'], false), 1771002405],
        ] as [$images, $titles, $request, $code]) {
            try {
                $this->runner([$executor], $images, $titles, $rows)->run('main', $request);
                self::fail('Expected the site setting to refuse the request.');
            } catch (AiConfigurationException $exception) {
                self::assertSame($code, $exception->getCode());
            }
        }
        self::assertSame([], $rows);
    }

    /** @param array<string,string> $context */
    private function request(array $context, bool $image = false): AiStructuredRequest
    {
        return AiStructuredRequest::withUntrustedContext(
            AiTask::ALT_TEXT,
            AiPromptDefinition::AI_PROMPT_VERSION,
            AiPromptDefinition::DEVELOPER_INSTRUCTIONS,
            $context,
            AiPromptDefinition::structuredOutputFormat(),
            180,
            $image ? AiImageInput::fromDataUrl('data:image/png;base64,AAAA') : null,
        );
    }

    #[Test]
    public function anAssistantQuestionIsOneRequestAndOneUsageRowCarryingOnlyTheConversationRef(): void
    {
        $rows = [];
        $executor = $this->createMock(AiStructuredTaskExecutorInterface::class);
        $executor->method('supports')->willReturn(true);
        $executor->expects(self::once())->method('execute')->willReturn(new AiStructuredResponse(['status' => 'answer'], new AiUsage(5, 1, true), 'anthropic', 'claude-haiku-5-5'));
        $request = new AiStructuredRequest(AiTask::ASSISTANT, 'v', 'instructions', AiPromptDefinition::CONTEXT_WRAPPER_PREFIX . "\n" . '{"question":"secret question"}', 'aqg_toolbar_ask', ['type' => 'object', 'properties' => [], 'required' => [], 'additionalProperties' => false], 100);

        $this->runner([$executor], true, true, $rows)->run('main', $request, 'a1b2c3d4e5f6a1b2c3d4e5f6');

        self::assertCount(1, $rows);
        self::assertSame('assistant', $rows[0]['feature']);
        self::assertSame('a1b2c3d4e5f6a1b2c3d4e5f6', $rows[0]['conversation_ref']);
        self::assertStringNotContainsString('secret question', json_encode($rows[0], JSON_THROW_ON_ERROR), 'usage rows hold no conversation content');
    }

    #[Test]
    public function aFailedProviderRequestKeepsItsUsageRowAndRefAndOtherFeaturesWriteNoRef(): void
    {
        $rows = [];
        $failing = $this->createMock(AiStructuredTaskExecutorInterface::class);
        $failing->method('supports')->willReturn(true);
        $failing->method('execute')->willThrowException(new \Priebera\A11yQualityGate\Ai\Exception\AiProviderException('timeout', 1771002302));
        $request = new AiStructuredRequest(AiTask::ASSISTANT, 'v', 'instructions', AiPromptDefinition::CONTEXT_WRAPPER_PREFIX . "\n{}", 'aqg_toolbar_ask', ['type' => 'object', 'properties' => [], 'required' => [], 'additionalProperties' => false], 100);
        try {
            $this->runner([$failing], true, true, $rows)->run('main', $request, 'ffffffffffffffffffffffff');
            self::fail('expected the provider failure');
        } catch (\Priebera\A11yQualityGate\Ai\Exception\AiProviderException) {
        }
        self::assertSame('failed_1771002302', $rows[0]['outcome']);
        self::assertSame('ffffffffffffffffffffffff', $rows[0]['conversation_ref'], 'a timeout is still counted for the conversation');

        $ok = $this->createMock(AiStructuredTaskExecutorInterface::class);
        $ok->method('supports')->willReturn(true);
        $ok->method('execute')->willReturn(new AiStructuredResponse(['status' => 'suggestion', 'alt_text' => 'x'], new AiUsage(5, 1, true), 'anthropic', 'claude-haiku-5-5'));
        $this->runner([$ok], true, true, $rows)->run('main', $this->request(['caption' => 'c']), 'not-hex; DROP');
        self::assertArrayNotHasKey('conversation_ref', $rows[1], 'only an opaque hex ref is ever written');
    }

    /**
     * @param list<AiStructuredTaskExecutorInterface> $executors
     * @param list<array<string,mixed>> $rows
     */
    private function runner(array $executors, bool $allowImages, bool $allowTitles, array &$rows): AiStructuredTaskRunner
    {
        $access = $this->createMock(AiFeatureAccessServiceInterface::class);
        $access->method('isAvailable')->willReturn(true);
        $settings = new InMemoryAiSiteSettingRepository();
        $settings->savePrivacy('main', $allowImages, $allowTitles);
        $limits = $this->createMock(AiRateLimitRepositoryInterface::class);
        $limits->method('consume')->willReturn(['allowed' => true, 'retryAfter' => 0]);
        $users = $this->createMock(BackendUserServiceInterface::class);
        $users->method('getBackendUserUid')->willReturn(1);
        $resolver = $this->createMock(AiConfigurationResolverInterface::class);
        $resolver->method('resolve')->willReturn(new AiProviderConfiguration('anthropic', 'sk-ant-secret', 'claude-haiku-5-5', 'site', 'fp'));
        $usage = new AiUsageRepositoryDouble(static function (array $row) use (&$rows): void {
            $rows[] = $row;
        });

        return new AiStructuredTaskRunner(
            $access,
            new AiSiteSettingsService($settings, new InMemoryAiConfigurationRepository()),
            new AiRateLimiter($limits, $users),
            $resolver,
            new AiUsageRecorder($usage, new AiUsageMeter(), $users),
            $executors,
        );
    }
}
