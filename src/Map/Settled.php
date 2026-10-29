<?php

declare(strict_types=1);

namespace Papercut\Docx\Map;

use Dskripchenko\PhpDocx\Element\BlockElement;

/**
 * The blocks a list item has already indented, kept for one export. A copy of a block (spacing, pagination)
 * is as settled as the original, so whatever copies blocks carries the mark across.
 */
final class Settled
{
    /** @var \WeakMap<BlockElement, true> */
    private \WeakMap $blocks;

    public function __construct()
    {
        $this->blocks = new \WeakMap();
    }

    public function has(BlockElement $b): bool
    {
        return isset($this->blocks[$b]);
    }

    public function mark(BlockElement $b): void
    {
        $this->blocks[$b] = true;
    }

    /** Marks $to settled when $from is. */
    public function carry(BlockElement $from, BlockElement $to): void
    {
        if ($this->has($from)) {
            $this->mark($to);
        }
    }
}
