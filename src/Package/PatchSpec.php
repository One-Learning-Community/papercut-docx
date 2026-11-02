<?php

declare(strict_types=1);

namespace Papercut\Docx\Package;

use Papercut\Text\FontInfo;

/**
 * What DocxPatcher writes into php-docx's output.
 *
 * @internal Part of Papercut's engine, not its public API. It may change in any release.
 */
final readonly class PatchSpec
{
    /** @param array<string, ?FontInfo> $fonts */
    public function __construct(
        public string $fontFamily,
        public int $fontSizeHalfPoints,
        public ?string $lang,
        /** Every ZIP entry's time, read as UTC. */
        public \DateTimeInterface $time,
        /** Swap the left and right margins on even pages (`w:mirrorMargins`). */
        public bool $mirrorMargins = false,
        /** The lists of the export; DocxPatcher writes word/numbering.xml and each list paragraph's `w:numPr`. */
        public ?Numbering $numbering = null,
        /** The fonts the runs name, by written name, for word/fontTable.xml; null where the FontSet does not know the family. */
        public array $fonts = [],
        /** @var list<TocField> the tables of contents, in the order of their marker paragraphs */
        public array $tocs = [],
        /** @var array<int, string> `w:cols` XML by section index, in document order; DocxPatcher writes it after the section's `w:pgMar` */
        public array $columns = [],
        /** How many column break runs (Flow::columnBreak()) the body holds; DocxPatcher turns exactly that many into breaks. */
        public int $columnBreaks = 0,
    ) {
    }
}
