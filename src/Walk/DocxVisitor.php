<?php

declare(strict_types=1);

namespace Papercut\Docx\Walk;

use Dskripchenko\PhpDocx\Element\{BlockElement, Image as DocxImage, PageBreak as DocxPageBreak, Paragraph};
use Dskripchenko\PhpDocx\Style\{Alignment, HorizontalAnchor, ImagePosition, ParagraphStyle, VerticalAnchor};
use Papercut\{Image, Link, ListItem, ListNode, Node, Page, PageBreak, PageNumber, PdfPage, Place, QrCode, Spacer, Svg, Table, TableCell, TableRow, Text, View};
use Papercut\Docx\Map\{BoxMapper, Fade, Flow, ImageMapper, ListMapper, RowMapper, TableMapper, TextMapper, Units};
use Papercut\Export\Fidelity;
use Papercut\Layout\WidthResolver;
use Papercut\Style\{Align, ComputedStyle, Direction, Justify, TextAlign};
use Papercut\Tree\{Region, TreeVisitor, Visit};
use Papercut\Value\{Auto, Edges, Length, Percent};

use function Papercut\pt;

/**
 * Turns a tree walk into php-docx blocks (approach B2 of the multi-format spec): columns flow as paragraphs,
 * rows become borderless fixed-layout tables sized by WidthResolver, and a decorated box becomes a single-cell table.
 */
final class DocxVisitor implements TreeVisitor
{
    /** @var list<Frame> */
    private array $stack = [];
    /** @var array<string, list<BlockElement>> blocks per region of the current page */
    private array $regions = [];
    /** @var list<array{page: Page, regions: array<string, list<BlockElement>>, floats: list<DocxImage>}> */
    private array $pages = [];
    private ?Page $page = null;
    /** True inside a background or overlay region: only its pictures reach Word, as floating pictures. */
    private bool $skipping = false;
    /** The background or overlay region being walked. */
    private ?Region $layer = null;
    /** How far down the page the background's pictures have reached, in pt. */
    private float $layerY = 0.0;
    /** Set once a background holds something other than a picture: what follows sits at a height Word can't know. */
    private bool $layerBlocked = false;
    /** The overlay place being walked. */
    private ?Place $place = null;
    /** @var list<DocxImage> the current page's layer pictures, positioned on the page */
    private array $floats = [];
    /** @var list<string> enclosing block link URIs, innermost last */
    private array $links = [];

    public function __construct(
        private readonly Fidelity $fidelity,
        private readonly WidthResolver $widths,
        private readonly TextMapper $text,
        private readonly BoxMapper $boxes,
        private readonly RowMapper $rows,
        private readonly TableMapper $tables,
        private readonly ListMapper $lists,
        private readonly ImageMapper $images,
    ) {
    }

    /** @return list<array{page: Page, regions: array<string, list<BlockElement>>, floats: list<DocxImage>}> */
    public function pages(): array
    {
        return $this->pages;
    }

    public function enterPage(Page $page, int $index): void
    {
        $this->page = $page;
        $this->regions = [];
        $this->floats = [];
    }

    public function leavePage(Page $page, int $index): void
    {
        $this->pages[] = ['page' => $page, 'regions' => $this->regions, 'floats' => $this->floats];
    }

    public function enterRegion(Region $region, ComputedStyle $root): void
    {
        $this->skipping = $region === Region::Background || $region === Region::Overlay;
        $this->layer = $this->skipping ? $region : null;
        $this->layerY = 0.0;
        $this->layerBlocked = false;
        $this->place = null;
        $page = $this->page;
        $width = $page === null ? 0.0 : $page->width() - $page->margins()->left->points - $page->margins()->right->points;
        $this->stack = [new Frame(null, $root, $width, $width)];
    }

