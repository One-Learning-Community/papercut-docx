<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests\Walk;

use Papercut\{Table, TableCell};

use function Papercut\pt;
use Papercut\Docx\Tests\Support\Styles;
use PHPUnit\Framework\TestCase;

final class RowspanTest extends TestCase
{
    use Styles;

    private const string ROW = '/w:document/w:body/w:tbl/w:tr';

    public function test_rowspan_writes_vmerge_restart_and_continue_cells(): void
    {
        $d = self::export(Table::make()->columns('1fr 1fr')->row(TableCell::make('a')->rowspan(2), 'b')->row('c'));
        self::assertSame(1, $d->count(self::ROW . '[1]/w:tc[1]/w:tcPr/w:vMerge[@w:val="restart"]'));
        self::assertSame(2, $d->count(self::ROW . '[2]/w:tc'));
        self::assertSame(1, $d->count(self::ROW . '[2]/w:tc[1]/w:tcPr/w:vMerge[not(@w:val)]'));
        self::assertSame($d->value(self::ROW . '[1]/w:tc[1]/w:tcPr/w:tcW/@w:w'), $d->value(self::ROW . '[2]/w:tc[1]/w:tcPr/w:tcW/@w:w'));
    }

    public function test_continuation_cells_in_middle_and_last_columns(): void
    {
        $d = self::export(Table::make()->columns('1fr 1fr 1fr')->row('a', TableCell::make('b')->rowspan(3), 'c')->row('d', 'e')->row('f', 'g'));
        foreach ([2, 3] as $r) {
            self::assertSame(3, $d->count(self::ROW . "[$r]/w:tc"));
            self::assertSame(1, $d->count(self::ROW . "[$r]/w:tc[2]/w:tcPr/w:vMerge[not(@w:val)]"));
        }
        $e = self::export(Table::make()->columns('1fr 1fr')->row('a', TableCell::make('b')->rowspan(2))->row('c'));
        self::assertSame(2, $e->count(self::ROW . '[2]/w:tc'));
        self::assertSame(1, $e->count(self::ROW . '[2]/w:tc[2]/w:tcPr/w:vMerge[not(@w:val)]'));
    }

    public function test_a_ragged_row_still_continues_a_rowspan_further_right(): void
    {
        $d = self::export(Table::make()->columns('1fr 1fr 1fr')->row('a', 'b', TableCell::make('c')->rowspan(2))->row('x'));
        self::assertSame(3, $d->count(self::ROW . '[2]/w:tc'));
        self::assertSame(0, $d->count(self::ROW . '[2]/w:tc[1]/w:tcPr/w:vMerge'));
        self::assertSame(0, $d->count(self::ROW . '[2]/w:tc[2]/w:tcPr/w:vMerge'));
        self::assertSame(1, $d->count(self::ROW . '[2]/w:tc[3]/w:tcPr/w:vMerge[not(@w:val)]'));
    }

    public function test_colspan_running_into_a_rowspan_is_clamped(): void
    {
        $d = self::export(Table::make()->columns('1fr 1fr 1fr')->row('a', 'b', TableCell::make('c')->rowspan(2))->row(TableCell::make('d')->colspan(3)));
        self::assertSame(2, $d->count(self::ROW . '[2]/w:tc'));
        self::assertSame('2', $d->value(self::ROW . '[2]/w:tc[1]/w:tcPr/w:gridSpan/@w:val'));
        self::assertSame(1, $d->count(self::ROW . '[2]/w:tc[2]/w:tcPr/w:vMerge[not(@w:val)]'));
    }

    public function test_continuation_keeps_the_gridspan(): void
    {
        $d = self::export(Table::make()->columns('1fr 1fr 1fr')->row(TableCell::make('a')->colspan(2)->rowspan(2), 'b')->row('c'));
        self::assertSame(2, $d->count(self::ROW . '[2]/w:tc'));
        self::assertSame('2', $d->value(self::ROW . '[2]/w:tc[1]/w:tcPr/w:gridSpan/@w:val'));
        self::assertSame(1, $d->count(self::ROW . '[2]/w:tc[1]/w:tcPr/w:vMerge[not(@w:val)]'));
    }

    public function test_cell_after_a_spanned_column_gets_its_grid_width(): void
    {
        $d = self::export(Table::make()->columns('20mm 40mm')->row(TableCell::make('a')->rowspan(2), 'b')->row('c'));
        $second = (int) $d->value(self::ROW . '[2]/w:tc[2]/w:tcPr/w:tcW/@w:w');
        self::assertEqualsWithDelta(40 * 56.693, $second, 40.0);
    }

    public function test_continuation_carries_the_spanners_borders_and_shading(): void
    {
        $a = TableCell::make('a')->rowspan(2)->border(pt(1), '#ff0000')->background('#00ff00')->padding(pt(3));
        $d = self::export(Table::make()->columns('1fr 1fr')->row($a, 'b')->row('c'));
        $tc = self::ROW . '[%d]/w:tc[1]/w:tcPr/';
        foreach (['w:tcBorders', 'w:shd'] as $part) {
            self::assertSame(1, $d->count(sprintf($tc, 2) . $part));
            $xml = static function (int $row) use ($d, $tc, $part): string {
                $found = $d->xpath()->query(sprintf($tc, $row) . $part);
                self::assertInstanceOf(\DOMNodeList::class, $found);
                $node = $found->item(0);
                self::assertInstanceOf(\DOMElement::class, $node);

                return (string) $node->C14N();
            };
            self::assertSame($xml(1), $xml(2));
        }
        self::assertSame($d->value(sprintf($tc, 1) . 'w:tcMar/w:left/@w:w'), $d->value(sprintf($tc, 2) . 'w:tcMar/w:left/@w:w'));
    }
}
