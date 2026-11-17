<?php

declare(strict_types=1);

namespace Papercut\Docx\Map;

use Papercut\Text\{FontSet, TrueTypeTables};

/**
 * A font's vertical metrics as shares of its size. natural() is the line height (hhea ascender - descender
 * + lineGap over unitsPerEm): Word, LibreOffice and Google Docs all take "single" spacing from it, so a
 * Papercut line height L becomes the multiple L / natural, which every reader can honour (Google Docs has
 * no exact rule). descent() (hhea) and capHeight() (OS/2) place a line's capitals, which choice markers centre on.
 *
 * @internal Part of Papercut's engine, not its public API. It may change in any release.
 */
final class LineMetrics
{
    private const float FALLBACK = 1.2;
    private const float FALLBACK_DESCENT = 0.2;
    private const float FALLBACK_CAP_HEIGHT = 0.7;

    /** @var array<string, array{natural: float, descent: float, capHeight: float}> by lowercased family */
    private array $metrics = [];

    public function __construct(private readonly FontSet $fonts)
    {
    }

    public function natural(string $family): float
    {
        return $this->of($family)['natural'];
    }

    public function descent(string $family): float
    {
        return $this->of($family)['descent'];
    }

    public function capHeight(string $family): float
    {
        return $this->of($family)['capHeight'];
    }

    /** @return array{natural: float, descent: float, capHeight: float} */
    private function of(string $family): array
    {
        $key = strtolower($family);
        if (isset($this->metrics[$key])) {
            return $this->metrics[$key];
        }
        $metrics = null;
        foreach ($this->fonts->effectiveFaces() as $face) {
            if (strtolower($face->family) === $key) {
                $metrics = self::read($face->path);
                break;
            }
        }

        return $this->metrics[$key] = $metrics ?? ['natural' => self::FALLBACK, 'descent' => self::FALLBACK_DESCENT, 'capHeight' => self::FALLBACK_CAP_HEIGHT];
    }

    /** @return ?array{natural: float, descent: float, capHeight: float} */
    private static function read(string $path): ?array
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
        if (!($ratio > 0.0)) {
            return null;
        }
        $descent = -$signed((int) $h['desc']) / $upm;
        // sCapHeight arrived in OS/2 version 2, at byte 88.
        $os2 = $tables->table('OS/2');
        $version = $os2 !== null && strlen($os2) >= 90 ? unpack('nversion', $os2, 0) : false;
        $cap = $version !== false && $version['version'] >= 2 ? unpack('ncap', (string) $os2, 88) : false;
        // A font that leaves sCapHeight at 0 gets the fallback, as does a font without the field.
        $capHeight = $cap !== false ? $signed((int) $cap['cap']) / $upm : 0.0;

        return [
            'natural' => $ratio,
            'descent' => $descent > 0.0 ? $descent : self::FALLBACK_DESCENT,
            'capHeight' => $capHeight > 0.0 ? $capHeight : self::FALLBACK_CAP_HEIGHT,
        ];
    }
}
