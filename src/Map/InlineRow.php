<?php

declare(strict_types=1);

namespace Papercut\Docx\Map;

use Dskripchenko\PhpDocx\Element\{Hyperlink, InlineElement, Paragraph, Run};
use Dskripchenko\PhpDocx\Style\{Alignment, ParagraphStyle};
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
 * margin, and carries a border or padding only when it is a badge: a background, or the same solid or dashed border on
 * every side, or both. The fill becomes run shading, the border a run border (Word joins the runs into one box), and
 * the padding no-break spaces inside them (see BadgeRun). A rounded corner is lost; that is reported. The items' max-content widths plus the
 * gaps must fit the row, so the PDF sets them on one line without squeezing any; otherwise the PDF wraps a long item
 * in its own column, and the row keeps its table. Anything else keeps its table.
 *
 * @internal Part of Papercut's engine, not its public API. It may change in any release.
 */
final class InlineRow
{
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
                || !self::inline($item['style']) || !self::runsOnly($p->children) || EquationMapper::isMarker($p)) {
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
        $border = $i->borderWidth->horizontal() + $i->borderWidth->vertical() > 0.0;

        return $i->grow === 0.0 && $i->width instanceof Auto && $i->height instanceof Auto
            && $i->basis instanceof Auto && $i->minWidth === null && $i->maxWidth === null
            && $i->minHeight === null && $i->maxHeight === null
            && $i->margin->horizontal() + $i->margin->vertical() === 0.0
            && (!$border || BadgeRun::evenBorder($i) !== null)
            && ($shaded || $border || $i->padding->horizontal() + $i->padding->vertical() === 0.0);
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
        $border = BadgeRun::evenBorder($i);
        if (Units::fill($i->background) === null && $border === null) {
            return $p->children;
        }
        if ($i->radius > 0.0) {
            $this->fidelity->approximated('badge in a line', $path);
        }

        // Only a badge (undecorated by now) is padded; a plain highlight stays a shaded run.
        return BadgeRun::runs($p->children, $i, $border, $i->padding->horizontal() > 0.0);
    }
}
