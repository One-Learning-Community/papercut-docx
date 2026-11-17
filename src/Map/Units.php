<?php

declare(strict_types=1);

namespace Quoin\Docx\Map;

use Quoin\Value\Color;

/**
 * Point conversions for WordprocessingML.
 *
 * @internal Engine detail, not public API. It may change in any release.
 */
final class Units
{
    /** The widest length OOXML takes in twentieths of a point (22 inches); lengths beyond it, either way, are clamped to it. */
    public const int MAX_TWIPS = 31680;

    /** The largest `w:sz` Word takes, in half points (1638 pt). */
    public const int MAX_HALF_POINTS = 3276;

    /** The widest border `w:sz` takes, in eighths of a point (12 pt). */
    public const int MAX_BORDER_EIGHTHS = 96;

    /** The most columns Word shows in a table. */
    public const int MAX_TABLE_COLUMNS = 63;

    /** Fidelity notes for a value the clamps above cut: reported where the style is set. */
    public const string FONT_SIZE_CLAMPED = 'font size larger than Word allows (1638pt), clamped';
    public const string BORDER_CLAMPED = 'border wider than Word allows (12pt), clamped';

    /** Twentieths of a point, clamped to what OOXML allows. */
    public static function tw(float $points): int
    {
        return max(-self::MAX_TWIPS, min(self::MAX_TWIPS, (int) round(max(-1e6, min(1e6, $points)) * 20)));
    }

    /** A twip total (a sum of clamped values) clamped again to what OOXML allows. */
    public static function clamp(int $twips): int
    {
        return max(-self::MAX_TWIPS, min(self::MAX_TWIPS, $twips));
    }

    /** A border width in eighths of a point for `w:sz`: at least 2 (a quarter point), at most what Word takes. */
    public static function eighths(float $points): int
    {
        return max(2, min(self::MAX_BORDER_EIGHTHS, (int) round(max(0.0, min(1e6, $points)) * 8)));
    }

    /** Half points for `w:sz`, between 1 and what Word takes. */
    public static function halfPoints(float $points): int
    {
        return max(1, min(self::MAX_HALF_POINTS, (int) round(max(0.0, min(1e6, $points)) * 2)));
    }

    /** English Metric Units: 12700 per point. */
    public static function emu(float $points): int
    {
        return (int) round($points * 12700);
    }

    /** Hex without '#', alpha blended onto white (Word colors have no alpha). */
    public static function hex(Color $c): string
    {
        $a = max(0.0, min(1.0, $c->alpha));
        $mix = static fn (int $v): int => (int) round($v * $a + 255 * (1 - $a));

        return sprintf('%02x%02x%02x', $mix($c->r), $mix($c->g), $mix($c->b));
    }

    /** Null for no color or a fully transparent one. */
    public static function fill(?Color $c): ?string
    {
        return $c === null || $c->isTransparent() ? null : self::hex($c);
    }
}
