<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests\Walk;

use Papercut\Decorator\Badge;
use Papercut\Docx\Tests\Support\Styles;
use Papercut\Style\{Justify, Style};
use Papercut\{Image, Link, ListItem, ListNode, Row, Span, Text, View};
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

final class InlineRowTest extends TestCase
{
    use Styles;

    private const string NBSP = "\u{00A0}";

    public function test_link_and_badge_in_a_list_item_are_one_paragraph_with_the_bullet(): void
    {
        $d = self::export(ListNode::unordered(ListItem::make(Row::make(Link::to('https://example.com', 'Example site'), Badge::make('Website')))));
        self::assertSame(0, $d->count('//w:tbl'));
        self::assertSame(1, $d->count('/w:document/w:body/w:p'));
        $text = $d->bodyText();
        self::assertStringContainsString('Example site', $text);
        self::assertStringContainsString('Website', $text);
        self::assertSame(1, $d->count('/w:document/w:body/w:p/w:pPr/w:numPr'));
        self::assertSame(1, $d->count('//w:p/w:hyperlink'));
        // the badge fill (slate-100) shades its text and the no-break space on each side, and nothing else
        self::assertSame(3, $d->count('//w:r[w:rPr/w:shd[@w:fill="f1f5f9"]]'));
        self::assertSame(1, $d->count('//w:r[w:rPr/w:shd][w:t="Website"]'));
        self::assertSame(2, $d->count('//w:r[w:rPr/w:shd][w:t="' . self::NBSP . '"]'));
        self::assertContains('approximated badge in a line', array_map(static fn ($r): string => strtolower($r->level->name) . ' ' . $r->feature, self::$exported));
    }

    public function test_the_same_row_at_top_level_is_one_paragraph(): void
    {
        $d = self::export(Row::make(Link::to('https://example.com', 'Example site'), Badge::make('Website')));
        self::assertSame(0, $d->count('//w:tbl'));
        self::assertSame(1, $d->count('/w:document/w:body/w:p'));
        self::assertSame(1, $d->count('//w:hyperlink'));
    }

    public function test_a_label_beside_a_long_value_stays_a_table_where_the_pdf_wraps_the_value(): void
    {
        $long = str_repeat('A value long enough to need several lines when it sits in its own column. ', 6);
        $d = self::export(Row::make(Text::make('Name:')->bold(), Text::make($long))->gap(pt(12)));
        self::assertSame(1, $d->count('//w:tbl'));
        self::assertSame(3, $d->count('//w:tc')); // label, gap, value
    }

    public function test_items_that_fit_on_one_line_with_their_gaps_stay_one_paragraph(): void
    {
        $d = self::export(Row::make(Text::make('Name:')->bold(), Text::make('short value'))->gap(pt(12)));
        self::assertSame(0, $d->count('//w:tbl'));
        self::assertSame(1, $d->count('/w:document/w:body/w:p'));
    }

    public function test_gap_is_a_space_between_items(): void
    {
        $d = self::export(Row::make('One', 'Two')->gap(pt(6)));
        self::assertSame(0, $d->count('//w:tbl'));
        self::assertStringContainsString('One Two', $d->bodyText());
    }

    public function test_text_runs_keep_their_formatting(): void
    {
        $d = self::export(Row::make(Text::make('Plain ')->append(Span::text('bold', Style::make(fontWeight: 700))), 'tail'));
        self::assertSame(0, $d->count('//w:tbl'));
        self::assertSame(1, $d->count('//w:r[w:rPr/w:b][w:t="bold"]'));
    }

    public function test_a_text_with_a_link_span_keeps_its_hyperlink(): void
    {
        $d = self::export(Row::make(Text::make('See ')->append(new Span('docs', href: 'https://example.com/docs')), 'tail'));
        self::assertSame(0, $d->count('//w:tbl'));
        self::assertSame(1, $d->count('//w:hyperlink[w:r/w:t="docs"]'));
    }

    /** @return iterable<string, array{\Papercut\View}> */
    public static function rowsThatStayTables(): iterable
    {
        yield 'a growing item' => [Row::make(Text::make('a')->grow(1), 'b')];
        yield 'space between' => [Row::make('a', 'b')->justify(Justify::SpaceBetween)];
        yield 'an image' => [Row::make('a', Image::bytes(self::png())->width(pt(20)))];
        yield 'a fixed width item' => [Row::make(Text::make('a')->width(pt(100)), 'b')];
        yield 'a basis' => [Row::make(Text::make('Label')->basis(pt(150)), 'value')];
        yield 'a min width' => [Row::make(Text::make('Label')->minWidth(pt(150)), 'value')];
        yield 'a max width' => [Row::make(Text::make('Label')->maxWidth(pt(50)), 'value')];
        yield 'a bordered item' => [Row::make(View::make('a')->apply(Style::make(borderWidth: \Papercut\Value\Edges::of(pt(1)))), 'b')];
        yield 'a two paragraph item' => [Row::make(View::make('a', 'b'), 'c')];
        yield 'a decorated row' => [Row::make('a', 'b')->apply(Style::make(padding: \Papercut\Value\Edges::of(pt(6))))];
        yield 'a wrapped row' => [Row::make(...array_map(static fn (int $i): string => str_repeat('word', 20) . $i, range(1, 4)))->wrap()];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rowsThatStayTables')]
    public function test_row_stays_a_table(View $row): void
    {
        self::assertSame(1, self::export($row)->count('/w:document/w:body/w:tbl'));
    }

    private static function png(): string
    {
        $im = imagecreatetruecolor(4, 2);
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }
}
