<?php

declare(strict_types=1);

namespace Papercut\Docx\Map;

use Dskripchenko\PhpDocx\Element\{BlockElement, Paragraph, Run, Table, TableCell, TableRow};
use Dskripchenko\PhpDocx\Style\{Alignment, Border, BorderSet, BorderStyle as DocxBorderStyle, CellStyle, ParagraphStyle, RowHeightRule, RunStyle, TableStyle, VerticalAlign};
use Papercut\Docx\Package\DocxPatcher;
use Papercut\Docx\Walk\Frame;
use Papercut\Export\Fidelity;
use Papercut\Style\{BorderStyle, ComputedStyle};
use Papercut\Value\{Auto, Edges, Length};

/** Boxes with padding, a border or a background, as single-cell tables. */
final class BoxMapper
{
    public function __construct(private readonly Fidelity $fidelity)
    {
    }

    public static function decorated(ComputedStyle $s): bool
    {
        return $s->padding->horizontal() + $s->padding->vertical() > 0.0
            || $s->borderWidth->horizontal() + $s->borderWidth->vertical() > 0.0
            || Units::fill($s->background) !== null;
    }

    /**
     * Whether an empty box is a rule: a bottom border and nothing else of a box. Word draws it as a paragraph border.
     * Anything else empty keeps its table.
     */
    public static function isRule(ComputedStyle $s): bool
    {
        return $s->borderWidth->bottom->points > 0.0
            && $s->borderWidth->top->points + $s->borderWidth->left->points + $s->borderWidth->right->points <= 0.0
            && $s->padding->horizontal() + $s->padding->vertical() <= 0.0
            && Units::fill($s->background) === null
            && $s->radius <= 0.0
            && !($s->height instanceof Length);
    }

    /** An empty paragraph, a hairline high, with the style's bottom border. */
    public static function rule(ComputedStyle $s): Paragraph
    {
        return new Paragraph(
            [new Run('', new RunStyle(sizeHalfPoints: 2))],
            new ParagraphStyle(lineSpacingTwips: 2, lineSpacingRule: 'exact', borders: self::borders($s)),
        );
    }

    /** The style without its box, for a node whose box is drawn some other way (a glyph marker). */
    public static function undecorated(ComputedStyle $s): ComputedStyle
    {
        return new ComputedStyle(...[...get_object_vars($s), 'padding' => Edges::zero(), 'borderWidth' => Edges::zero(), 'background' => null, 'radius' => 0.0, 'height' => Auto::instance()]);
    }

    /**
     * @param list<BlockElement> $blocks
     * @param ?float $minHeight a floor in pt from the content (a lone child's own min height), on top of the style's
     */
    public function box(array $blocks, ComputedStyle $s, float $width, string $path, Alignment $align = Alignment::Start, ?float $minHeight = null): Table
    {
        if ($s->radius > 0.0) {
            $this->fidelity->dropped('radius', $path);
        }
        $height = null;
        if ($s->height instanceof Length) {
            $height = Units::tw($s->height->points);
            $this->fidelity->approximated('fixed height', $path);
        } else {
            // LibreOffice adds the cell's vertical padding to an at-least row height.
            $floor = max($s->minHeight instanceof Length ? $s->minHeight->points : 0.0, $minHeight ?? 0.0) - $s->padding->vertical() - $s->borderWidth->top->points;
            $height = $floor > 0.0 ? Units::tw($floor) : null;
        }

        return new Table(
            [new TableRow(
                [new TableCell(self::cellContent($blocks), $this->cellStyle($s, Units::tw($width)))],
                heightTwips: $height,
                cantSplit: $s->keepTogether,
                heightRule: $s->height instanceof Length ? RowHeightRule::Exact : RowHeightRule::AtLeast,
            )],
            new TableStyle(...[...get_object_vars(self::tableStyle($width)), 'alignment' => $align]),
            gridColumnsTwips: [Units::tw($width)],
        );
    }

