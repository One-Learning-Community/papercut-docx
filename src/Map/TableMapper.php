<?php

declare(strict_types=1);

namespace Papercut\Docx\Map;

use Dskripchenko\PhpDocx\Element\{BlockElement, Table, TableCell as DocxCell, TableRow};
use Dskripchenko\PhpDocx\Style\{TableStyle, VerticalAlign};
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

    public function row(Frame $frame): TableRow
    {
        return new TableRow($frame->cells, isHeader: $frame->header, cantSplit: true);
    }

    /** @param list<BlockElement> $blocks */
    public function cell(TableCell $n, ComputedStyle $s, Frame $cell, Frame $row, array $blocks, string $path): DocxCell
    {
        $row->column += $n->span();
        $v = match ($s->justify) {
            Justify::Center => VerticalAlign::Center,
            Justify::End => VerticalAlign::Bottom,
            Justify::Start => VerticalAlign::Top,
            default => null,
        };
        if ($v === null) {
            $this->fidelity->approximated('cell justify', $path);
        }

        return new DocxCell(BoxMapper::cellContent($blocks), $this->boxes->cellStyle($s, Units::tw($cell->width), $n->span(), $v ?? VerticalAlign::Top, $row->style));
    }
}
