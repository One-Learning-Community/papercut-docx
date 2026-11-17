<?php

declare(strict_types=1);

namespace Quoin\Docx\Tests\Map;

use Dskripchenko\PhpDocx\Element\{Field, Hyperlink, Paragraph, Run};
use Dskripchenko\PhpDocx\Style\Alignment;
use Quoin\{Document, Page, PageNumber, Span, Text, View};
use Quoin\Docx\DocxExporter;
use Quoin\Docx\Map\{LineMetrics, TextMapper, WordFonts};
use Quoin\Docx\Tests\Support\{DocxInspector, Styles};
use Quoin\Export\Fidelity;
use Quoin\Exception\InvalidValue;
use Quoin\Style\Style;
use Quoin\Text\FontSet;
use Quoin\Value\{Color, Length};
use PHPUnit\Framework\TestCase;

use function Quoin\pt;

final class TextMapperTest extends TestCase
{
    use Styles;

    private Fidelity $fidelity;

    private function mapper(): TextMapper
    {
        $this->fidelity = self::fidelity();

        return new TextMapper(new LineMetrics(new FontSet()), $this->fidelity, new WordFonts(new FontSet()));
    }

    private function para(Text $t, ?string $blockHref = null): Paragraph
    {
        return $this->mapper()->paragraph($t, self::styleOf($t), self::visit(), $blockHref);
    }

    public function test_line_height_is_an_auto_multiple_of_the_natural_line(): void
    {
        $p = $this->para(Text::make('x')->size(pt(10))->lineHeight(1.4));
        self::assertSame(278, $p->style->lineSpacingTwips);
        self::assertSame('auto', $p->style->lineSpacingRule);
    }

    public function test_weights(): void
    {
        $semi = $this->para(Text::make('x')->weight(600));
        $run = $semi->children[0];
        self::assertInstanceOf(Run::class, $run);
        self::assertTrue($run->style->bold);
        self::assertSame(['Approximated font weight 600 @ page > body > text[0]'], self::reports($this->fidelity));

        $medium = $this->para(Text::make('x')->weight(500));
        $run = $medium->children[0];
        self::assertInstanceOf(Run::class, $run);
        self::assertFalse($run->style->bold);
        self::assertSame(['Approximated font weight 500 @ page > body > text[0]'], self::reports($this->fidelity));

        foreach ([400, 700] as $w) {
            $this->para(Text::make('x')->weight($w));
            self::assertSame([], self::reports($this->fidelity));
        }
    }

    public function test_black_weight_reports_like_extra_bold(): void
    {
        $this->para(Text::make('x')->weight(900));
        self::assertSame(['Approximated font weight 900 @ page > body > text[0]'], self::reports($this->fidelity));
    }

    public function test_right_alignment_is_written_as_end(): void
    {
        self::assertSame(Alignment::End, $this->para(Text::make('x')->right())->style->alignment);
    }

    public function test_heading_levels_from_presets(): void
    {
        self::assertSame(2, $this->para(Text::h2('x'))->headingLevel);
        self::assertNull($this->para(Text::p('x'))->headingLevel);
    }

    public function test_a_line_break_inside_a_link_stays_in_one_hyperlink(): void
    {
        $url = 'https://example.test';
        $t = Text::spans(new Span('one', href: $url), Span::break(), Span::break(), new Span('two', href: $url), Span::break(), new Span('after'));
        $p = $this->para($t);

        self::assertCount(3, $p->children);
        self::assertInstanceOf(Hyperlink::class, $p->children[0]);
        self::assertSame(['one', "\n", "\n", 'two'], array_map(static fn ($r): string => $r instanceof Run ? $r->text : '?', $p->children[0]->children));
        // a break between a link and plain text is not part of the link
        self::assertInstanceOf(Run::class, $p->children[1]);
        self::assertSame("\n", $p->children[1]->text);
        self::assertInstanceOf(Run::class, $p->children[2]);
    }

    public function test_a_line_break_between_two_different_links_joins_neither(): void
    {
        $p = $this->para(Text::spans(new Span('a', href: 'https://a.test'), Span::break(), new Span('b', href: 'https://b.test')));

        self::assertCount(3, $p->children);
        self::assertInstanceOf(Hyperlink::class, $p->children[0]);
        self::assertInstanceOf(Run::class, $p->children[1]);
        self::assertInstanceOf(Hyperlink::class, $p->children[2]);
    }