    public function leaveRegion(Region $region): void
    {
        $root = array_pop($this->stack);
        \assert($root instanceof Frame);
        if ($this->skipping) {
            $this->skipping = false;

            return;
        }
        $blocks = Flow::blocks($root);
        // Word pushes the body below the header's full extent, spacing included, so the gap rides on the header.
        if ($blocks !== [] && $region === Region::Header) {
            $blocks = Flow::spaceAfter($blocks, $this->page?->headerGapLength()->points ?? 0.0);
        } elseif ($blocks !== [] && $region === Region::Footer) {
            $blocks = Flow::spaceBefore($blocks, $this->page?->footerGapLength()->points ?? 0.0);
        }
        $this->regions[$region->value] = $blocks;
    }

    public function enter(Node $node, ComputedStyle $style, Visit $at): bool
    {
        if ($this->skipping) {
            return $this->layerNode($node, $style, $at);
        }
        $parent = $this->top();
        // The opacity in effect here: this node's times its ancestors' (a faded style carries the product).
        $opacity = ($parent->style->opacity ?? 1.0) * $style->opacity;
        if ($opacity < 1.0) {
            $style = Fade::style($style, $opacity, $parent->backdrop);
        }
        $width = $this->outerWidth($node, $style, $parent, $at);
        $content = max(0.0, $width - $style->padding->horizontal() - $style->borderWidth->horizontal());
        $isRow = $node instanceof View && $style->direction === Direction::Row;
        $frame = new Frame($node, $style, $width, $content, $isRow);
        $frame->backdrop = Fade::under($style->background, $parent->backdrop);
        if ($node instanceof View && $isRow) {
            $frame->lines = $this->widths->flexLines($node, $style, $width);
            foreach ($frame->lines as $line) {
                foreach ($line as $slot) {
                    $frame->slots[$slot->index] = $slot;
                }
            }
        } elseif ($node instanceof Table) {
            $frame->columns = TableMapper::settle($this->widths->tableColumns($node, $style, $width), WidthResolver::tracks($node));
        } elseif ($node instanceof TableRow) {
            $frame->header = $at->inHeader() && $at->parent() instanceof Table && $at->parent()->repeatsHeader();
        } elseif ($node instanceof ListNode) {
            $this->lists->enter($node);
        } elseif ($node instanceof Link) {
            $this->links[] = TextMapper::href($node->uri());
            $this->fidelity->approximated('block link', $at->path());
        }
        $this->stack[] = $frame;
        if (TextMapper::glyph($node, $style) !== null) {
            return false;
        }

        // Leaves produce their blocks in leave(); containers wait for their children.
        return !($node instanceof Text || $node instanceof Spacer || $node instanceof PageBreak || $node instanceof PageNumber
            || $node instanceof Image || $node instanceof QrCode || $node instanceof Svg || $node instanceof PdfPage || $node instanceof Place);
    }

