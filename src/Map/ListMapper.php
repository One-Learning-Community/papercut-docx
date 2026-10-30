<?php

declare(strict_types=1);

namespace Papercut\Docx\Map;

use Dskripchenko\PhpDocx\Element\{BlockElement, Field, Hyperlink, Paragraph, Run, Table};
use Dskripchenko\PhpDocx\Style\{ParagraphStyle, RunStyle, TableStyle};
use Papercut\Export\Fidelity;
use Papercut\Docx\Package\Numbering;
use Papercut\ListNode;
use Papercut\Style\ListMarker;

/**
 * List items as real Word numbering: the first paragraph of an item carries a reserved style id that DocxPatcher turns
 * into `w:numPr`, and Word draws the label and the hanging indent. A list with no marker only indents.
 */
final class ListMapper
{
    public const int INDENT = 360;

    /** @var list<array{ordered: bool, numId: ?int, marker: ListMarker, typed: bool, numbers: list<int>, next: int}> open lists, outermost first */
    private array $open = [];

    /** @var list<list<array{ordered: bool, numId: ?int, marker: ListMarker, typed: bool, numbers: list<int>, next: int}>> lists open outside the table cells being walked */
    private array $saved = [];

    private readonly Settled $settled;

    public function __construct(private readonly Numbering $numbering, private readonly Fidelity $fidelity)
    {
        $this->settled = new Settled();
    }

    /** The blocks list items have settled in this export; whatever copies blocks must carry the mark across. */
    public function settled(): Settled
    {
        return $this->settled;
    }

    /**
     * The marker the PDF draws for a list: its own, else the default for its depth among enclosing lists of the same kind.
     * The level in Word only counts the lists since the last cell, but the marker counts every enclosing list.
     *
     * @param list<bool> $enclosing the ordered flag of every enclosing list, outermost first
     */
    public static function markerFor(ListNode $list, array $enclosing): ListMarker
    {
        $depth = 1 + count(array_filter($enclosing, static fn (bool $o): bool => $o === $list->isOrdered()));

        return $list->markerStyle() ?? ListMarker::forDepth($list->isOrdered(), $depth);
    }

    public function enter(ListNode $list, string $path): void
    {
        $enclosing = [];
        foreach ([...$this->saved, $this->open] as $stack) {
            foreach ($stack as $l) {
                $enclosing[] = $l['ordered'];
            }
        }
        $marker = self::markerFor($list, $enclosing);
        $numbers = $list->numbers();
        $typed = $marker !== ListMarker::None && !self::numberable($marker, $numbers);
        if ($typed) {
            $this->fidelity->approximated('list numbering', $path);
        }
        $this->open[] = [
            'ordered' => $list->isOrdered(),
            'numId' => $marker === ListMarker::None || $typed ? null : $this->numbering->instance($marker, $numbers[0] ?? $list->startNumber()),
            'marker' => $marker,
            'typed' => $typed,
            'numbers' => $numbers,
            'next' => 0,
        ];
    }

    public function leave(): void
    {
        array_pop($this->open);
    }

    /** A table cell (or a box, which Word draws as one) starts its lists from level 0, whatever list it sits in. */
    public function enterCell(): void
    {
        $this->saved[] = $this->open;
        $this->open = [];
    }

    public function leaveCell(): void
    {
        $this->open = array_pop($this->saved) ?? [];
    }