    public function test_spans_links_breaks_and_backgrounds(): void
    {
        $t = Text::spans(
            new Span('site', href: 'https://example.test'),
            Span::break(),
            new Span('marked', Style::make(background: Color::rgb(255, 255, 0))),
        );
        $p = $this->para($t);
        self::assertInstanceOf(Hyperlink::class, $p->children[0]);
        self::assertSame('https://example.test', $p->children[0]->href);
        self::assertInstanceOf(Run::class, $p->children[0]->children[0]);
        self::assertInstanceOf(Run::class, $p->children[1]);
        self::assertSame("\n", $p->children[1]->text);
        $marked = $p->children[2];
        self::assertInstanceOf(Run::class, $marked);
        self::assertSame([], self::reports($this->fidelity));
    }

    public function test_span_backgrounds_are_shaded_in_their_own_color(): void
    {
        $d = self::export(Text::spans(new Span('plain'), new Span('marked', Style::make(background: Color::hex('#fef08a')))));
        self::assertSame(['fef08a'], $d->values('//w:r/w:rPr/w:shd/@w:fill'));
        self::assertSame('clear', $d->value('//w:r/w:rPr/w:shd/@w:val'));
        self::assertSame(0, $d->count('//w:highlight'));
        self::assertSame([], self::exportedReports());
    }

    public function test_block_href_wraps_runs_without_their_own(): void
    {
        $t = Text::spans(new Span('a'), new Span('b', href: 'https://own.test'));
        $p = $this->para($t, 'https://x.test');
        self::assertInstanceOf(Hyperlink::class, $p->children[0]);
        self::assertSame('https://x.test', $p->children[0]->href);
        self::assertInstanceOf(Hyperlink::class, $p->children[1]);
        self::assertSame('https://own.test', $p->children[1]->href);
    }

    public function test_min_lines_turns_on_widow_control(): void
    {
        self::assertTrue($this->para(Text::make('x')->minLines(2))->style->widowControl);
    }

    public function test_page_number_fields(): void
    {
        $n = PageNumber::make('Page {page} of {total}');
        $p = $this->mapper()->pageNumber($n, self::styleOf($n));
        $kinds = array_map(static fn ($c): string => $c instanceof Field ? 'F:' . $c->instruction : ($c instanceof Run ? 'R:' . $c->text : '?'), $p->children);
        self::assertSame(['R:Page ', 'F:PAGE', 'R: of ', 'F:NUMPAGES'], $kinds);
        $first = $p->children[0];
        $field = $p->children[1];
        self::assertInstanceOf(Run::class, $first);
        self::assertInstanceOf(Field::class, $field);
        self::assertEquals($first->style, $field->style);
    }

    public function test_glyph_markers(): void
    {
        $box = static fn (Text|View ...$c): View => View::make(...$c)->width(pt(8))->height(pt(8))->border(pt(1), '#000000');
        $glyph = static fn (View $v): ?string => TextMapper::glyph($v, self::styleOf($v));

        self::assertSame('☐', $glyph($box()));
        self::assertSame('▣', $glyph($box(View::make())));
        self::assertSame('○', $glyph($box()->radius(pt(4))));
        self::assertSame('◉', $glyph($box(View::make())->radius(pt(4))));
        // Round once the corners take most of each side, as a radio enlarged without its radius reads round.
        $sized = static fn (float $radius): View => View::make()->width(pt(10))->height(pt(10))->border(pt(1), '#000000')->radius(pt($radius));
        self::assertSame('○', $glyph($sized(3.75)));
        self::assertSame('☐', $glyph($sized(1.25)));
        self::assertSame('☐', $glyph($sized(2.5)));
        // A third exactly is still a square: the built-in checkbox radius on a box shrunk to half (0.375em).
        self::assertSame('☐', $glyph(View::make()->width(pt(3.75))->height(pt(3.75))->border(pt(0.5), '#000000')->radius(pt(1.25))));
        self::assertNull($glyph(View::make()->width(pt(40))->height(pt(40))->border(pt(1), '#000000')));
        self::assertNull($glyph(View::make()->width(pt(8))->height(pt(8))));
        // A color swatch is a picture of its fill, not a marker: it keeps its box.
        self::assertNull($glyph($box()->background('#2563eb')));
        self::assertSame('☐', $glyph($box()->background('#ffffff')));
        self::assertNull($glyph($box(Text::make('x'))));
    }

