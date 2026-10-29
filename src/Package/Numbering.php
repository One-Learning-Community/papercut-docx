<?php

declare(strict_types=1);

namespace Papercut\Docx\Package;

use Papercut\Style\ListMarker;

/** The numbering of one export: a `w:num` per list, an abstract numbering per marker used. Rendered as word/numbering.xml. */
final class Numbering
{
    public const string STYLE_PREFIX = 'papercut-num-';

    private const int LEVELS = 9;

    private const string NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /** @var array<string, int> marker name => abstractNumId, in first-use order */
    private array $abstracts = [];

    /** @var list<array{ListMarker, int}> numId - 1 => marker, start */
    private array $nums = [];

    /** Registers a list of its own (never shared with another) and returns its numId. */
    public function instance(ListMarker $marker, int $start): int
    {
        if ($marker === ListMarker::None) {
            throw new \InvalidArgumentException('ListMarker::None has no numbering');
        }
        $this->abstracts[$marker->name] ??= count($this->abstracts);
        $this->nums[] = [$marker, $start];

        return count($this->nums);
    }

    public function count(): int
    {
        return count($this->nums);
    }

    public function isEmpty(): bool
    {
        return $this->nums === [];
    }

    /** The paragraph style id ListMapper sets; DocxPatcher turns it into `w:numPr`. */
    public static function marker(int $numId, int $ilvl, ?int $headingLevel): string
    {
        if ($headingLevel !== null && ($headingLevel < 1 || $headingLevel > 6)) {
            throw new \InvalidArgumentException('Heading level must be 1-6, got ' . $headingLevel);
        }

        return self::STYLE_PREFIX . $numId . '-' . $ilvl . ($headingLevel === null ? '' : '-h' . $headingLevel);
    }

    public function xml(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><w:numbering xmlns:w="' . self::NS . '">';
        foreach ($this->abstracts as $name => $id) {
            $marker = constant(ListMarker::class . '::' . $name);
            $xml .= '<w:abstractNum w:abstractNumId="' . $id . '"><w:multiLevelType w:val="multilevel"/>';
            for ($l = 0; $l < self::LEVELS; $l++) {
                [$fmt, $text] = self::format($marker, $l);
                $xml .= '<w:lvl w:ilvl="' . $l . '"><w:start w:val="1"/><w:numFmt w:val="' . $fmt . '"/><w:suff w:val="tab"/>'
                    . '<w:lvlText w:val="' . $text . '"/><w:lvlJc w:val="left"/>'
                    . '<w:pPr><w:ind w:left="' . (360 * ($l + 1)) . '" w:hanging="360"/></w:pPr></w:lvl>';
            }
            $xml .= '</w:abstractNum>';
        }
        foreach ($this->nums as $i => [$marker, $start]) {
            $xml .= '<w:num w:numId="' . ($i + 1) . '"><w:abstractNumId w:val="' . $this->abstracts[$marker->name] . '"/>';
            for ($l = 0; $l < self::LEVELS; $l++) {
                $xml .= '<w:lvlOverride w:ilvl="' . $l . '"><w:startOverride w:val="' . $start . '"/></w:lvlOverride>';
            }
            $xml .= '</w:num>';
        }

        return $xml . '</w:numbering>';
    }

    /** @return array{string, string} numFmt, lvlText */
    private static function format(ListMarker $marker, int $level): array
    {
        $n = '%' . ($level + 1) . '.';

        return match ($marker) {
            ListMarker::Decimal => ['decimal', $n],
            ListMarker::LowerAlpha => ['lowerLetter', $n],
            ListMarker::UpperAlpha => ['upperLetter', $n],
            ListMarker::LowerRoman => ['lowerRoman', $n],
            ListMarker::UpperRoman => ['upperRoman', $n],
            ListMarker::Disc => ['bullet', '•'],
            ListMarker::Circle => ['bullet', '◦'],
            ListMarker::Square => ['bullet', '▪'],
            ListMarker::None => throw new \LogicException('ListMarker::None has no numbering'),
        };
    }
}
