<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Backend\ToolbarItems\Assistant;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Backend\ToolbarItems\Assistant\Ask\AskAqgFindingGuidance;
use Priebera\A11yQualityGate\Backend\ToolbarItems\Assistant\Ask\AskAqgLimits;
use Priebera\A11yQualityGate\Backend\ToolbarItems\Assistant\Ask\AskAqgRepairIntent;
use Priebera\A11yQualityGate\Backend\ToolbarItems\Assistant\Ask\AskAqgSelection;

/**
 * "Fix problem 1" means exactly the finding that was number 1 in the answer the editor saw: numbers are parsed from the
 * words deterministically and resolved only through the server-signed list of that conversation (user, login session,
 * context and conversation bound), never re-sorted, guessed or carried into another context.
 */
final class AskAqgSelectionTest extends TestCase
{
    private const BINDING = 'session-hash|record:0:main:40:0:tt_content:90:0';

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = str_repeat('k', 96);
    }

    /** @return iterable<string, array{string, list<int>}> */
    public static function references(): iterable
    {
        yield 'en problem 1' => ['Fix problem 1', [1]];
        yield 'en problem #2' => ['Fix problem #2.', [2]];
        yield 'en two and range' => ['Please fix problems 1, 3 and 5 to 6', [1, 3, 5, 6]];
        yield 'en bare number after verb' => ['fix 4', [4]];
        yield 'en first one' => ['Fix the first one', [1]];
        yield 'en second problem' => ['Can you repair the second problem?', [2]];
        yield 'de problem' => ['Behebe Problem 3', [3]];
        yield 'de nummer' => ['Bitte korrigiere Problem Nr. 2', [2]];
        yield 'de erste' => ['Behebe das erste Problem', [1]];
        yield 'sk problem' => ['Oprav problém 2', [2]];
        yield 'sk range' => ['oprav problémy 1 až 3', [1, 2, 3]];
        yield 'none: whole page' => ['Fix all problems on this page', []];
        yield 'none: what first' => ['What should I fix first?', []];
        yield 'none: out of range' => ['Fix problem 99', []];
        yield 'none: a count, not a number' => ['Fix 3 images', []];
        yield 'none: sk count' => ['oprav 2 obrázky', []];
        yield 'none: first heading' => ['Fix the first heading', []];
    }

    /** @param list<int> $expected */
    #[Test]
    #[DataProvider('references')]
    public function numbersAreReadFromTheWordsOnly(string $question, array $expected): void
    {
        self::assertSame($expected, AskAqgRepairIntent::references($question));
    }

    #[Test]
    public function aNumberedRequestIsStillARepairRequest(): void
    {
        self::assertTrue(AskAqgRepairIntent::detect('Fix problem 1')['repair']);
        self::assertTrue(AskAqgRepairIntent::detect('Behebe Problem 2')['repair']);
        self::assertFalse(AskAqgRepairIntent::detect('What problems does this page have?')['repair']);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function listQuestions(): iterable
    {
        yield 'en what problems' => ['What problems does this page have?', true];
        yield 'en which issues' => ['Which issues are on this page?', true];
        yield 'en show' => ['Show me the problems', true];
        yield 'en what is wrong' => ['What is wrong here?', true];
        yield 'de welche' => ['Welche Probleme hat diese Seite?', true];
        yield 'sk ake' => ['Aké problémy má táto stránka?', true];
        yield 'en why: not a list' => ['Why is this an accessibility issue?', false];
        yield 'en what first: not a list' => ['What should I fix first?', false];
        yield 'en example question keeps its answer' => ['Which issues are most important?', false];
        yield 'en priority keeps the AI answer' => ['Which errors matter most for screen readers?', false];
    }

    #[Test]
    #[DataProvider('listQuestions')]
    public function listQuestionsAreRecognised(string $question, bool $expected): void
    {
        self::assertSame($expected, AskAqgRepairIntent::asksForList($question));
    }

    #[Test]
    public function aSignedListResolvesEachNumberToItsFinding(): void
    {
        $token = AskAqgSelection::issue('page', [501, 77, 902], 7, self::BINDING, 'v2.conv', 1000);
        $selection = AskAqgSelection::verify($token, 7, self::BINDING, 'v2.conv', 1001);

        self::assertNotNull($selection);
        self::assertSame('page', $selection->target);
        self::assertSame([1 => 501], $selection->resolve([1]));
        self::assertSame([2 => 77, 3 => 902], $selection->resolve([2, 3]));
        self::assertSame(2, $selection->numberOf(77));
        // A number that is not in the list is never shifted onto another finding.
        self::assertNull($selection->resolve([4]));
        self::assertNull($selection->resolve([1, 4]));
        self::assertNull($selection->resolve([]));
    }

    #[Test]
    public function aListIsUsableOnlyByItsUserSessionContextAndConversation(): void
    {
        $token = AskAqgSelection::issue('record', [11, 12], 7, self::BINDING, 'v2.conv', 1000);

        self::assertNotNull(AskAqgSelection::verify($token, 7, self::BINDING, 'v2.conv', 1000));
        self::assertNull(AskAqgSelection::verify($token, 8, self::BINDING, 'v2.conv', 1000), 'another backend user');
        self::assertNull(AskAqgSelection::verify($token, 7, 'other-session|record:0:main:40:0:tt_content:90:0', 'v2.conv', 1000), 'another login session');
        self::assertNull(AskAqgSelection::verify($token, 7, 'session-hash|page:0:main:41:0::0:0', 'v2.conv', 1000), 'another page or record');
        self::assertNull(AskAqgSelection::verify($token, 7, self::BINDING, 'v2.other', 1000), 'another conversation');
        self::assertNull(AskAqgSelection::verify($token, 7, self::BINDING, '', 1000), 'no conversation');
        self::assertNull(AskAqgSelection::verify($token, 7, self::BINDING, 'v2.conv', 1000 + AskAqgLimits::CONVERSATION_TTL_SECONDS), 'expired');
        self::assertNull(AskAqgSelection::verify($token, 7, self::BINDING, 'v2.conv', 999), 'issued in the future');
    }

    #[Test]
    public function anAlteredListIsRefused(): void
    {
        $token = AskAqgSelection::issue('page', [501, 77], 7, self::BINDING, 'v2.conv', 1000);
        $reordered = str_replace('501-77', '77-501', $token);
        $retargeted = str_replace('s1.page.', 's1.record.', $token);

        self::assertNotSame($token, $reordered);
        self::assertNull(AskAqgSelection::verify($reordered, 7, self::BINDING, 'v2.conv', 1000));
        self::assertNull(AskAqgSelection::verify($retargeted, 7, self::BINDING, 'v2.conv', 1000));
        self::assertNull(AskAqgSelection::verify('s1.page.1000.501.' . str_repeat('a', 64), 7, self::BINDING, 'v2.conv', 1000));
        self::assertNull(AskAqgSelection::verify('not a token', 7, self::BINDING, 'v2.conv', 1000));
    }

    #[Test]
    public function theHeadingOutlineIsReadFromTheSavedText(): void
    {
        $outline = AskAqgFindingGuidance::headingsOf('<h2>Overview</h2><p>Text</p><h5 class="x">Details &amp; <em>more</em></h5><h3></h3>');

        self::assertSame([
            ['level' => 2, 'text' => 'Overview', 'flagged' => false],
            ['level' => 5, 'text' => 'Details & more', 'flagged' => false],
            ['level' => 3, 'text' => '…', 'flagged' => false],
        ], $outline);
        self::assertSame([], AskAqgFindingGuidance::headingsOf('<p>No headings</p>'));
    }

    #[Test]
    public function renderedFindingsAreNeverTreatedAsEditableContent(): void
    {
        self::assertTrue(AskAqgFindingGuidance::isRendered(['rule_id' => 'rendered.empty_link', 'source_table' => 'pages', 'source_field' => '__rendered_html']));
        self::assertTrue(AskAqgFindingGuidance::isRendered(['rule_id' => 'rte.x', 'source_table' => 'pages', 'source_field' => '__rendered_html']));
        self::assertFalse(AskAqgFindingGuidance::isRendered(['rule_id' => 'rte.heading_hierarchy_jump', 'source_table' => 'tt_content', 'source_field' => 'bodytext']));
    }
}