    public function leave(Node $node, ComputedStyle $style, Visit $at): void
    {
        if ($this->skipping) {
            return;
        }
        $frame = array_pop($this->stack);
        \assert($frame instanceof Frame);
        $style = $frame->style ?? $style;
        $parent = $this->top();

        if ($node instanceof TableCell && $parent->node instanceof TableRow) {
            $parent->cells[] = $this->tables->cell($node, $style, $frame, $parent, Flow::blocks($frame), $at->path());

            return;
        }
        if ($node instanceof TableRow && $parent->node instanceof Table) {
            $parent->rows[] = $this->tables->row($frame);

            return;
        }

        $glyph = TextMapper::glyph($node, $style);
        $blocks = match (true) {
            $glyph !== null => [$this->text->glyphParagraph($glyph, $style)],
            $node instanceof Text => [$this->text->paragraph($node, $style, $at, $this->links === [] ? null : end($this->links), $frame->backdrop)],
            $node instanceof PageNumber => [$this->text->pageNumber($node, $style)],
            $node instanceof Spacer => $this->spacer($node, $parent, $at),
            $node instanceof PageBreak => [new DocxPageBreak()],
            $node instanceof Image => $this->images->image($node, $style, $frame, $at->path()),
            $node instanceof QrCode => $this->images->qr($node, $style, $frame, $at->path()),
            $node instanceof Svg => $this->images->svg($node, $style, $frame, $at->path()),
            $node instanceof PdfPage, $node instanceof Place => $this->drop($node, $at),
            $node instanceof ListNode => $this->leaveList($frame),
            $node instanceof ListItem => $this->lists->item(Flow::blocks($frame)),
            $node instanceof Link => $this->leaveLink($frame),
            $node instanceof Table => [$this->tables->table($style, $frame)],
            $frame->isRow => [$this->rows->row($style, $frame, $at->path())],
            default => Flow::blocks($frame),
        };
        // A column centres or end-aligns a child that doesn't stretch; for a paragraph that is its alignment.
        $cross = $parent->isRow ? Align::Stretch : ($style->alignSelf ?? $parent->style->alignItems ?? Align::Stretch);
        $crossJc = match ($cross) {
            Align::Center => Alignment::Center,
            Align::End => Alignment::End,
            default => null,
        };
        $picture = $node instanceof Image || $node instanceof QrCode || $node instanceof Svg;
        if ($picture) {
            $blocks = array_map(static fn (BlockElement $b): BlockElement => $b instanceof DocxImage
                ? new Paragraph([$b], new ParagraphStyle(alignment: $crossJc ?? Alignment::Start, lineSpacingTwips: 240, lineSpacingRule: 'auto'))
                : $b, $blocks);
        } elseif ($node instanceof Text && $crossJc !== null && $style->textAlign === TextAlign::Left) {
            $blocks = array_map(static fn (BlockElement $b): BlockElement => $b instanceof Paragraph ? Flow::withParagraph($b, ['alignment' => $crossJc]) : $b, $blocks);
        }

        // A child of a row becomes a cell, which carries its box itself; elsewhere a decorated box is wrapped.
        $boxed = false;
        if ($glyph !== null) {
            $style = BoxMapper::undecorated($style);
        } elseif (!$parent->isRow && !$frame->isRow && !$node instanceof Table && BoxMapper::decorated($style)) {
            $align = match ($style->alignSelf ?? $parent->style->alignItems ?? Align::Stretch) {
                Align::Center => Alignment::Center,
                Align::End => Alignment::End,
                default => Alignment::Start,
            };
            // Word sizes a table row, not a paragraph run, so a lone undecorated child's min height moves up to the box.
            $only = count($frame->items) === 1 ? $frame->items[0] : null;
            $childMin = $only !== null && !$only['boxed'] && $only['style']?->minHeight instanceof Length
                ? $only['style']->minHeight->points + $style->padding->vertical() + $style->borderWidth->vertical()
                : null;
            $blocks = [$this->boxes->stack($frame, $at->path()) ?? $this->boxes->box($blocks, $style, $frame->width, $at->path(), $align, $childMin)];
            $boxed = true;
        }
        $parent->items[] = ['blocks' => $blocks, 'style' => $style, 'index' => $at->index(), 'boxed' => $boxed];
    }

    private function top(): Frame
    {
        $top = end($this->stack);
        \assert($top instanceof Frame);

        return $top;
    }

    /** Border-box width: the row slot, the table cell's columns, an explicit width, or the parent's content box. */
    private function outerWidth(Node $node, ComputedStyle $style, Frame $parent, Visit $at): float
    {
        if ($parent->isRow) {
            return $parent->slots[$at->index()]->width ?? 0.0;
        }
        if ($node instanceof TableCell && $parent->node instanceof TableRow) {
            $table = $this->stack[count($this->stack) - 2] ?? null;
            $cols = $table instanceof Frame ? $table->columns : [];

            return array_sum(array_slice($cols, $parent->column, $node->span()));
        }
        if ($node instanceof TableRow) {
            return $parent->content;
        }
        $avail = max(0.0, $parent->content - $style->margin->horizontal());
        $w = match (true) {
            $style->width instanceof Length => $style->width->points,
            // As in the PDF, a percentage is of the space left after the margins.
            $style->width instanceof Percent => $avail * $style->width->value / 100,
            default => $avail,
        };
        if ($style->maxWidth instanceof Length) {
            $w = min($w, $style->maxWidth->points);
        }
        // A box that doesn't stretch across its column is as wide as its content, as in the PDF.
        $align = $style->alignSelf ?? $parent->style->alignItems ?? Align::Stretch;
        if ($node instanceof View && $style->width instanceof Auto && $align !== Align::Stretch && BoxMapper::decorated($style) && $parent->style !== null) {
            $w = min($w, $this->intrinsic($node, $parent->style, $avail));
        }

        return min($w, $avail);
    }

