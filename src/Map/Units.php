<?php

declare(strict_types=1);

namespace Papercut\Docx\Map;

use Papercut\Value\Color;

/** Point conversions for WordprocessingML. */
final class Units
{
    /** Twentieths of a point. */
    public static function tw(float $points): int
    {
        return (int) round($points * 20);
    }

    /** English Metric Units: 12700 per point. */
    public static function emu(float $points): int
    {
        return (int) round($points * 12700);
    }

    /** Hex without '#', alpha blended onto white (Word colours have no alpha). */
    public static function hex(Color $c): string
    {
        $a = max(0.0, min(1.0, $c->alpha));
        $mix = static fn (int $v): int => (int) round($v * $a + 255 * (1 - $a));

        return sprintf('%02x%02x%02x', $mix($c->r), $mix($c->g), $mix($c->b));
    }

    /** Null for no colour or a fully transparent one. */
    public static function fill(?Color $c): ?string
    {
        return $c === null || $c->isTransparent() ? null : self::hex($c);
    }
}
