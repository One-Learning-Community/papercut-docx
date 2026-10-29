<?php

declare(strict_types=1);

namespace Papercut\Docx\Map;

use Papercut\Text\{FontInfo, FontSet};

/** The font names the export writes into runs, and what the font table needs to say about each. */
final class WordFonts
{
    /** @var array<string, ?FontInfo> written name => what the font file says, null for a family the FontSet does not know */
    private array $written = [];

    public function __construct(private readonly FontSet $fonts)
    {
    }

    /** The name Word gets for the family; remembers it for the font table. */
    public function name(string $family): string
    {
        $name = $this->fonts->wordName($family);
        if (!array_key_exists($name, $this->written)) {
            $this->written[$name] = $this->fonts->info($family);
        }

        return $name;
    }

    /** @return array<string, ?FontInfo> */
    public function written(): array
    {
        return $this->written;
    }
}
