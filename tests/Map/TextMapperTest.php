<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests\Map;

use Dskripchenko\PhpDocx\Element\{Field, Hyperlink, LineBreak, Paragraph, Run};
use Dskripchenko\PhpDocx\Style\Alignment;
use Papercut\{Document, Page, PageNumber, Span, Text, View};
use Papercut\Docx\DocxExporter;
use Papercut\Docx\Map\{LineMetrics, TextMapper};
use Papercut\Docx\Tests\Support\{DocxInspector, Styles};
use Papercut\Export\Fidelity;
use Papercut\Exception\InvalidValue;
use Papercut\Style\Style;
use Papercut\Text\FontSet;
use Papercut\Value\Color;
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

final class TextMapperTest extends TestCase
{
    use Styles;

    private Fidelity $fidelity;

    private function mapper(): TextMapper
    {
        $this->fidelity = self::fidelity();

        return new TextMapper(new LineMetrics(new FontSet()), $this->fidelity);
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
        self::assertInstanceOf(LineBreak::class, $p->children[1]);
        $marked = $p->children[2];
        self::assertInstanceOf(Run::class, $marked);
        self::assertSame('ffff00', $marked->style->backgroundColor);
        // php-docx writes no run shading, only Word's named highlights
        self::assertSame('yellow', $marked->style->highlight);
        self::assertSame(['Approximated span background @ page > body > text[0]'], self::reports($this->fidelity));
    }

    public function test_highlight_follows_the_background_hue(): void
    {
        $hl = static fn (string $c): ?string => TextMapper::highlight(Color::parse($c));
        self::assertSame('yellow', $hl('#fef08a'));
        self::assertSame('green', $hl('#bbf7d0'));
        self::assertSame('cyan', $hl('#bfdbfe'));
        self::assertSame('magenta', $hl('#fecaca'));
        self::assertSame('lightGray', $hl('#e2e8f0'));
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
        self::assertNull($glyph(View::make()->width(pt(40))->height(pt(40))->border(pt(1), '#000000')));
        self::assertNull($glyph(View::make()->width(pt(8))->height(pt(8))));
        // A colour swatch is a picture of its fill, not a marker: it keeps its box.
        self::assertNull($glyph($box()->background('#2563eb')));
        self::assertSame('☐', $glyph($box()->background('#ffffff')));
        self::assertNull($glyph($box(Text::make('x'))));
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
}