    public function test_glyph_paragraph_centers_the_marker_on_the_label_capitals(): void
    {
        // A 1em marker at 10pt with a 1.4 line: the glyph is 12.5pt in Segoe UI Symbol (all four markers, one size).
        $marker = View::make()->width(Length::em(1))->height(Length::em(1))->border(pt(1), '#000000');
        $p = $this->mapper()->glyphParagraph('○', self::styleOf($marker));
        $run = $p->children[0];
        self::assertInstanceOf(Run::class, $run);
        self::assertSame('Segoe UI Symbol', $run->style->fontFamily);
        self::assertSame('Segoe UI Symbol', $run->style->fontFamilyEastAsia);
        self::assertSame(25, $run->style->sizeHalfPoints);
        // An exact line: Inter's cap center sits 14 - 2.41 - 3.64 = 7.95pt down the label's line, and the glyph's
        // center 0.48 of its size above the line's bottom, so the line is 7.95 + 6 = 13.95pt (279 twips).
        self::assertSame('exact', $p->style->lineSpacingRule);
        self::assertSame(279, $p->style->lineSpacingTwips);

        $small = View::make()->width(Length::em(0.75))->height(Length::em(0.75))->border(pt(1), '#000000');
        self::assertSame(250, $this->mapper()->glyphParagraph('○', self::styleOf($small))->style->lineSpacingTwips);
        // Too big to center on the capitals without its top cut off: it hangs from the top of the line instead.
        $big = View::make()->width(Length::em(2))->height(Length::em(2))->border(pt(1), '#000000');
        self::assertSame(425, $this->mapper()->glyphParagraph('○', self::styleOf($big))->style->lineSpacingTwips);
    }

    public function test_disallowed_span_link_throws(): void
    {
        $this->expectException(InvalidValue::class);
        $this->para(Text::make('x')->append(new Span('go', href: 'javascript:alert(1)')));
    }

    public function test_control_characters_keep_the_xml_well_formed(): void
    {
        $out = Document::make(Page::a4()->body(Text::make("a\x0Bb\u{FFFE}c")))->export(new DocxExporter());
        $dom = new \DOMDocument();
        self::assertTrue(@$dom->loadXML(DocxInspector::of($out->bytes())->part('word/document.xml')));
    }
    /** @return list<string> every run's text in order, links unwrapped */
    private static function runTexts(Paragraph $p): array
    {
        $texts = [];
        foreach ($p->children as $c) {
            foreach ($c instanceof Hyperlink ? $c->children : [$c] as $r) {
                $texts[] = $r instanceof Run ? $r->text : '?';
            }
        }

        return $texts;
    }

    public function test_whitespace_collapses_across_spans_as_in_the_pdf(): void
    {
        $p = $this->para(Text::markup("\n    plain\n    <b>bold</b>\n    end\n"));
        self::assertSame(['plain ', 'bold', ' end'], self::runTexts($p));
    }

    public function test_whitespace_spanning_several_spans_becomes_one_space(): void
    {
        $p = $this->para(Text::spans(new Span("a \n "), new Span("\t "), new Span(' b'), new Span("\n"), new Span('  c  ')));
        self::assertSame(['a ', 'b', ' ', 'c'], self::runTexts($p));
    }

    public function test_spaces_around_a_line_break_are_dropped(): void
    {
        $p = $this->para(Text::spans(new Span('one   '), Span::break(), new Span('   two')));
        self::assertSame(['one', "\n", 'two'], self::runTexts($p));
    }

    public function test_no_break_spaces_are_kept(): void
    {
        $p = $this->para(Text::spans(new Span("a\u{00A0}\u{00A0} b")));
        self::assertSame(["a\u{00A0}\u{00A0} b"], self::runTexts($p));
    }

    public function test_pre_wrap_spans_keep_their_spaces(): void
    {
        $p = $this->para(Text::spans(new Span('x  '), new Span('  a  b', Style::make(whiteSpace: \Quoin\Style\WhiteSpace::PreWrap)), new Span('  y')));
        self::assertSame(['x ', '  a  b', ' y'], self::runTexts($p));
    }

    public function test_indentation_between_a_badge_and_the_next_span_is_one_space(): void
    {
        $p = $this->para(Text::markup("<badge>New</badge>\n                \n                <span color=\"red-500\">tail</span>"));
        $texts = self::runTexts($p);
        self::assertSame(['tail'], array_slice($texts, -1));
        self::assertSame(' ', $texts[count($texts) - 2]);
        self::assertCount(3, $texts);
    }
}
