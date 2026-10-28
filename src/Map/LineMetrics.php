<?php

declare(strict_types=1);

namespace Papercut\Docx\Map;

use Papercut\Text\FontSet;

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
        $data = @file_get_contents($path);
        if ($data === false) {
            return null;
        }
        $at = static function (string $format, int $offset, int $size) use ($data): ?array {
            if ($offset < 0 || $offset + $size > strlen($data)) {
                return null;
            }
            $v = unpack($format, $data, $offset);

            return $v === false ? null : $v;
        };
        $count = $at('n', 4, 2)[1] ?? 0;
        $tables = [];
        for ($i = 0; $i < $count; $i++) {
            $rec = $at('a4tag/Nsum/Noffset/Nlength', 12 + 16 * $i, 16);
            if ($rec === null) {
                return null;
            }
            $tables[(string) $rec['tag']] = (int) $rec['offset'];
        }
        if (!isset($tables['head'], $tables['hhea'])) {
            return null;
        }
        $upm = (int) ($at('n', $tables['head'] + 18, 2)[1] ?? 0);
        $h = $at('nasc/ndesc/ngap', $tables['hhea'] + 4, 6);
        if ($upm <= 0 || $h === null) {
            return null;
        }
        $signed = static fn (int $v): int => $v >= 0x8000 ? $v - 0x10000 : $v;

        $ratio = ($signed((int) $h['asc']) - $signed((int) $h['desc']) + $signed((int) $h['gap'])) / $upm;

        // A malformed font can claim no line height at all; line spacing divides by it.
        return $ratio > 0.0 ? $ratio : null;
    }
}
