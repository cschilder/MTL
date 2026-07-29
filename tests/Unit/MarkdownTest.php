<?php

declare(strict_types=1);

namespace MTL\Tests\Unit;

use MTL\Markdown\Markdown;
use MTL\Markdown\MarkdownOptions;
use MTL\Tests\TestCase;

defined('MTL_APP') || exit;

/**
 * The markdown renderer.
 *
 * The cases here are the ones that a naive regex-based renderer gets wrong and
 * that turn up in real writing: emphasis inside words, nested emphasis, links
 * whose labels contain formatting, and anything that could smuggle HTML into
 * the page.
 */
final class MarkdownTest extends TestCase
{
    private function render(string $markdown): string
    {
        return Markdown::render($markdown, new MarkdownOptions(headingOffset: 0, headingAnchors: false, collectHeadings: false));
    }

    // -------------------------------------------------------------------------
    // Emphasis
    // -------------------------------------------------------------------------

    public function testEmphasisBasics(): void
    {
        $this->assertHtmlSame('<p><em>a</em></p>', $this->render('*a*'));
        $this->assertHtmlSame('<p><strong>a</strong></p>', $this->render('**a**'));
        $this->assertHtmlSame('<p><em>a</em></p>', $this->render('_a_'));
        $this->assertHtmlSame('<p><strong>a</strong></p>', $this->render('__a__'));
    }

    public function testUnderscoresInsideWordsAreLiteral(): void
    {
        // snake_case identifiers and file names appear constantly in a report
        // about anything technical; turning them into emphasis is the classic
        // failure of a regex renderer.
        $this->assertHtmlSame('<p>snake_case_name</p>', $this->render('snake_case_name'));
        $this->assertHtmlSame('<p>a_b_c</p>', $this->render('a_b_c'));
    }

    public function testAsterisksInsideWordsStillEmphasise(): void
    {
        // Unlike underscores, intraword asterisks do delimit emphasis.
        $this->assertHtmlSame('<p>a<em>b</em>c</p>', $this->render('a*b*c'));
    }

    public function testNestedEmphasis(): void
    {
        $this->assertHtmlSame('<p><em>a <strong>b</strong> c</em></p>', $this->render('*a **b** c*'));
        $this->assertHtmlSame('<p><strong>a <em>b</em> c</strong></p>', $this->render('**a *b* c**'));
    }

    public function testUnmatchedDelimitersStayLiteral(): void
    {
        $this->assertHtmlSame('<p>*a</p>', $this->render('*a'));
        $this->assertHtmlSame('<p>2 * 3 * 4</p>', $this->render('2 * 3 * 4'));
    }

    public function testStrikethrough(): void
    {
        $this->assertHtmlSame('<p><del>gone</del></p>', $this->render('~~gone~~'));
        $this->assertHtmlSame('<p><del>gone</del></p>', $this->render('~gone~'));
    }

    // -------------------------------------------------------------------------
    // Code
    // -------------------------------------------------------------------------

    public function testCodeSpanTakesContentsLiterally(): void
    {
        $html = $this->render('`**not bold**`');

        $this->assertContains('<code>**not bold**</code>', $html);
        $this->assertNotContains('<strong>', $html);
    }

    public function testCodeSpanWithBacktickInside(): void
    {
        $this->assertContains('<code>a ` b</code>', $this->render('`` a ` b ``'));
    }

    public function testFencedCodeKeepsLanguage(): void
    {
        $html = $this->render("```php\n\$a = 1;\n```");

        $this->assertContains('<code class="language-php">', $html);
        $this->assertContains('$a = 1;', $html);
    }

    public function testIndentedCode(): void
    {
        $this->assertContains('<pre><code>een regel</code></pre>', $this->render("    een regel"));
    }

    // -------------------------------------------------------------------------
    // Security: no raw HTML, no dangerous URLs
    // -------------------------------------------------------------------------

    public function testRawHtmlIsEscaped(): void
    {
        $html = $this->render('<script>alert(1)</script>');

        $this->assertNotContains('<script>', $html);
        $this->assertContains('&lt;script&gt;', $html);
    }

    public function testRawHtmlAttributesCannotEscapeThroughLinkTitle(): void
    {
        $html = $this->render('[x](/a "\" onmouseover=\"alert(1)")');

        $this->assertNotContains('onmouseover="alert', $html);
    }

    public function testJavascriptUrlsAreRefused(): void
    {
        $html = $this->render('[click](javascript:alert(1))');

        $this->assertNotContains('javascript:', $html);
        $this->assertNotContains('<a', $html);
    }

