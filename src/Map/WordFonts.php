<?php

declare(strict_types=1);

namespace Quoin\Docx\Map;

use Quoin\Text\{FontInfo, FontSet};

/**
 * The font names the export writes into runs, and what the font table needs to say about each.
 *
 * @internal Engine detail, not public API. It may change in any release.
 */
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

    /** A font the export names itself, not one from the FontSet (the choice-marker symbol font), with what the font table should say. */
    public function fixed(string $name, FontInfo $info): string
    {
        $this->written[$name] ??= $info;

        return $name;
    }

    /** @return array<string, ?FontInfo> */
    public function written(): array
    {
        return $this->written;
    }
}