    /**
     * One item's blocks: the first paragraph is numbered, later paragraphs and tables sit under the level.
     * A paragraph that already has an indent (a container's) keeps it, added to the level's.
     *
     * @param list<BlockElement> $blocks
     * @return list<BlockElement>
     */
    public function item(array $blocks, string $path): array
    {
        $i = count($this->open) - 1;
        if ($i < 0) {
            return $blocks;
        }
        $ilvl = $i;
        $list = $this->open[$i];
        $n = $list['numbers'][$list['next']] ?? null;
        $numId = $list['numId'];
        if ($numId !== null && self::isNumeric($list['marker']) && $n !== null && $list['next'] > 0 && $n !== $list['numbers'][$list['next'] - 1] + 1) {
            $numId = $this->numbering->instance($list['marker'], $n);
            $this->fidelity->approximated('list item number', $path);
        }
        $this->open[$i] = [...$list, 'numId' => $numId, 'next' => $list['next'] + 1];
        $label = $list['typed'] && $n !== null ? $list['marker']->label($n) : '';
        $marked = $numId !== null || $label !== '';
        $text = self::INDENT * ($ilvl + 1);

        $first = $blocks[0] ?? null;
        if ($marked && $first instanceof Paragraph && Flow::isHairline($first)) {
            // A spacing hairline can't carry a label (Word clips it): its spacing and page break move to a paragraph of normal height.
            // The hairline's space after was its margin less its own line; a normal line gives the full margin back.
            $after = $first->style->spaceAfterTwips;
            $blocks[0] = $first = new Paragraph([], new ParagraphStyle(...[...get_object_vars($first->style), 'spaceAfterTwips' => $after > 0 ? $after + Flow::HAIRLINE : 0, 'lineSpacingTwips' => null, 'lineSpacingRule' => null]));
        }
        // A rule (an exact line under a point, drawn by its border) clips a label as a hairline does, but it must stay: the label gets its own line above.
        $clipped = $first instanceof Paragraph && $first->style->lineSpacingRule === 'exact' && ($first->style->lineSpacingTwips ?? PHP_INT_MAX) < 20;
        // A bordered paragraph (a shaded code block, whose label is inside its box) can't carry the marker either: it would sit in the box.
        $boxed = $first instanceof Paragraph && $first->style->borders !== null;
        if ($marked && !($first instanceof Paragraph && !$clipped && !$boxed && !$this->settled->has($first))) {
            array_unshift($blocks, new Paragraph([]));
        }
        foreach ($blocks as $k => $b) {
            if ($this->settled->has($b)) {
                continue;
            }
            if ($b instanceof Table) {
                $b = new Table($b->rows, new TableStyle(...[...get_object_vars($b->style), 'indentTwips' => ($b->style->indentTwips ?? 0) + $text]), $b->caption, $b->gridColumnsTwips);
            } elseif (!$b instanceof Paragraph) {
                continue;
            } elseif ($k === 0 && $numId !== null) {
                $offset = $b->style->indentLeftTwips;
                $b = new Paragraph($b->children, $b->style, $b->headingLevel, Numbering::marker($numId, $ilvl, $b->headingLevel));
                if ($offset > 0) {
                    $b = Flow::withParagraph($b, ['indentLeftTwips' => $offset + $text, 'indentFirstLineTwips' => -self::INDENT], $this->settled);
                }
            } elseif ($k === 0 && $label !== '') {
                $b = $this->typed($b, $label, $text);
            } else {
                $b = Flow::withParagraph($b, ['indentLeftTwips' => $b->style->indentLeftTwips + $text], $this->settled);
            }
            $this->settled->mark($b);
            $blocks[$k] = $b;
        }

        return $blocks;
    }

    private static function isNumeric(ListMarker $marker): bool
    {
        return in_array($marker, [ListMarker::Decimal, ListMarker::LowerAlpha, ListMarker::UpperAlpha, ListMarker::LowerRoman, ListMarker::UpperRoman], true);
    }

    /**
     * Whether Word's numbering draws every one of these numbers as this marker does.
     *
     * @param list<int> $numbers */
    private static function numberable(ListMarker $marker, array $numbers): bool
    {
        if (!self::isNumeric($marker)) {
            return true;
        }
        $roman = $marker === ListMarker::LowerRoman || $marker === ListMarker::UpperRoman;
        foreach ($numbers as $n) {
            if ($n < 1 || ($roman && $n > 3999)) {
                return false;
            }
        }

        return true;
    }

    /** The label as text with a hanging indent: the way lists Word can't number are drawn. */
    private function typed(Paragraph $p, string $label, int $text): Paragraph
    {
        $children = $p->children;
        $first = $children[0] ?? null;
        if ($first instanceof Hyperlink) {
            $first = $first->children[0] ?? null;
        }
        $style = $first instanceof Run || $first instanceof Field ? $first->style : new RunStyle();
        array_unshift($children, new Run($label . "\t", $style));

        return Flow::withParagraph(new Paragraph($children, $p->style, $p->headingLevel, $p->styleId), [
            'indentLeftTwips' => $p->style->indentLeftTwips + $text,
            'indentFirstLineTwips' => -self::INDENT,
        ], $this->settled);
    }
}
