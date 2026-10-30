<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests\Walk;

use Papercut\{Column, Image, PageBreak, Row, Spacer, Text};
use Papercut\Style\Align;
use Papercut\Docx\Tests\Support\Styles;
use Papercut\Value\Edges;
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

final class FlowTest extends TestCase
{
    use Styles;

    private const string P = '/w:document/w:body/w:p';

    public function test_column_gap_becomes_space_after(): void
    {
        $d = self::export(Column::make(Text::make('a'), Text::make('b'))->gap(pt(8)));
        self::assertSame('160', $d->value(self::P . '[1]/w:pPr/w:spacing/@w:after'));
        self::assertNull($d->value(self::P . '[2]/w:pPr/w:spacing/@w:after'));
    }

    public function test_child_margin_top_becomes_space_before(): void
    {
        $d = self::export(Column::make(Text::make('a')->margin(Edges::of(pt(6), pt(0), pt(0), pt(0)))));
        self::assertSame('120', $d->value(self::P . '[1]/w:pPr/w:spacing/@w:before'));
    }

    public function test_pagination_flags(): void
    {
        $d = self::export(Column::make(
            Text::make('next')->keepWithNext(),
            Column::make(Text::make('t1'), Text::make('t2'))->keepTogether(),
            Text::make('before')->breakBefore(),
            Text::make('after')->breakAfter(),
            Text::make('end'),
        ));
        $p = static fn (string $text): string => self::P . "[.//w:t='$text']";
        self::assertSame(1, $d->count($p('next') . '/w:pPr/w:keepNext'));
        self::assertSame(1, $d->count($p('t1') . '/w:pPr/w:keepLines'));
        self::assertSame(1, $d->count($p('t2') . '/w:pPr/w:keepLines'));
        self::assertSame(1, $d->count($p('t1') . '/w:pPr/w:keepNext'));
        self::assertSame(0, $d->count($p('t2') . '/w:pPr/w:keepNext'));
        self::assertSame(1, $d->count($p('before') . '/w:pPr/w:pageBreakBefore'));
        self::assertSame(1, $d->count($p('after') . "/following-sibling::w:p[1]//w:br[@w:type='page']"));
    }

    public function test_keep_together_table_keeps_each_row_with_the_next(): void
    {
        $d = self::export(\Papercut\Table::make()->columns('1fr 1fr')->head('H1', 'H2')->row('a1', 'a2')->row('b1', 'b2')->keepTogether());
        $row = static fn (int $i): string => "/w:document/w:body/w:tbl/w:tr[$i]";
        self::assertCount(3, $d->values('/w:document/w:body/w:tbl/w:tr'));
        self::assertCount(2, $d->values($row(1) . '//w:p/w:pPr/w:keepNext'));
        self::assertCount(2, $d->values($row(2) . '//w:p/w:pPr/w:keepNext'));
        self::assertSame([], $d->values($row(3) . '//w:p/w:pPr/w:keepNext'));
    }

    public function test_without_keep_together_table_rows_are_not_kept_together(): void
    {
        $d = self::export(\Papercut\Table::make()->columns('1fr 1fr')->row('a1', 'a2')->row('b1', 'b2'));
        self::assertSame([], $d->values('//w:keepNext'));
    }

    public function test_keep_together_column_keeps_a_table_with_what_follows_inside_it(): void
    {
        $d = self::export(Column::make(
            Column::make(\Papercut\Table::make()->columns('1fr')->row('a')->row('b'), Text::make('after'))->keepTogether(),
            Text::make('outside'),
        ));
        self::assertCount(2, $d->values('/w:document/w:body/w:tbl/w:tr//w:p/w:pPr/w:keepNext'));
        self::assertSame(0, $d->count(self::P . "[.//w:t='after']/w:pPr/w:keepNext"));
    }

    public function test_page_break_node(): void
    {
        $d = self::export(Column::make(Text::make('a'), PageBreak::make(), Text::make('b')));
        self::assertSame(1, $d->count("//w:br[@w:type='page']"));
    }

    public function test_fixed_spacer_is_a_hairline_paragraph(): void
    {
        $d = self::export(Column::make(Text::make('a'), Spacer::size(pt(12)), Text::make('b')));
        $hair = self::P . '[2]';
        self::assertSame('238', $d->value("$hair/w:pPr/w:spacing/@w:after"));
        self::assertSame('2', $d->value("$hair/w:pPr/w:spacing/@w:line"));
        self::assertSame('exact', $d->value("$hair/w:pPr/w:spacing/@w:lineRule"));
        self::assertSame(1, $d->count("$hair/w:r"));
        self::assertSame('2', $d->value("$hair/w:r/w:rPr/w:sz/@w:val"));
    }

    public function test_flexible_spacer_in_a_column_is_approximated(): void
    {
        $d = self::export(Column::make(Text::make('a'), Spacer::flex(), Text::make('b')));
        self::assertSame(2, $d->count(self::P . '[.//w:t]'));
        self::assertSame(['Approximated flexible spacer @ page > body > column[0] > spacer[1]'], self::exportedReports());
    }

    public function test_break_before_on_a_box_or_row_still_breaks(): void
    {
        foreach ([
            Column::make(Text::make('card'))->padding(pt(8))->breakBefore(),
            Row::make(Text::make('a')->grow(1), Text::make('b'))->breakBefore(),
        ] as $second) {
            $d = self::export(Column::make(Text::make('first'), $second));
            self::assertSame(1, $d->count('//w:pPr/w:pageBreakBefore') + $d->count("//w:br[@w:type='page']"));
            self::assertSame(1, $d->count("/w:document/w:body/w:tbl/preceding-sibling::w:p[w:pPr/w:pageBreakBefore]"));
        }
    }

    public function test_break_before_on_an_inline_row_breaks_before_its_paragraph(): void
    {
        $d = self::export(Column::make(Text::make('first'), Row::make(Text::make('a'), Text::make('b'))->breakBefore()));
        self::assertSame(0, $d->count('//w:tbl'));
        self::assertSame(1, $d->count('//w:p[w:pPr/w:pageBreakBefore][.//w:t="a"]'));
    }

    public function test_keep_with_next_on_a_box_keeps_its_last_row_with_what_follows(): void
    {
        $d = self::export(Column::make(Column::make(Text::make('boxed'))->padding(pt(4))->keepWithNext(), Text::make('next')));
        self::assertSame(1, $d->count("/w:document/w:body/w:tbl/w:tr[last()]//w:p[w:pPr/w:keepNext]"));
    }

    public function test_column_alignment_reaches_text_and_images(): void
    {
        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true);
        $d = self::export(Column::make(Image::bytes($png), Text::make('caption'), Text::make('own')->right())->align(Align::Center));
        self::assertSame('center', $d->value('//w:p[.//w:drawing]/w:pPr/w:jc/@w:val'));
        self::assertSame('center', $d->value("//w:p[.//w:t='caption']/w:pPr/w:jc/@w:val"));
        // a text's own alignment wins
        self::assertSame('right', $d->value("//w:p[.//w:t='own']/w:pPr/w:jc/@w:val"));

        $d = self::export(Column::make(Text::make('end')->alignSelf(Align::End)));
        self::assertSame('right', $d->value("//w:p[.//w:t='end']/w:pPr/w:jc/@w:val"));
    }
}
