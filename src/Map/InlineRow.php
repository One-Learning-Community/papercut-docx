<?php

declare(strict_types=1);

namespace Papercut\Docx\Map;

use Dskripchenko\PhpDocx\Element\{Hyperlink, InlineElement, Paragraph, Run};
use Dskripchenko\PhpDocx\Style\{Alignment, ParagraphStyle, RunStyle};
use Papercut\Docx\Package\DocxPatcher;
use Papercut\Docx\Walk\Frame;
use Papercut\Export\Fidelity;
use Papercut\Layout\WidthResolver;
use Papercut\View;
use Papercut\Style\{ComputedStyle, Justify};
use Papercut\Value\Auto;

/**
 * A row of nothing but inline content (text, links, badges) as one paragraph, so a list bullet shares its line and
 * a badge wraps with its neighbours instead of inside a narrow table cell.
 *
 * A row qualifies when, in the PDF, it is a single flex line packed at the start, undecorated and unsized, and every
 * item is one paragraph of text runs (links included) that is content-sized: it does not grow, has no width, height,
 * margin or border, and carries padding only when it has a background (a badge), which becomes a no-break space on
 * each side inside the shading. A rounded corner is lost; that is reported. The items' max-content widths plus the
 * gaps must fit the row, so the PDF sets them on one line without squeezing any; otherwise the PDF wraps a long item
 * in its own column, and the row keeps its table. Anything else keeps its table.
 */
final class InlineRow
{
    private const string NBSP = "\u{00A0}";
    /** Padding at least this wide (pt) reads as a space inside the shading. */
    private const float PAD = 3.0;
    private const float EPSILON = 0.01;

    public function __construct(
        private readonly Fidelity $fidelity,
        private readonly TextMapper $text,
        private readonly WidthResolver $widths,
    ) {
    }

    /** The row as a paragraph, or null if it needs a table. */
    public function line(ComputedStyle $s, Frame $frame, string $path): ?Paragraph
    {
        if (count($frame->lines) !== 1 || $frame->items === [] || $s->justify !== Justify::Start
            || BoxMapper::decorated($s) || $s->radius > 0.0 || !$s->height instanceof Auto || $s->minHeight !== null) {
            return null;
        }
        /** @var list<array{Paragraph, ComputedStyle}> $parts */
        $parts = [];
        foreach ($frame->items as $item) {
            $p = $item['blocks'][0] ?? null;
            if ($item['boxed'] || count($item['blocks']) !== 1 || !$p instanceof Paragraph || !$item['style'] instanceof ComputedStyle
                || !self::inline($item['style']) || !self::runsOnly($p->children)) {
                return null;
            }
            $parts[] = [$p, $item['style']];
        }

        if (!$this->fits($s, $frame)) {
            return null;
        }

        $space = $this->text->runStyle($s);
        $children = [];
        foreach ($parts as $k => [$p, $style]) {
            if ($k > 0 && $s->gap > 0.0) {
                $children[] = new Run(' ', $space);
            }
            foreach ($this->item($p, $style, $path) as $child) {
                $children[] = $child;
            }
        }
        // Safe to copy: items have zero margins, so the first paragraph carries only line spacing and flags, no spacing of its own.
        // The first item's paragraph settings (line spacing); the items sit at the start whatever the row inherits.
        $style = new ParagraphStyle(...[...get_object_vars($parts[0][0]->style), 'alignment' => Alignment::Start]);

        return new Paragraph($children, $style);
    }

    /** Whether the items at their max-content widths, gaps between, fit the row's width. */
    private function fits(ComputedStyle $s, Frame $frame): bool
    {
        $row = $frame->node;
        if (!$row instanceof View) {
            return false;
        }
        // Offered unbounded, every item sits at its max-content width (none grows, so none stretches).
        $probe = new ComputedStyle(...[...get_object_vars($s), 'width' => Auto::instance(), 'minWidth' => null, 'maxWidth' => null]);
        $slots = $this->widths->flexLines($row, $probe, 1.0e7)[0] ?? [];
        $need = $s->gap * max(0, count($slots) - 1);
        foreach ($slots as $slot) {
            $need += $slot->width;
        }

        return $need <= $frame->content + self::EPSILON;
    }

    private static function inline(ComputedStyle $i): bool
    {
        $shaded = Units::fill($i->background) !== null;

        return $i->grow === 0.0 && $i->width instanceof Auto && $i->height instanceof Auto
            && $i->basis instanceof Auto && $i->minWidth === null && $i->maxWidth === null
            && $i->minHeight === null && $i->maxHeight === null
            && $i->margin->horizontal() + $i->margin->vertical() === 0.0
            && $i->borderWidth->horizontal() + $i->borderWidth->vertical() === 0.0
            && ($shaded || $i->padding->horizontal() + $i->padding->vertical() === 0.0);
    }

    /** @param list<InlineElement> $children */
    private static function runsOnly(array $children): bool
    {
        foreach ($children as $c) {
            if ($c instanceof Hyperlink) {
                if (!self::runsOnly($c->children)) {
                    return false;
                }
            } elseif (!$c instanceof Run) {
                return false;
            }
        }

        return $children !== [];
    }

    /** @return list<InlineElement> */
    private function item(Paragraph $p, ComputedStyle $i, string $path): array
    {
        $fill = Units::fill($i->background);
        if ($fill === null) {
            return $p->children;
        }
        $shade = static fn (RunStyle $r): RunStyle => $r->highlight !== null ? $r : new RunStyle(...[...get_object_vars($r), 'highlight' => DocxPatcher::shading($fill)]);
        $out = [];
        $firstRun = null;
        $lastRun = null;
        foreach ($p->children as $c) {
            if ($c instanceof Hyperlink) {
                $runs = array_map(static fn (InlineElement $r): InlineElement => $r instanceof Run ? new Run($r->text, $shade($r->style)) : $r, $c->children);
                $c = new Hyperlink($c->href, $runs, $c->anchor);
                $inner = $runs[0] ?? null;
                $innerLast = $runs[count($runs) - 1] ?? null;
                $firstRun ??= $inner instanceof Run ? $inner : null;
                $lastRun = $innerLast instanceof Run ? $innerLast : $lastRun;
            } elseif ($c instanceof Run) {
                $c = new Run($c->text, $shade($c->style));
                $firstRun ??= $c;
                $lastRun = $c;
            }
            $out[] = $c;
        }
        if ($i->padding->left->points >= self::PAD && $firstRun !== null) {
            array_unshift($out, new Run(self::NBSP, $firstRun->style));
        }
        if ($i->padding->right->points >= self::PAD && $lastRun !== null) {
            $out[] = new Run(self::NBSP, $lastRun->style);
        }
        if ($i->radius > 0.0) {
            $this->fidelity->approximated('badge in a line', $path);
        }

        return $out;
    }
}
