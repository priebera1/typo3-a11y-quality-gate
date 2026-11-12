<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Backend\ToolbarItems\Assistant;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Backend\ToolbarItems\Assistant\Ask\AskAqgRepairIntent;
use Priebera\A11yQualityGate\Backend\ToolbarItems\Assistant\Ask\AskAqgService;

/**
 * Repair intent is recognised deterministically (no AI request): explicit requests to fix content in English and German,
 * and ordinary Slovak and Czech requests, open the repair workflow; information questions never do.
 */
final class AskAqgRepairIntentTest extends TestCase
{
    /** @return iterable<string, array{string, string, list<string>}> */
    public static function repairRequests(): iterable
    {
        // The reported request, with and without diacritics.
        yield 'sk reported' => ['Oprav mi túto stránku', AskAqgRepairIntent::MENTION_PAGE, []];
        yield 'sk reported, no diacritics' => ['oprav mi tuto stranku', AskAqgRepairIntent::MENTION_PAGE, []];
        yield 'sk polite element' => ['Opravte prosím tento prvok', AskAqgRepairIntent::MENTION_ELEMENT, []];
        yield 'sk can you, images' => ['Môžeš mi opraviť obrázky?', '', [AskAqgRepairIntent::KIND_IMAGE]];
        yield 'sk whole web' => ['Oprav celý web', AskAqgRepairIntent::MENTION_SITE, []];
        yield 'sk naprav' => ['Napravte to', '', []];
        yield 'cs infinitive' => ['Opravit tuto stránku', AskAqgRepairIntent::MENTION_PAGE, []];
        yield 'cs could you' => ['Mohl bys to opravit?', '', []];
        yield 'en element' => ['Fix this element', AskAqgRepairIntent::MENTION_ELEMENT, []];
        yield 'en all here' => ['Fix all accessibility issues here.', '', []];
        yield 'en prepare' => ['Prepare fixes', '', []];
        yield 'en please alt' => ['Please fix the missing alt text', '', [AskAqgRepairIntent::KIND_IMAGE]];
        yield 'en can you page' => ['Can you fix this page?', AskAqgRepairIntent::MENTION_PAGE, []];
        yield 'en add alt' => ['Add the missing alt text', '', [AskAqgRepairIntent::KIND_IMAGE]];
        yield 'en website' => ['Fix the whole website', AskAqgRepairIntent::MENTION_SITE, []];
        yield 'en buttons and iframes' => ['Repair the buttons and the iframe', '', [AskAqgRepairIntent::KIND_IFRAME, AskAqgRepairIntent::KIND_BUTTON]];
        yield 'de page' => ['Behebe die Probleme auf dieser Seite', AskAqgRepairIntent::MENTION_PAGE, []];
        yield 'de can you' => ['Kannst du die Probleme auf dieser Seite beheben?', AskAqgRepairIntent::MENTION_PAGE, []];
        yield 'de all' => ['Bitte alles beheben', '', []];
        yield 'de element' => ['Repariere bitte dieses Element', AskAqgRepairIntent::MENTION_ELEMENT, []];
        yield 'de prepare' => ['Fixes vorbereiten', '', []];
        yield 'de alt texts' => ['Ergänze die fehlenden Alternativtexte', '', [AskAqgRepairIntent::KIND_IMAGE]];
    }

    /** @param list<string> $kinds */
    #[Test]
    #[DataProvider('repairRequests')]
    public function explicitRepairRequestsAreRecognised(string $question, string $mentions, array $kinds): void
    {
        $intent = AskAqgRepairIntent::detect($question);

        self::assertTrue($intent['repair'], $question);
        self::assertSame($mentions, $intent['mentions'], $question);
        self::assertSame($kinds, $intent['kinds'], $question);
    }

    /** @return iterable<string, array{string}> */
    public static function informationQuestions(): iterable
    {
        foreach ([
            'What is wrong?', 'Explain this issue', 'What should I fix first?', 'Which issues are most important?', 'How do I fix this?',
            'Why is this an accessibility issue?', 'Where can I fix these problems?', 'Is this fixed?', "Don't fix anything", 'Show me the fixes',
            'Was sollte ich zuerst beheben?', 'Wo kann ich das beheben?', 'Wie behebe ich das?', 'Bitte nichts beheben',
            'Čo mám opraviť?', 'Ako to opravím?', 'Prečo je to problém?', 'Neopravuj nič', '', '   ',
        ] as $question) {
            yield $question === '' || trim($question) === '' ? 'empty ' . strlen($question) : $question => [$question];
        }
    }

    #[Test]
    #[DataProvider('informationQuestions')]
    public function informationQuestionsAreNotRepairRequests(string $question): void
    {
        self::assertFalse(AskAqgRepairIntent::detect($question)['repair'], $question);
    }

    #[Test]
    public function theExampleQuestionsKeepTheirDeterministicAnswers(): void
    {
        foreach (['What should I fix first?', 'Where can I fix the missing alt text?', 'Was sollte ich zuerst beheben?', 'What should I improve on this website?'] as $question) {
            self::assertNotSame('', AskAqgService::intentOf('', $question), $question);
            self::assertFalse(AskAqgRepairIntent::detect($question)['repair'], $question);
        }
    }
}
