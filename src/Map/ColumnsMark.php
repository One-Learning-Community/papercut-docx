<?php

declare(strict_types=1);

namespace Quoin\Docx\Map;

use Dskripchenko\PhpDocx\Element\BlockElement;

/**
 * Marks in a body where a column section starts or ends. DocxExporter::sections() splits the body at the marks
 * and never hands one to php-docx.
 *
 * @internal Engine detail, not public API. It may change in any release.
 */
final readonly class ColumnsMark implements BlockElement
{
    public function __construct(
        /** The `w:cols` XML for the section that starts here; null ends the column section. */
        public ?string $cols,
    ) {
    }
}
