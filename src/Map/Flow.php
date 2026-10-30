<?php

declare(strict_types=1);

namespace Papercut\Docx\Map;

use Dskripchenko\PhpDocx\Element\{BlockElement, PageBreak, Paragraph, Run, Table, TableCell, TableRow};
use Dskripchenko\PhpDocx\Style\{ParagraphStyle, RunStyle};
use Papercut\Docx\Walk\Frame;
use Papercut\Style\ComputedStyle;

/** A column's children as a run of blocks: gap and vertical margins as paragraph spacing, pagination as flags. */
final class Flow
{
    /** A hairline paragraph's exact line height in twips; its space after is the requested space less this. */
    public const int HAIRLINE = 2;

    /** @return list<BlockElement> */
    public static function blocks(Frame $frame, Settled $settled): array
    {
        $gap = $frame->style->gap ?? 0.0;
        $out = [];
        $items = array_values(array_filter($frame->items, static fn (array $i): bool => $i['blocks'] !== []));
        $count = count($items);
        foreach ($items as $i => $item) {
            $blocks = $item['blocks'];
            $s = $item['style'];
            $before = $s?->margin->top->points ?? 0.0;
            $after = ($s?->margin->bottom->points ?? 0.0) + ($i < $count - 1 ? $gap : 0.0);
            if ($s !== null) {
                $blocks = self::pagination($blocks, $s, $settled);
            }
            if ($before > 0.0) {
                $blocks = self::spaceBefore($blocks, $before, $settled);
            }
            // Two tables back to back merge into one in Word; a hairline paragraph keeps them apart.
            if ($out !== [] && end($out) instanceof Table && $blocks[0] instanceof Table) {
                $out[] = self::hairline();
            }
            // Word draws consecutive paragraphs with identical borders as one box, so two boxes back to back (shaded code
            // blocks) need an unbordered paragraph between them. The same separates two rules in a row (a rule is a bordered
            // paragraph), which would otherwise merge into one line.
            $prev = $out === [] ? null : end($out);
            if ($prev instanceof Paragraph && $prev->style->borders !== null && $blocks[0] instanceof Paragraph && $blocks[0]->style->borders !== null) {
                $out[] = self::hairline();
            }
            foreach (self::spaceAfter($blocks, $after, $settled) as $b) {
                $out[] = $b;
            }
        }

        return $out;
    }

    /**
     * @param non-empty-list<BlockElement> $blocks
     * @return non-empty-list<BlockElement>
     */
    private static function pagination(array $blocks, ComputedStyle $s, Settled $settled): array
    {
        $last = count($blocks) - 1;
        foreach ($blocks as $i => $b) {
            if ($b instanceof Table && $s->keepTogether && $b->rows !== []) {
                // Each row stays with the next; the last one too when more of the kept block follows.
                $blocks[$i] = self::keepRowsWithNext($b, $settled, 0, count($b->rows) - ($i < $last ? 1 : 2));
                continue;
            }
            if (!$b instanceof Paragraph) {
                continue;
            }
            $changes = [];
            if ($s->keepTogether) {
                $changes['keepLines'] = true;
                if ($i < $last) {
                    $changes['keepWithNext'] = true;
                }
            }
            if ($s->keepWithNext && $i === $last) {
                $changes['keepWithNext'] = true;
            }
            if ($s->breakBefore && $i === 0) {
                $changes['pageBreakBefore'] = true;
            }
            if ($changes !== []) {
                $blocks[$i] = self::withParagraph($b, $changes, $settled);
            }
        }
        $lastBlock = $blocks[$last];
        if ($s->keepWithNext && $lastBlock instanceof Table) {
            // Word keeps a table with what follows through keepNext on the paragraphs of its last row.
            $blocks[$last] = self::keepLastRowWithNext($lastBlock, $settled);
        }
        if ($s->breakBefore && !$blocks[0] instanceof Paragraph) {
            array_unshift($blocks, self::withParagraph(self::hairline(), ['pageBreakBefore' => true], $settled));
        }
        if ($s->breakAfter) {
            $blocks[] = new PageBreak();
        }

        return array_values($blocks);
    }

