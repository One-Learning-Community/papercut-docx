<?php

declare(strict_types=1);

namespace Papercut\Docx\Map;

use Papercut\Text\{FontSet, TrueTypeTables};

/**
 * A font's natural line height as a share of its size (hhea ascender - descender + lineGap over
 * unitsPerEm). Word, LibreOffice and Google Docs all take "single" spacing from it, so a Papercut
 * line height L becomes the multiple L / natural, which every reader can honour (Google Docs has no exact rule).
 */
final class LineMetrics
{
    private const float FALLBACK = 1.2;

    /** @var array<string, float> by lowercased family */
    private array $ratios = [];

    public function __construct(private readonly FontSet $fonts)
    {
    }

    public function natural(string $family): float
    {
        $key = strtolower($family);
        if (isset($this->ratios[$key])) {
            return $this->ratios[$key];
        }
        $ratio = self::FALLBACK;
        foreach ($this->fonts->effectiveFaces() as $face) {
            if (strtolower($face->family) === $key) {
                $ratio = self::read($face->path) ?? self::FALLBACK;
                break;
            }
        }

        return $this->ratios[$key] = $ratio;
    }

    private static function read(string $path): ?float
    {
        $tables = TrueTypeTables::open($path);
        $head = $tables?->table('head');
        $hhea = $tables?->table('hhea');
        if ($head === null || $hhea === null || strlen($head) < 20 || strlen($hhea) < 10) {
            return null;
        }
        $u = unpack('n', $head, 18);
        $upm = $u === false ? 0 : (int) $u[1];
        $h = unpack('nasc/ndesc/ngap', $hhea, 4);
        if ($upm <= 0 || $h === false) {
            return null;
        }
        $signed = static fn (int $v): int => $v >= 0x8000 ? $v - 0x10000 : $v;

        $ratio = ($signed((int) $h['asc']) - $signed((int) $h['desc']) + $signed((int) $h['gap'])) / $upm;

        // A malformed font can claim no line height at all; line spacing divides by it.
        return $ratio > 0.0 ? $ratio : null;
    }
}