    /**
     * The node's content width: its slot in a one-item row, plus a little slack (Word's text measures a hair
     * wider than ours, and a box that wraps reads worse than one a few points wider).
     */
    private function intrinsic(View $node, ComputedStyle $parent, float $avail): float
    {
        $row = new ComputedStyle(...[...get_object_vars($parent), 'direction' => Direction::Row, 'gap' => 0.0, 'wrap' => false,
            'padding' => Edges::zero(), 'borderWidth' => Edges::zero(), 'justify' => Justify::Start, 'alignItems' => Align::Start]);
        $slot = $this->widths->flexLines(View::make($node), $row, $avail)[0][0] ?? null;

        return $slot === null ? $avail : min($avail, $slot->width * 1.03 + 2.0);
    }

    /** @return list<BlockElement> */
    private function spacer(Spacer $sp, Frame $parent, Visit $at): array
    {
        if ($parent->isRow) {
            return [];
        }
        $fixed = $sp->fixedSize();
        if ($fixed === null) {
            $this->fidelity->approximated('flexible spacer', $at->path());

            return [];
        }

        return [Flow::hairline($fixed->points)];
    }

    /** @return list<BlockElement> */
    private function leaveList(Frame $frame): array
    {
        $this->lists->leave();

        return Flow::blocks($frame);
    }

    /** @return list<BlockElement> */
    private function leaveLink(Frame $frame): array
    {
        array_pop($this->links);

        return Flow::blocks($frame);
    }

    /** @return list<BlockElement> */
    private function drop(Node $n, Visit $at): array
    {
        $this->fidelity->dropped($n->debugName(), $at->path());

        return [];
    }

    /**
     * A node of a background or overlay. Pictures become floating pictures placed where the PDF draws them
     * (Paginator: the background is a column the width of the page from its top-left corner; a place lays out its
     * content in a box its width, or as wide as the content wants, and puts the box's anchor at its point).
     * Anything else is dropped: Word floats only pictures.
     */
    private function layerNode(Node $node, ComputedStyle $style, Visit $at): bool
    {
        $page = $this->page;
        \assert($page !== null);
        [$w, $h] = [$page->width(), $page->height()];
        $m = $style->margin;

        if ($this->layer === Region::Background) {
            $picture = $this->layerBlocked || $at->parent() !== null ? null : $this->layerPicture($node, $style, $w, $at);
            if ($picture === null) {
                if (!self::isPicture($node) || $this->layerBlocked) {
                    $this->fidelity->dropped('background layer', $at->path());
                }
                $this->layerBlocked = true;

                return false;
            }
            [$image, $boxW, $boxH] = $picture;
            $x = self::aligned($style, $m->left->points, $w - $m->horizontal(), $boxW);
            $this->float($image, $style, $x, $this->layerY + $m->top->points, true);
            $this->layerY += $m->vertical() + $boxH;

            return false;
        }

        if ($node instanceof Place) {
            $this->place = $node;
            $children = count($node->children());
            if ($children > 1) {
                $this->fidelity->dropped('overlay layer', $at->path());
            }

            return $children === 1;
        }
        $place = $this->place;
        \assert($place !== null);
        if (!self::isPicture($node)) {
            $this->fidelity->dropped('overlay layer', $at->path());

            return false;
        }
        \assert($node instanceof Image || $node instanceof QrCode || $node instanceof Svg);
        $given = $place->boxWidth();
        $box = $given === null
            ? min($w, $this->images->intrinsicWidth($node, $style) + $m->horizontal())
            : max(0.0, self::resolve($given, $w));
        $picture = $this->layerPicture($node, $style, $box, $at);
        if ($picture === null) {
            return false;
        }
        [$image, $boxW, $boxH] = $picture;
        $anchor = $place->anchorPoint();
        $x = self::resolve($place->x(), $w) - $anchor->across() * $box;
        $y = self::resolve($place->y(), $h) - $anchor->down() * ($boxH + $m->vertical());
        $this->float($image, $style, self::aligned($style, $x + $m->left->points, $box - $m->horizontal(), $boxW), $y + $m->top->points, false);

        return false;
    }