    private static function keepLastRowWithNext(Table $t, Settled $settled): Table
    {
        return self::keepRowsWithNext($t, $settled, count($t->rows) - 1);
    }

    /** Word has no keep-together for a table: keepNext on the paragraphs of rows $from..$to (default: just $from) keeps them with the row after. */
    public static function keepRowsWithNext(Table $t, Settled $settled, int $from, ?int $to = null): Table
    {
        $rows = $t->rows;
        $to ??= $from;
        for ($i = max(0, $from); $i <= min($to, count($rows) - 1); $i++) {
            $row = $rows[$i];
            $cells = [];
            foreach ($row->cells as $cell) {
                $children = array_map(static fn (BlockElement $b): BlockElement => $b instanceof Paragraph ? self::withParagraph($b, ['keepWithNext' => true], $settled) : $b, $cell->children);
                $cells[] = new TableCell($children, $cell->style);
            }
            $rows[$i] = new TableRow($cells, $row->isHeader, $row->heightTwips, $row->cantSplit, $row->heightRule);
        }

        $copy = new Table(array_values($rows), $t->style, $t->caption, $t->gridColumnsTwips);
        $settled->carry($t, $copy);

        return $copy;
    }

    /**
     * A hairline paragraph with $after points of space after it. Its height is spacing, not line height,
     * and its run is 1pt, so readers without exact line heights (Google Docs) draw it tiny too.
     */
    public static function hairline(float $after = 0.0): Paragraph
    {
        $p = new Paragraph(
            [new Run('', new RunStyle(sizeHalfPoints: 2))],
            new ParagraphStyle(spaceAfterTwips: max(0, Units::tw($after) - self::HAIRLINE), lineSpacingTwips: self::HAIRLINE, lineSpacingRule: 'exact'),
        );
        self::$hairlines ??= new \WeakMap();
        self::$hairlines[$p] = true;

        return $p;
    }

    /** @var ?\WeakMap<Paragraph, true> paragraphs made by hairline(), and their copies */
    private static ?\WeakMap $hairlines = null;

    /** Whether the paragraph is a spacing hairline made here (or a copy with more spacing), not text. */
    public static function isHairline(Paragraph $p): bool
    {
        return self::$hairlines !== null && isset(self::$hairlines[$p]);
    }

    /**
     * @param non-empty-list<BlockElement> $blocks
     * @return non-empty-list<BlockElement>
     */
    public static function spaceAfter(array $blocks, float $points, Settled $settled): array
    {
        if ($points <= 0.0) {
            return $blocks;
        }
        $last = count($blocks) - 1;
        $p = $blocks[$last];
        if ($p instanceof Paragraph) {
            $blocks[$last] = self::withParagraph($p, ['spaceAfterTwips' => $p->style->spaceAfterTwips + Units::tw($points)], $settled);
        } else {
            $blocks[] = self::hairline($points);
        }

        return $blocks;
    }

    /**
     * @param non-empty-list<BlockElement> $blocks
     * @return non-empty-list<BlockElement>
     */
    public static function spaceBefore(array $blocks, float $points, Settled $settled): array
    {
        if ($points <= 0.0) {
            return $blocks;
        }
        $p = $blocks[0];
        if ($p instanceof Paragraph) {
            $blocks[0] = self::withParagraph($p, ['spaceBeforeTwips' => $p->style->spaceBeforeTwips + Units::tw($points)], $settled);
        } else {
            array_unshift($blocks, self::hairline($points));
        }

        return $blocks;
    }

    /** @param array<string, mixed> $changes ParagraphStyle constructor arguments */
    public static function withParagraph(Paragraph $p, array $changes, Settled $settled): Paragraph
    {
        $copy = new Paragraph($p->children, new ParagraphStyle(...[...get_object_vars($p->style), ...$changes]), $p->headingLevel, $p->styleId);
        $settled->carry($p, $copy);
        if (self::isHairline($p)) {
            self::$hairlines ??= new \WeakMap();
            self::$hairlines[$copy] = true;
        }

        return $copy;
    }
}