    public function testObfuscatedJavascriptUrlIsRefused(): void
    {
        // Browsers strip control characters before reading the scheme, so a
        // check that only looked at the literal prefix would be fooled. Here
        // the whitespace also ends the destination, so no link forms at all —
        // both paths have to hold.
        $html = $this->render("[click](java\tscript:alert(1))");

        $this->assertNotContains('<a ', $html);

        $this->assertFalse(\MTL\Markdown\Url::isSafe("java\tscript:alert(1)"));
        $this->assertFalse(\MTL\Markdown\Url::isSafe("java\nscript:alert(1)"));
        $this->assertFalse(\MTL\Markdown\Url::isSafe(" JAVASCRIPT:alert(1)"));
        $this->assertFalse(\MTL\Markdown\Url::isSafe("vbscript:msgbox(1)"));
    }

    public function testDataUrlsAreRefused(): void
    {
        $html = $this->render('![x](data:text/html;base64,PHNjcmlwdD4=)');

        $this->assertNotContains('data:text/html', $html);
    }

    public function testRelativeAndAnchorLinksAreAllowed(): void
    {
        $this->assertContains('href="/trips/ijsland"', $this->render('[x](/trips/ijsland)'));
        $this->assertContains('href="#dag-1"', $this->render('[x](#dag-1)'));
    }

    public function testExternalLinksGetRelAttributes(): void
    {
        $html = $this->render('[x](https://example.com)');

        $this->assertContains('rel="nofollow noopener"', $html);
        $this->assertContains('target="_blank"', $html);
    }

    // -------------------------------------------------------------------------
    // Links and images
    // -------------------------------------------------------------------------

    public function testLinkWithFormattedLabel(): void
    {
        $this->assertHtmlSame(
            '<p><a href="/a"><strong>vet</strong> label</a></p>',
            $this->render('[**vet** label](/a)')
        );
    }

    public function testReferenceLink(): void
    {
        $html = $this->render("Zie [de reis][r].\n\n[r]: /trips/x \"Titel\"");

        $this->assertContains('href="/trips/x"', $html);
        $this->assertContains('title="Titel"', $html);
    }

    public function testReferenceDefinedBeforeUse(): void
    {
        $html = $this->render("[r]: /trips/x\n\nZie [de reis][r].");

        $this->assertContains('href="/trips/x"', $html);
    }

    public function testShortcutReferenceLink(): void
    {
        $html = $this->render("Zie [ijsland].\n\n[ijsland]: /trips/ijsland");

        $this->assertContains('href="/trips/ijsland"', $html);
    }

    public function testImageProducesAltText(): void
    {
        $html = $this->render('![een berg](/media/large/abc)');

        $this->assertContains('alt="een berg"', $html);
        $this->assertContains('loading="lazy"', $html);
    }

    public function testBareUrlIsLinkified(): void
    {
        $html = $this->render('Zie https://example.com/x voor meer.');

        $this->assertContains('<a href="https://example.com/x"', $html);
    }

    public function testTrailingPunctuationIsNotPartOfBareUrl(): void
    {
        $html = $this->render('Zie https://example.com/x.');

        $this->assertContains('href="https://example.com/x"', $html);
        $this->assertContains('</a>.', $html);
    }

    public function testUrlInsideCodeSpanIsNotLinkified(): void
    {
        $html = $this->render('`https://example.com`');

        $this->assertNotContains('<a ', $html);
    }

    // -------------------------------------------------------------------------
    // Blocks
    // -------------------------------------------------------------------------

    public function testHeadings(): void
    {
        $this->assertHtmlSame('<h1>Titel</h1>', $this->render('# Titel'));
        $this->assertHtmlSame('<h3>Titel</h3>', $this->render('### Titel'));
        $this->assertHtmlSame('<h1>Titel</h1>', $this->render("Titel\n====="));
        $this->assertHtmlSame('<h2>Titel</h2>', $this->render("Titel\n-----"));
    }

    public function testHeadingOffsetAndAnchors(): void
    {
        // The default document options push headings below the page's own h1.
        $html = Markdown::render('# Dag een', MarkdownOptions::document());

        $this->assertContains('<h2 id="dag-een"', $html);
        $this->assertContains('mtl-heading-anchor', $html);
    }

    public function testThematicBreak(): void
    {
        $this->assertHtmlSame('<hr>', $this->render('---'));
        $this->assertHtmlSame('<hr>', $this->render('***'));
    }

    public function testBlockQuote(): void
    {
        $this->assertHtmlSame('<blockquote><p>een citaat</p></blockquote>', $this->render('> een citaat'));
    }

    public function testNestedBlockQuote(): void
    {
        $html = $this->render("> buiten\n> > binnen");

        $this->assertContains('<blockquote>', $html);
        $this->assertContains('binnen', $html);
        $this->assertSame(2, substr_count($html, '<blockquote>'));
    }

    public function testTightList(): void
    {
        $this->assertHtmlSame('<ul><li>een</li><li>twee</li></ul>', $this->render("- een\n- twee"));
    }

