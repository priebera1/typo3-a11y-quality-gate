<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\ExpressionLanguage;

use TYPO3\CMS\Core\ExpressionLanguage\AbstractProvider;

final class AqgTypoScriptConditionProvider extends AbstractProvider
{
    public function __construct()
    {
        $this->expressionLanguageProviders = [
            AqgTypoScriptConditionFunctionsProvider::class,
        ];
    }
}
