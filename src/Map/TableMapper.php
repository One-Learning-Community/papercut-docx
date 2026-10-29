<?php

declare(strict_types=1);

namespace Papercut\Docx\Map;

use Dskripchenko\PhpDocx\Element\{BlockElement, Table, TableCell as DocxCell, TableRow};
use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Style\{CellStyle, TableStyle, VerticalAlign};
use Papercut\Layout\GridCell;
use Papercut\Docx\Walk\Frame;
use Papercut\Export\Fidelity;
use Papercut\Style\{ComputedStyle, Justify};
use Papercut\TableCell;
use Papercut\Value\{Auto, Fr, Length, Percent};

/** Papercut tables as Word tables at WidthResolver's column widths. */
final class TableMapper
{
    public function __construct(
        private readonly Fidelity $fidelity,
        private readonly BoxMapper $boxes,
    ) {
    }

    /**
     * Content-sized (auto) columns borrow up to 15% + 4pt from the flexible (fr) columns, at most a fifth of
     * them in all, so Word's text metrics don't wrap a label the PDF fits exactly. Other columns keep their width.
     *
     * @param list<float> $columns WidthResolver's widths in pt
     * @param list<Length|Percent|Fr|Auto> $tracks
     * @return list<float>
     */
    public static function settle(array $columns, array $tracks): array
    {
        $flex = [];
        $auto = [];
        foreach ($columns as $i => $w) {
            if (($tracks[$i] ?? null) instanceof Fr) {
                $flex[] = $i;
            } elseif (($tracks[$i] ?? null) instanceof Auto) {
                $auto[] = $i;
            }
        }
        $pool = array_sum(array_map(static fn (int $i): float => $columns[$i], $flex));
        $budget = $pool * 0.2;
        if ($flex === [] || $auto === [] || $budget <= 0.0) {
            return $columns;
        }
        $taken = 0.0;
        foreach ($auto as $i) {
            $take = min($columns[$i] * 0.15 + 4.0, $budget - $taken);
            $columns[$i] += $take;
            $taken += $take;
        }
        foreach ($flex as $i) {
            $columns[$i] -= $taken * $columns[$i] / $pool;
        }

        return array_values($columns);
    }

    public function table(ComputedStyle $s, Frame $frame): Table
    {
        $style = BoxMapper::tableStyle($frame->width);
        $borders = BoxMapper::borders($s);
        if ($borders !== null) {
            $style = new TableStyle(...[...get_object_vars($style), 'borders' => $borders]);
        }

        return new Table($frame->rows, $style, gridColumnsTwips: array_map(static fn (float $w): int => max(1, Units::tw($w)), $frame->columns));
    }

    /** The grid cell the row's next own cell lands on, if the table's grid knows it. */
    public static function placement(Frame $table, Frame $row): ?GridCell
    {
        return $table->grid?->placements[$row->rowIndex][$row->placed] ?? null;
    }

    public function row(Frame $frame, ?Frame $table = null): TableRow
    {
        if ($table?->grid !== null) {
            // Up to the last column a rowspan from above covers, padding the uncovered gaps before it; short of that, no cells.
            $reach = 0;
            for ($r = 0; $r < $frame->rowIndex; $r++) {
                foreach ($table->grid->placements[$r] ?? [] as $p) {
                    if ($p->row + $p->rowspan > $frame->rowIndex) {
                        $reach = max($reach, $p->col + $p->colspan);
                    }
                }
            }
            $this->continueTo($table, $frame, $reach, true);
        }

        return new TableRow($frame->cells, isHeader: $frame->header, cantSplit: true);
    }

    /**
     * Fills the row from its next column up to $until with the cells a rowspan from above covers. A gap
     * nothing covers gets an empty cell when $pad (a later cell must land on its own column), else none.
     */
    private function continueTo(Frame $table, Frame $row, int $until, bool $pad): void
    {
        $grid = $table->grid;
        if ($grid === null) {
            return;
        }
        while ($row->column < $until) {
            $over = null;
            for ($r = $row->rowIndex - 1; $r >= 0 && $over === null; $r--) {
                foreach ($grid->placements[$r] ?? [] as $p) {
                    if ($p->col === $row->column && $p->row + $p->rowspan > $row->rowIndex) {
                        $over = $p;
                        break;
                    }
                }
            }
            if ($over === null) {
                if (!$pad) {
                    return;
                }
                $row->cells[] = new DocxCell([new Paragraph([])], new CellStyle(widthTwips: Units::tw($table->columns[$row->column] ?? 0.0)));
                $row->column++;
                continue;
            }
            $width = array_sum(array_slice($table->columns, $over->col, $over->colspan));
            $style = $table->spanStyles[$over->row . ':' . $over->col] ?? new CellStyle(widthTwips: Units::tw($width), gridSpan: $over->colspan);
            $row->cells[] = new DocxCell([new Paragraph([])], new CellStyle(...[...get_object_vars($style), 'rowSpan' => 1, 'vMergeContinue' => true]));
            $row->column += $over->colspan;
        }
    }

    /** @param list<BlockElement> $blocks */
    public function cell(TableCell $n, ComputedStyle $s, Frame $cell, Frame $row, ?Frame $table, array $blocks, string $path): DocxCell
    {
        $at = $table === null ? null : self::placement($table, $row);
        if ($table !== null && $at !== null) {
            $this->continueTo($table, $row, $at->col, true);
        }
        $row->column = ($at->col ?? $row->column) + ($at->colspan ?? $n->span());
        $row->placed++;
        $v = match ($s->justify) {
            Justify::Center => VerticalAlign::Center,
            Justify::End => VerticalAlign::Bottom,
            Justify::Start => VerticalAlign::Top,
            default => null,
        };
        if ($v === null) {
            $this->fidelity->approximated('cell justify', $path);
        }

        $style = $this->boxes->cellStyle($s, Units::tw($cell->width), $at->colspan ?? $n->span(), $v ?? VerticalAlign::Top, $row->style, rowSpan: $at->rowspan ?? 1);
        if ($table !== null && $at !== null && $at->rowspan > 1) {
            $table->spanStyles[$at->row . ':' . $at->col] = $style;
        }

        return new DocxCell(BoxMapper::cellContent($blocks), $style);
    }
}
