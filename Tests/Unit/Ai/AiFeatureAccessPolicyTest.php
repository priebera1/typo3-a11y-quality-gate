<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Service\AiFeatureAccessPolicy;

final class AiFeatureAccessPolicyTest extends TestCase
{
    #[DataProvider('licenceProvider')]
    #[Test]
    public function onlyValidNonTrialProAndAgencyPlansAreAllowed(
        bool $valid,
        bool $trial,
        string $plan,
        bool $expected,
    ): void {
        $status = (object)[
            'valid' => $valid,
            'isTrial' => $trial,
            'plan' => $plan,
        ];

        self::assertSame($expected, (new AiFeatureAccessPolicy())->isAllowed($status));
    }

    public static function licenceProvider(): iterable
    {
        yield 'free' => [false, false, 'free', false];
        yield 'missing' => [false, false, '', false];
        yield 'expired pro' => [false, false, 'pro', false];
        yield 'trial pro' => [true, true, 'pro', false];
        yield 'valid pro' => [true, false, 'PRO', true];
        yield 'valid agency' => [true, false, 'agency', true];
    }
}
