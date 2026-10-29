<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests\Walk;

use Papercut\Docx\Tests\Support\Styles;
use Papercut\Markup\Markup;
use Papercut\{Row, Style\Style, View};

use function Papercut\pt;
use PHPUnit\Framework\TestCase;

final class RuleTest extends TestCase
{
    use Styles;

    public function test_hr_exports_a_bordered_paragraph_not_a_table(): void
    {
        $d = self::export(Markup::html('<p>a</p><hr><p>b</p>'));
        self::assertSame(0, $d->count('/w:document/w:body/w:tbl'));
        self::assertSame(1, $d->count('/w:document/w:body/w:p/w:pPr/w:pBdr/w:bottom'));
    }

    public function test_a_user_built_bordered_view_keeps_its_table(): void
    {
        $line = View::make()->apply(Style::make(borderWidth: \Papercut\Value\Edges::of(pt(0), pt(0), pt(1), pt(0)), width: pt(100), minHeight: pt(30)));
        $d = self::export($line);
        self::assertSame(1, $d->count('/w:document/w:body/w:tbl'));
        self::assertSame(0, $d->count('/w:document/w:body/w:p/w:pPr/w:pBdr'));
    }

    public function test_a_growing_signature_line_in_a_row_is_the_bottom_border_of_its_cell(): void
    {
        $line = View::make()->apply(Style::make(borderWidth: \Papercut\Value\Edges::of(pt(0), pt(0), pt(1), pt(0)), grow: 1));
        $d = self::export(Row::make('Signed:', $line));
        self::assertSame(0, $d->count('//w:pBdr'));
        self::assertSame(2, $d->count('/w:document/w:body/w:tbl/w:tr/w:tc'));
        self::assertSame(1, $d->count('//w:tc/w:tcPr/w:tcBorders/w:bottom[@w:val="single"]'));
        self::assertSame(1, $d->count('//w:tc[2]/w:tcPr/w:tcBorders/w:bottom[@w:val="single"]'));
        self::assertSame(0, $d->count('//w:tc[1]/w:tcPr/w:tcBorders'));
    }

    public function test_a_fixed_width_signature_line_in_a_row_is_the_bottom_border_of_its_cell(): void
    {
        $line = View::make()->apply(Style::make(borderWidth: \Papercut\Value\Edges::of(pt(0), pt(0), pt(1), pt(0)), width: pt(150)));
        $d = self::export(Row::make('Signed:', $line));
        self::assertSame(0, $d->count('//w:pBdr'));
        self::assertSame(1, $d->count('//w:tc/w:tcPr/w:tcBorders/w:bottom[@w:val="single"]'));
        self::assertSame(1, $d->count('//w:tc[w:tcPr/w:tcBorders/w:bottom][w:tcPr/w:tcW/@w:w="3000"]'));
    }

    public function test_a_bordered_view_with_text_in_a_row_keeps_its_bottom_border(): void
    {
        $name = View::make(\Papercut\Text::make('Name'))->apply(Style::make(borderWidth: \Papercut\Value\Edges::of(pt(0), pt(0), pt(1), pt(0)), grow: 1));
        $d = self::export(Row::make('Signed:', $name));
        self::assertSame(1, $d->count('//w:tc/w:tcPr/w:tcBorders/w:bottom[@w:val="single"]'));
        self::assertSame(1, $d->count('//w:tc[2]/w:tcPr/w:tcBorders/w:bottom[@w:val="single"]'));
        self::assertStringContainsString('Name', $d->bodyText());
    }

    public function test_the_label_beside_a_signature_line_keeps_its_word_slack(): void
    {
        $line = View::make()->apply(Style::make(borderWidth: \Papercut\Value\Edges::of(pt(0), pt(0), pt(1), pt(0)), grow: 1));
        $plain = View::make()->apply(Style::make(grow: 1));
        $withLine = self::export(Row::make('Signed:', $line))->value('//w:tc[1]/w:tcPr/w:tcW/@w:w');
        $spacer = self::export(Row::make('Signed:', $plain))->value('//w:tc[1]/w:tcPr/w:tcW/@w:w');
        self::assertSame($spacer, $withLine);
        self::assertGreaterThan(714, (int) $withLine);
    }

    public function test_a_padding_only_empty_view_in_a_row_stays_empty_space(): void
    {
        $pad = View::make()->apply(Style::make(padding: \Papercut\Value\Edges::all(pt(4)), grow: 1));
        $plain = View::make()->apply(Style::make(grow: 1));
        $a = self::export(Row::make('a', $pad, 'b'));
        $b = self::export(Row::make('a', $plain, 'b'));
        self::assertSame($b->values('//w:tc/w:tcPr/w:tcW/@w:w'), $a->values('//w:tc/w:tcPr/w:tcW/@w:w'));
        self::assertSame(0, $a->count('//w:tcMar'));
    }
}
