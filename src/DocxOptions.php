<?php

declare(strict_types=1);

namespace Papercut\Docx;

use Papercut\Export\ExportOptions;

/** Options for Word export. It has none of its own yet; the type marks what DocxExporter takes. */
final readonly class DocxOptions extends ExportOptions
{
    public static function from(ExportOptions $o): self
    {
        return $o instanceof self ? $o : new self($o->theme(), $o->fonts(), $o->onWarning(), $o->strict());
    }
}
