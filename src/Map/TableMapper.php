<?php

declare(strict_types=1);

namespace Papercut\Docx\Map;

use Dskripchenko\PhpDocx\Element\{BlockElement, Table, TableCell as DocxCell, TableRow};
use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Style\{Alignment, CellStyle, RowHeightRule, TableStyle, VerticalAlign};
use Papercut\Layout\GridCell;
use Papercut\Docx\Walk\Frame;
use Papercut\Export\Fidelity;
use Papercut\Style\{ComputedStyle, Justify};
use Papercut\TableCell;
use Papercut\Value\{Auto, Fr, Length, Percent};

/**
 * Papercut tables as Word tables at WidthResolver's column widths.
 *
 * @internal Part of Papercut's engine, not its public API. It may change in any release.
 */
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

    /** $align places a table that doesn't stretch across its column, as `align-self` does in the PDF. */
    public function table(ComputedStyle $s, Frame $frame, ?Alignment $align = null): Table
    {
        $style = BoxMapper::tableStyle($frame->width);
        if ($align !== null) {
            $style = new TableStyle(...[...get_object_vars($style), 'alignment' => $align]);
        }
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

        // An "at least" height, as in the PDF: a cell's height or min-height is a floor for its row. Word clips
        // what overflows an exact height, and its text never measures quite like ours.
        $floor = max($frame->floor, $table->rowFloors[$frame->rowIndex] ?? 0.0);
        $height = $floor > 0.0 ? Units::tw($floor) : null;

        return new TableRow($frame->cells, isHeader: $frame->header, heightTwips: $height, cantSplit: true, heightRule: RowHeightRule::AtLeast);
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

    /**
     * Raises the height floor of the cell's rows to its height or min-height (border box, as the PDF lays it
     * out). A cell spanning rows shares it out evenly, where the PDF stretches its rows to fit; both that and
     * a fixed height, which becomes a floor too, are reported.
     */
    private function floor(ComputedStyle $s, Frame $row, ?Frame $table, int $rowspan, string $path): void
    {
        if ($s->height instanceof Length) {
            $this->fidelity->approximated('fixed height', $path);
        }
        $h = max(
            $s->height instanceof Length ? $s->height->points : 0.0,
            $s->minHeight instanceof Length ? $s->minHeight->points : 0.0,
        );
        if ($h <= 0.0) {
            return;
        }
        if ($table === null) {
            $row->floor = max($row->floor, $h);
            return;
        }
        if ($rowspan > 1) {
            $this->fidelity->approximated('spanning cell height', $path);
        }
        for ($r = $row->rowIndex; $r < $row->rowIndex + $rowspan; $r++) {
            $table->rowFloors[$r] = max($table->rowFloors[$r] ?? 0.0, $h / $rowspan);
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
        $this->floor($s, $row, $table, $at->rowspan ?? 1, $path);

        $style = $this->boxes->cellStyle($s, Units::tw($cell->width), $at->colspan ?? $n->span(), $v ?? VerticalAlign::Top, $row->style, rowSpan: $at->rowspan ?? 1);
        if ($table !== null && $at !== null && $at->rowspan > 1) {
            $table->spanStyles[$at->row . ':' . $at->col] = $style;
        }

        return new DocxCell(BoxMapper::cellContent($blocks), $style);
    }
}
