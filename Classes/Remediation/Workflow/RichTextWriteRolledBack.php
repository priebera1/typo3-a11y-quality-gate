<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Remediation\Workflow;

/**
 * @internal Rolls back RichTextRecordWriter's transaction when the stored value is not the reviewed value; never leaves
 *           the writer.
 */
final class RichTextWriteRolledBack extends \RuntimeException
{
    /** @param list<string> $errors DataHandler's error log (logged, never shown). */
    public function __construct(public readonly string $outcome, public readonly array $errors)
    {
        parent::__construct($outcome);
    }
}
