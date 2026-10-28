<?php

declare(strict_types=1);

namespace Papercut\Docx\Walk;

use Dskripchenko\PhpDocx\Element\{BlockElement, TableCell, TableRow};
use Papercut\Layout\FlexSlot;
use Papercut\Node;
use Papercut\Style\ComputedStyle;
use Papercut\Value\Color;

/** One open node during the walk, collecting what its children produced. */
final class Frame
{
    /** @var list<array{blocks: list<BlockElement>, style: ?ComputedStyle, index: int, boxed: bool}> one entry per child; boxed: the blocks are this child's own decorated box */
    public array $items = [];
    /** @var list<TableRow> (Table frames) */
    public array $rows = [];
    /** @var list<TableCell> (TableRow frames) */
    public array $cells = [];
    /** @var list<list<FlexSlot>> flex lines (row frames) */
    public array $lines = [];
    /** @var array<int, FlexSlot> slot by child index (row frames) */
    public array $slots = [];
    /** @var list<float> column widths in pt (Table frames) */
    public array $columns = [];
    /** Next grid column (TableRow frames). */
    public int $column = 0;
    /** Whether this table row is a header row. */
    public bool $header = false;
    /** What a faded child lies on: the nearest fill, else white. */
    public Color $backdrop;

    public function __construct(
        public readonly ?Node $node,
        public readonly ?ComputedStyle $style,
        /** Border-box width in pt. */
        public readonly float $width,
        /** Content-box width in pt. */
        public readonly float $content,
        public readonly bool $isRow = false,
    ) {
        $this->backdrop = Color::white();
    }
}
