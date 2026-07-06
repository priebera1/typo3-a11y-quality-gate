<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Remediation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Remediation\ImageFindingContext;

final class ImageFindingContextTest extends TestCase
{
    #[Test]
    public function exposesImmutableFindingAndReferenceIdentity(): void
    {
        $context = new ImageFindingContext(
            issue: ['uid' => 12],
            fileReference: ['uid' => 3],
            siteIdentifier: 'site',
            pageUid: 1,
            languageUid: 0,
            sourceTable: 'tt_content',
            sourceUid: 2,
            sourceField: 'image',
            fileReferenceUid: 3,
            fileUid: 4,
            fingerprint: 'fp',
            issueTimestamp: 10,
            fileReferenceTimestamp: 20,
        );

        self::assertSame(12, $context->issue['uid']);
        self::assertSame(3, $context->fileReferenceUid);
        self::assertSame('site', $context->siteIdentifier);
    }
}
