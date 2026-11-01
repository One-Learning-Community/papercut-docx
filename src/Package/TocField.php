<?php

declare(strict_types=1);

namespace Papercut\Docx\Package;

/** One table of contents for DocxPatcher: the field's levels and the entry paragraphs it writes in place of the marker. */
final readonly class TocField
{
    /** The text of the marker paragraph DocxPatcher replaces, followed by the table of contents' number. */
    public const string MARKER = 'papercut-toc-marker-';

    /** @param list<TocFieldEntry> $entries */
    public function __construct(
        public int $from,
        public int $to,
        /** Where the right tab stop sits, in twips from the left margin. */
        public int $tabTwips,
        public bool $leaders,
        public array $entries,
    ) {
    }
}
