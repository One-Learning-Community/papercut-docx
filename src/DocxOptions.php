<?php

declare(strict_types=1);

namespace Papercut\Docx;

use Papercut\Export\{ExportOptions, FidelityReport};
use Papercut\Style\Theme;
use Papercut\Text\FontSet;

/**
 * Options for Word export.
 *
 * `$tocPageNumbers` false skips the page layout that fills in a table of contents' page numbers, which costs about as
 * much as laying out the PDF. The field then lists its entries without numbers until the reader updates it in Word.
 *
 * `$rsvgConvert` is the path to librsvg's rsvg-convert, which then draws SVG pictures ahead of Imagick. How well
 * Imagick draws SVG depends on how it was built; rsvg-convert draws them properly whatever the build. The path must
 * be absolute. Should it fail on one SVG, Imagick draws that one instead.
 */
final readonly class DocxOptions extends ExportOptions
{
    /** @param (\Closure(string, ?FidelityReport=): void)|null $onWarning */
    public function __construct(
        ?Theme $theme = null,
        ?FontSet $fonts = null,
        ?\Closure $onWarning = null,
        bool $strict = false,
        public bool $tocPageNumbers = true,
        public ?string $rsvgConvert = null,
    ) {
        parent::__construct($theme, $fonts, $onWarning, $strict);
    }

    public static function from(ExportOptions $o): self
    {
        return $o instanceof self ? $o : new self($o->theme(), $o->fonts(), $o->onWarning(), $o->strict());
    }
}