    /**
     * The picture a layer node draws in a column $space pt wide, as the PDF sizes it, with its border box in pt;
     * null if it isn't a picture or can't be drawn (the mapper reports why).
     *
     * @return array{DocxImage, float, float}|null
     */
    private function layerPicture(Node $node, ComputedStyle $style, float $space, Visit $at): ?array
    {
        if (!self::isPicture($node)) {
            return null;
        }
        $outer = $this->outerWidth($node, $style, new Frame(null, $this->stack[0]->style, $space, $space), $at);
        $decor = $style->padding->horizontal() + $style->borderWidth->horizontal();
        $decorV = $style->padding->vertical() + $style->borderWidth->vertical();
        if ($node instanceof QrCode) {
            // The mapper takes the QR code's padding and border off its side itself.
            $blocks = $this->images->qr($node, $style, new Frame($node, $style, $outer, max(0.0, $outer - $decor)), $at->path());
        } else {
            // Widths and heights are border-box sizes; the mapper sizes the picture inside them.
            $inner = $style->height instanceof Length
                ? new ComputedStyle(...[...get_object_vars($style), 'height' => pt(max(0.0, $style->height->points - $decorV))])
                : $style;
            $content = max(0.0, $outer - $decor);
            $frame = new Frame($node, $inner, $content, $content);
            $blocks = $node instanceof Image
                ? $this->images->image($node, $inner, $frame, $at->path())
                : ($node instanceof Svg ? $this->images->svg($node, $inner, $frame, $at->path()) : []);
        }
        $image = $blocks[0] ?? null;
        if (!$image instanceof DocxImage) {
            return null;
        }
        if (($style->borderWidth->horizontal() + $style->borderWidth->vertical() > 0.0 && !$style->borderColor->isTransparent())
            || Units::fill($style->background) !== null) {
            $this->fidelity->dropped('border or background on a layer picture', $at->path());
        }

        return [$image, $image->widthEmu / 12700 + $decor, $image->heightEmu / 12700 + $decorV];
    }

    /** Where a box $boxW wide sits in a line $space wide from $left, by the node's align-self. */
    private static function aligned(ComputedStyle $style, float $left, float $space, float $boxW): float
    {
        return match ($style->alignSelf) {
            Align::Center => $left + ($space - $boxW) / 2,
            Align::End => $left + $space - $boxW,
            default => $left,
        };
    }

    /** Floats $image on the page with its border box at $x, $y. */
    private function float(DocxImage $image, ComputedStyle $style, float $x, float $y, bool $behind): void
    {
        $x += $style->padding->left->points + $style->borderWidth->left->points;
        $y += $style->padding->top->points + $style->borderWidth->top->points;
        $this->floats[] = new DocxImage($image->binary, $image->format, $image->widthEmu, $image->heightEmu, $image->altText, position: new ImagePosition(
            horizontalFrom: HorizontalAnchor::Page,
            xEmu: Units::emu($x),
            verticalFrom: VerticalAnchor::Page,
            yEmu: Units::emu($y),
            behindText: $behind,
            zOrder: count($this->floats),
        ));
    }

    private static function isPicture(Node $node): bool
    {
        return $node instanceof Image || $node instanceof QrCode || $node instanceof Svg;
    }

    private static function resolve(Length|Percent $v, float $basis): float
    {
        return $v instanceof Percent ? $v->resolve($basis) : $v->points;
    }
}
