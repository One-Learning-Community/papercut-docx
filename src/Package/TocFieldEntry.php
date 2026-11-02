<?php

declare(strict_types=1);

namespace Papercut\Docx\Package;

/**
 * One line of a table of contents field: a heading's text, where it links and its cached page number.
 *
 * @internal Part of Papercut's engine, not its public API. It may change in any release.
 */
final readonly class TocFieldEntry
{
    public function __construct(
        public string $text,
        /** The heading's bookmark. */
        public string $bookmark,
        public ?int $page,
        public int $indentTwips,
        public int $beforeTwips,
        public int $afterTwips,
        /** Line spacing as a multiple, in 240ths of a line. */
        public int $lineTwips,
        /** The `w:rPr` element every run of the entry carries. */
        public string $runProperties,
    ) {
    }
}