    /**
     * A bordered box with no padding or gap whose children are all boxes (a titled card) as one table with a row
     * per child: tables nested in table cells cost Google Docs an extra paragraph each, which pushes pages over.
     * Null when the frame isn't such a stack.
     */
    public function stack(Frame $frame, string $path): ?Table
    {
        $s = $frame->style;
        if ($s === null || !self::decorated($s) || $s->gap > 0.0 || $s->padding->horizontal() + $s->padding->vertical() > 0.0
            || $s->height instanceof Length || count($frame->items) < 2) {
            return null;
        }
        $rows = [];
        $width = Units::tw($frame->width);
        foreach ($frame->items as $item) {
            $table = $item['blocks'][0] ?? null;
            if (!$item['boxed'] || count($item['blocks']) !== 1 || !$table instanceof Table || count($table->rows) !== 1
                || ($item['style'] !== null && $item['style']->margin->vertical() + $item['style']->margin->horizontal() > 0.0)
                // a row spans the whole box: a child narrower than it, or placed within it, keeps its own table
                || $table->style->alignment !== Alignment::Start || $table->gridColumnsTwips === null || abs(array_sum($table->gridColumnsTwips) - Units::tw($frame->content)) > 1) {
                return null;
            }
            $rows[] = $table->rows[0];
        }
        if ($s->radius > 0.0) {
            $this->fidelity->dropped('radius', $path);
        }

        $outer = self::borders($s);
        $last = count($rows) - 1;
        foreach ($rows as $i => $row) {
            $cell = $row->cells[0];
            $own = $cell->style->borders;
            $edge = static fn (?Border $mine, ?Border $theirs): ?Border => $mine !== null && $mine->style !== DocxBorderStyle::None ? $mine : $theirs;
            $borders = new BorderSet(
                $edge($own?->top, $i === 0 ? $outer?->top : null),
                $edge($own?->right, $outer?->right),
                $edge($own?->bottom, $i === $last ? $outer?->bottom : null),
                $edge($own?->left, $outer?->left),
            );
            $padTop = $cell->style->paddingTopTwips + ($i === 0 ? Units::tw($s->borderWidth->top->points) : 0);
            $style = new CellStyle(...[...get_object_vars($cell->style), 'widthTwips' => $width, 'borders' => $borders, 'paddingTopTwips' => $padTop]);
            $rows[$i] = new TableRow([new TableCell($cell->children, $style)], $row->isHeader, $row->heightTwips, $row->cantSplit || $s->keepTogether, $row->heightRule);
        }
        $table = self::tableStyle($frame->width);
        if ($outer !== null) {
            $table = new TableStyle(...[...get_object_vars($table), 'borders' => $outer]);
        }

        return new Table($rows, $table, gridColumnsTwips: [$width]);
    }

    /**
     * A code block's box as paragraph shading and borders, which Word draws around consecutive paragraphs as one
     * box and which a reader can still select and edit line by line. Null where the box is anything else (a
     * border on some sides only, a fixed size, a child that is not a paragraph), which keeps its table.
     *
     * @param list<BlockElement> $blocks
     * @return ?list<BlockElement>
     */
    public function shadedParagraphs(array $blocks, ComputedStyle $s, Settled $settled, string $path): ?array
    {
        $w = $s->borderWidth;
        $fill = Units::fill($s->background);
        if ($blocks === [] || $fill === null || $s->height instanceof Length || !$s->width instanceof Auto
            || min($w->top->points, $w->right->points, $w->bottom->points, $w->left->points) <= 0.0) {
            return null;
        }
        foreach ($blocks as $b) {
            if (!$b instanceof Paragraph) {
                return null;
            }
        }
        if ($s->radius > 0.0) {
            $this->fidelity->dropped('radius', $path);
        }
        $style = $s->borderStyle === BorderStyle::Dashed ? DocxBorderStyle::Dashed : DocxBorderStyle::Single;
        $color = Units::hex($s->borderColor);
        // php-docx writes w:space="0"; DocxPatcher reads the padding out of the colour and writes it as the space.
        $side = static fn (Length $width, Length $pad): Border => new Border($style, max(2, (int) round($width->points * 8)), DocxPatcher::spaced((int) round(min(31.0, $pad->points)), $color));
        $borders = new BorderSet(
            $side($w->top, $s->padding->top),
            $side($w->right, $s->padding->right),
            $side($w->bottom, $s->padding->bottom),
            $side($w->left, $s->padding->left),
        );
        $left = Units::tw($s->padding->left->points + $w->left->points + $s->margin->left->points);
        $right = Units::tw($s->padding->right->points + $w->right->points + $s->margin->right->points);

        return array_map(static fn (Paragraph $p): Paragraph => Flow::withParagraph($p, [
            'borders' => $borders,
            'shadingColor' => $fill,
            'indentLeftTwips' => $p->style->indentLeftTwips + $left,
            'indentRightTwips' => $p->style->indentRightTwips + $right,
        ], $settled), $blocks);
    }

