<?php

declare(strict_types=1);

namespace Papercut\Docx\Map;

use Papercut\Style\ComputedStyle;
use Papercut\Value\Color;

/**
 * Opacity, which the PDF applies to each thing a faded node draws: Word has none, so each colour is blended
 * onto what lies beneath it (the nearest fill, else white) and written opaque.
 */
final class Fade
{
    /**
     * The style at $opacity over $backdrop: its fill onto the backdrop, its text and border onto that fill.
     * The opacity becomes the style's, for pictures to take as is.
     */
    public static function style(ComputedStyle $s, float $opacity, Color $backdrop): ComputedStyle
    {
        $background = self::over($s->background, $opacity, $backdrop);
        $inner = $background ?? $backdrop;

        return new ComputedStyle(...[
            ...get_object_vars($s),
            'color' => self::over($s->color, $opacity, $inner) ?? $s->color,
            'background' => $background,
            'borderColor' => self::over($s->borderColor, $opacity, $inner) ?? $s->borderColor,
            'opacity' => $opacity,
        ]);
    }

    /** What a fill leaves beneath its contents: the fill laid over $backdrop, or the backdrop when there is none. */
    public static function under(?Color $fill, Color $backdrop): Color
    {
        return $fill === null || $fill->isTransparent() ? $backdrop : self::blend($fill, 1.0, $backdrop);
    }

    /** $c at $opacity (times its own alpha) laid over $backdrop, as an opaque colour; null stays null. */
    public static function over(?Color $c, float $opacity, Color $backdrop): ?Color
    {
        return $c === null || $opacity >= 1.0 ? $c : self::blend($c, $opacity, $backdrop);
    }

    private static function blend(Color $c, float $opacity, Color $backdrop): Color
    {
        $a = max(0.0, min(1.0, $c->alpha * $opacity));
        $mix = static fn (int $top, int $under): int => max(0, min(255, (int) round($top * $a + $under * (1 - $a))));

        return Color::rgb($mix($c->r, $backdrop->r), $mix($c->g, $backdrop->g), $mix($c->b, $backdrop->b));
    }
}
