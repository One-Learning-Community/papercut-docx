<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests\Map;

use Papercut\{Column, Page, Table, TableCell, TableRow, Text};
use Papercut\Docx\Tests\Support\Styles;
use Papercut\Export\ExportOptions;
use Papercut\Layout\WidthResolver;
use Papercut\Style\{Justify, Style};
use Papercut\Value\{Auto, Fr};
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

final class TableMapperTest extends TestCase
{
    use Styles;

    private const string TBL = '/w:document/w:body/w:tbl';

    private static function invoiceTable(): Table
    {
        return Table::make()->columns('1fr 20mm 25mm 25mm')
            ->head('Item', 'Qty', 'Price', 'Total')
            ->row('Widget', '2', '$10', '$20')
            ->row('Gadget', '1', '$5', '$5');
    }

    public function test_grid_matches_width_resolver_columns(): void
    {
        $table = self::invoiceTable();
        $d = self::export($table);
        $page = Page::a4();
        $width = $page->width() - $page->margins()->left->points - $page->margins()->right->points;
        $cols = WidthResolver::for(new ExportOptions())->tableColumns($table, self::styleOf($table), $width);
        $grid = array_map('intval', $d->values(self::TBL . '/w:tblGrid/w:gridCol/@w:w'));
        self::assertCount(4, $grid);
        foreach ($cols as $i => $c) {
            self::assertEqualsWithDelta($c * 20, $grid[$i], 1.0);
        }
    }

    public function test_filled_table_grid_sums_to_the_region_width(): void
    {
        $table = Table::make()->columns('auto auto')->fillWidth()->row('aa', 'aaaa');
        $d = self::export($table);
        $page = Page::a4();
        $width = $page->width() - $page->margins()->left->points - $page->margins()->right->points;
        $grid = array_map('intval', $d->values(self::TBL . '/w:tblGrid/w:gridCol/@w:w'));
        self::assertCount(2, $grid);
        self::assertEqualsWithDelta($width * 20, array_sum($grid), 3.0);
    }

    public function test_fitted_length_columns_sum_to_the_region_width(): void
    {
        $table = Table::make()->columns('400pt 400pt')->fitColumns()->row('a', 'b');
        $d = self::export($table);
        $page = Page::a4();
        $width = $page->width() - $page->margins()->left->points - $page->margins()->right->points;
        $grid = array_map('intval', $d->values(self::TBL . '/w:tblGrid/w:gridCol/@w:w'));
        self::assertCount(2, $grid);
        self::assertEqualsWithDelta($width * 20, array_sum($grid), 3.0);

        $plain = array_map('intval', self::export(Table::make()->columns('400pt 400pt')->row('a', 'b'))->values(self::TBL . '/w:tblGrid/w:gridCol/@w:w'));
        self::assertSame([8000, 8000], $plain);
    }

    public function test_auto_columns_borrow_slack_from_a_flexible_column(): void
    {
        $table = Table::make()->columns(Auto::instance(), Fr::of(1))->row('Respondent', 'Jane Smith');
        $d = self::export($table);
        $page = Page::a4();
        $width = $page->width() - $page->margins()->left->points - $page->margins()->right->points;
        $cols = WidthResolver::for(new ExportOptions())->tableColumns($table, self::styleOf($table), $width);
        $grid = array_map('intval', $d->values(self::TBL . '/w:tblGrid/w:gridCol/@w:w'));
        self::assertEqualsWithDelta(($cols[0] * 1.15 + 4) * 20, $grid[0], 1.0);
        self::assertEqualsWithDelta(array_sum($cols) * 20, array_sum($grid), 2.0);
    }

    public function test_header_rows_repeat_and_rows_do_not_split(): void
    {
        $d = self::export(self::invoiceTable());
        self::assertSame(1, $d->count(self::TBL . '/w:tr[w:trPr/w:tblHeader]'));
        self::assertSame(1, $d->count(self::TBL . '/w:tr[1][w:trPr/w:tblHeader]'));
        self::assertSame(3, $d->count(self::TBL . '/w:tr[w:trPr/w:cantSplit]'));
    }

    public function test_colspan(): void
    {
        $table = Table::make()->columns('100pt 100pt 100pt')
            ->row(TableCell::make('wide')->colspan(2), 'c')
            ->row('a', 'b', 'c');
        $d = self::export($table);
        self::assertSame('2', $d->value(self::TBL . '/w:tr[1]/w:tc[1]/w:tcPr/w:gridSpan/@w:val'));
        self::assertSame('4000', $d->value(self::TBL . '/w:tr[1]/w:tc[1]/w:tcPr/w:tcW/@w:w'));
        self::assertSame('2000', $d->value(self::TBL . '/w:tr[1]/w:tc[2]/w:tcPr/w:tcW/@w:w'));
    }

    public function test_row_fill_and_border_reach_every_cell(): void
    {
        $table = Table::make()->columns('1fr 1fr')
            ->bodyRow(TableRow::make('a', 'b')->background('slate-50')->border(pt(1), 'slate-200'));
        $d = self::export($table);
        self::assertSame(['f8fafc', 'f8fafc'], $d->values(self::TBL . '/w:tr/w:tc/w:tcPr/w:shd/@w:fill'));
        self::assertSame(['8', '8'], $d->values(self::TBL . '/w:tr/w:tc/w:tcPr/w:tcBorders/w:bottom/@w:sz'));
    }

    public function test_cell_justify(): void
    {
        $table = Table::make()->columns('1fr 1fr')
            ->row(TableCell::make('a')->apply(Style::make(justify: Justify::Center)), TableCell::make('b')->apply(Style::make(justify: Justify::SpaceBetween)));
        $d = self::export($table);
        self::assertSame('center', $d->value(self::TBL . '/w:tr/w:tc[1]/w:tcPr/w:vAlign/@w:val'));
        self::assertSame(['Approximated cell justify @ page > body > table[0] > tr[0] > td[1]'], self::exportedReports());
    }

    public function test_a_cell_ending_in_a_table_ends_with_a_hairline(): void
    {
        $table = Table::make()->columns('1fr')->row(TableCell::make(Column::make(Text::make('boxed'))->padding(pt(4))));
        $d = self::export($table);
        $last = self::TBL . '/w:tr/w:tc/*[last()]';
        self::assertSame('p', $d->xpath()->evaluate("local-name($last)"));
        self::assertSame('exact', $d->value("$last/w:pPr/w:spacing/@w:lineRule"));
    }
}