    /** @param array{float, float, float, float} $extraPad top, right, bottom, left in pt */
    public function cellStyle(ComputedStyle $s, int $widthTwips, int $span = 1, VerticalAlign $v = VerticalAlign::Top, ?ComputedStyle $row = null, array $extraPad = [0.0, 0.0, 0.0, 0.0], int $rowSpan = 1, bool $continue = false): CellStyle
    {
        return new CellStyle(
            widthTwips: $widthTwips,
            paddingTopTwips: Units::tw($s->padding->top->points + $s->borderWidth->top->points + $extraPad[0]),
            paddingRightTwips: Units::tw($s->padding->right->points + $extraPad[1]),
            paddingBottomTwips: Units::tw($s->padding->bottom->points + $extraPad[2]),
            paddingLeftTwips: Units::tw($s->padding->left->points + $extraPad[3]),
            verticalAlign: $v,
            backgroundColor: Units::fill($s->background) ?? ($row === null ? null : Units::fill($row->background)),
            borders: self::borders($s) ?? ($row === null ? null : self::borders($row)),
            gridSpan: $span,
            rowSpan: $rowSpan,
            vMergeContinue: $continue,
        );
    }

    public static function borders(ComputedStyle $s): ?BorderSet
    {
        $w = $s->borderWidth;
        if ($w->horizontal() + $w->vertical() <= 0.0) {
            return null;
        }
        $style = $s->borderStyle === BorderStyle::Dashed ? DocxBorderStyle::Dashed : DocxBorderStyle::Single;
        $color = Units::hex($s->borderColor);
        $side = static fn (Length $l): Border => $l->points > 0.0 ? new Border($style, max(2, (int) round($l->points * 8)), $color) : new Border(DocxBorderStyle::None, 0, 'auto');

        return new BorderSet($side($w->top), $side($w->right), $side($w->bottom), $side($w->left));
    }

    public static function tableStyle(float $width): TableStyle
    {
        $none = new Border(DocxBorderStyle::None, 0, 'auto');

        return new TableStyle(
            widthTwips: Units::tw($width),
            fixedLayout: true,
            borders: new BorderSet($none, $none, $none, $none, $none, $none),
            // php-docx writes no tblCellMar when all four are 0, and Word then pads 5.4pt a side; 1 twip forces it out.
            cellMarginTopTwips: 1,
            cellMarginRightTwips: 0,
            cellMarginLeftTwips: 0,
        );
    }

    /**
     * A cell must end in a paragraph; php-docx's own fallback is a default-height one.
     *
     * @param list<BlockElement> $blocks
     * @return non-empty-list<BlockElement>
     */
    public static function cellContent(array $blocks): array
    {
        if ($blocks === [] || !end($blocks) instanceof Paragraph) {
            $blocks[] = Flow::hairline();
        }

        return $blocks;
    }
}
