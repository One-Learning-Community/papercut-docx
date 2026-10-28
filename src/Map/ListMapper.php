<?php

declare(strict_types=1);

namespace Papercut\Docx\Map;

use Dskripchenko\PhpDocx\Element\{BlockElement, Field, Hyperlink, Paragraph, Run};
use Dskripchenko\PhpDocx\Style\RunStyle;
use Papercut\ListNode;
use Papercut\Style\ListMarker;

/**
 * List items as paragraphs with a typed marker and a hanging indent. Markers are text, not Word numbering,
 * so they match the PDF exactly (start numbers, value overrides, per-kind depth) in every reader.
 */
final class ListMapper
{
    private const int INDENT = 360;

    /** @var list<array{ordered: bool, marker: ListMarker, numbers: list<int>, next: int}> open lists, outermost first */
    private array $open = [];

    public function enter(ListNode $list): void
    {
        $depth = 1 + count(array_filter($this->open, static fn (array $l): bool => $l['ordered'] === $list->isOrdered()));
        $this->open[] = [
            'ordered' => $list->isOrdered(),
            'marker' => $list->markerStyle() ?? ListMarker::forDepth($list->isOrdered(), $depth),
            'numbers' => $list->numbers(),
            'next' => 0,
        ];
    }

    public function leave(): void
    {
        array_pop($this->open);
    }

    /**
     * One item's blocks: every paragraph moves in one level (nested lists accumulate), the first gets the marker.
     *
     * @param list<BlockElement> $blocks
     * @return list<BlockElement>
     */
    public function item(array $blocks): array
    {
        $i = count($this->open) - 1;
        if ($i < 0) {
            return $blocks;
        }
        $list = $this->open[$i];
        $n = $list['numbers'][$list['next']] ?? ($list['numbers'] === [] ? 1 : end($list['numbers']) + 1);
        $this->open[$i]['next']++;
        $label = $list['marker']->label($n);

        if ($label !== '' && !(($blocks[0] ?? null) instanceof Paragraph)) {
            array_unshift($blocks, new Paragraph([]));
        }
        foreach ($blocks as $k => $b) {
            if (!$b instanceof Paragraph) {
                continue;
            }
            $changes = ['indentLeftTwips' => $b->style->indentLeftTwips + self::INDENT];
            $children = $b->children;
            if ($k === 0 && $label !== '') {
                $first = $children[0] ?? null;
                if ($first instanceof Hyperlink) {
                    $first = $first->children[0] ?? null;
                }
                $style = $first instanceof Run || $first instanceof Field ? $first->style : new RunStyle();
                array_unshift($children, new Run($label . "\t", $style));
                $changes['indentFirstLineTwips'] = -self::INDENT;
            }
            $blocks[$k] = Flow::withParagraph(new Paragraph($children, $b->style, $b->headingLevel, $b->styleId), $changes);
        }

        return $blocks;
    }
}