    public function testLooseListWrapsItemsInParagraphs(): void
    {
        $html = $this->render("- een\n\n- twee");

        $this->assertContains('<li><p>een</p>', $html);
    }

    public function testOrderedListKeepsItsStartNumber(): void
    {
        $html = $this->render("3. drie\n4. vier");

        $this->assertContains('<ol start="3">', $html);
    }

    public function testNestedList(): void
    {
        $html = $this->render("- een\n  - genest\n- twee");

        $this->assertContains('<ul>', $html);
        $this->assertSame(2, substr_count($html, '<ul>'));
        $this->assertContains('genest', $html);
    }

    public function testTaskList(): void
    {
        $html = $this->render("- [x] gedaan\n- [ ] nog niet");

        $this->assertContains('mtl-task-list', $html);
        $this->assertContains('checked', $html);
        $this->assertSame(2, substr_count($html, 'type="checkbox"'));
    }

    public function testTaskItemAnywhereInTheListIsRecognised(): void
    {
        $html = $this->render("- gewoon\n- [x] gedaan");

        $this->assertContains('type="checkbox"', $html);
    }

    public function testTable(): void
    {
        $html = $this->render("| a | b |\n|---|--:|\n| 1 | 2 |");

        $this->assertContains('<table>', $html);
        $this->assertContains('<th>a</th>', $html);
        $this->assertContains('text-align: right', $html);
        $this->assertContains('data-label="a"', $html);
    }

    public function testPipeTableWithoutDelimiterRowIsAParagraph(): void
    {
        $html = $this->render("| a | b |\nnot a delimiter");

        $this->assertNotContains('<table>', $html);
    }

    public function testTableRowWithMissingCells(): void
    {
        $html = $this->render("| a | b | c |\n|---|---|---|\n| 1 |");

        $this->assertContains('<table>', $html);
        // Three header cells means three body cells, padded with empties.
        $this->assertSame(3, substr_count($html, '<td'));
    }

    public function testHardBreakFromTwoSpaces(): void
    {
        $this->assertContains('<br>', $this->render("regel een  \nregel twee"));
    }

    public function testHardBreakFromBackslash(): void
    {
        $this->assertContains('<br>', $this->render("regel een\\\nregel twee"));
    }

    public function testBackslashEscapes(): void
    {
        $this->assertHtmlSame('<p>*niet cursief*</p>', $this->render('\*niet cursief\*'));
    }

    public function testFootnotes(): void
    {
        $html = $this->render("Tekst[^a].\n\n[^a]: De noot.");

        $this->assertContains('mtl-footnote-ref', $html);
        $this->assertContains('id="fn-a"', $html);
        $this->assertContains('De noot.', $html);
    }

    public function testUnusedFootnoteDefinitionIsNotRendered(): void
    {
        $html = $this->render("Gewone tekst.\n\n[^a]: Nooit verwezen.");

        $this->assertNotContains('mtl-footnotes', $html);
    }

    // -------------------------------------------------------------------------
    // Whole documents
    // -------------------------------------------------------------------------

    public function testEmptyInputProducesNothing(): void
    {
        $this->assertSame('', $this->render(''));
        $this->assertSame('', $this->render("   \n\n  "));
    }

    public function testContextReportsHeadings(): void
    {
        $result = Markdown::renderWithContext("# Een\n\n## Twee\n\nTekst.");

        $this->assertCount(2, $result['headings']);
        $this->assertSame('Een', $result['headings'][0]['text']);
        $this->assertSame(2, $result['headings'][0]['level']);
    }

    public function testContextReportsPlainText(): void
    {
        $result = Markdown::renderWithContext('Dit is **vet** en `code`.');

        $this->assertSame('Dit is vet en code.', $result['text']);
    }

    public function testOversizedDocumentIsRejected(): void
    {
        $this->assertThrows(
            static fn () => Markdown::render(str_repeat('a', 2_000_001)),
            \InvalidArgumentException::class
        );
    }

    public function testCrlfLineEndingsAreHandled(): void
    {
        // Windows line endings arrive from a pasted document often enough that
        // a renderer which only splits on \n produces stray carriage returns.
        $html = $this->render("# Titel\r\n\r\nTekst.");

        $this->assertContains('<h1>Titel</h1>', $html);
        $this->assertNotContains("\r", $html);
    }

    public function testTabsCountAsFourColumnStops(): void
    {
        $this->assertContains('<pre><code>code</code></pre>', $this->render("\tcode"));
    }

    public function testUnicodeSurvivesIntact(): void
    {
        $html = $this->render('Þingvellir, Ísland — 🌋');

        $this->assertContains('Þingvellir', $html);
        $this->assertContains('🌋', $html);
    }
}
