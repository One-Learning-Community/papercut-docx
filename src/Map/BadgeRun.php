<?php

declare(strict_types=1);

namespace Papercut\Docx\Map;

use Dskripchenko\PhpDocx\Element\{BlockElement, Hyperlink, InlineElement, Paragraph, Run};
use Dskripchenko\PhpDocx\Style\RunStyle;
use Papercut\Docx\Package\DocxPatcher;
use Papercut\Style\{BorderStyle, ComputedStyle};

/**
 * The one place a badge becomes Word text: a shaded run with a run border, two no-break spaces of padding on each
 * side, and no-break spaces in place of the label's own spaces so it never wraps. Word has no inline box, so the
 * corners stay square. A badge with no border of its own gets a border in its fill colour, which gives it a little
 * height in Word and LibreOffice; Google Docs ignores run borders and shows the shading alone.
 *
 * @internal Part of Papercut's engine, not its public API. It may change in any release.
 */
final class BadgeRun
{
    public const string NBSP = "\u{00A0}";
    /** No-break spaces of padding on each side of the label. */
    private const int PAD = 2;
    /** Width (eighths of a point) of the border drawn in the fill colour. */
    private const int FILL_BORDER = 4;
    private const float EPSILON = 0.01;

    /** The label with its spaces as no-break spaces, and the padding around it. */
    public static function text(string $label): string
    {
        $pad = str_repeat(self::NBSP, self::PAD);
        $label = trim(preg_replace('/[ \t\n\r\f]+/', ' ', $label) ?? $label);

        return $pad . str_replace(' ', self::NBSP, $label) . $pad;
    }

    /** The border's width in eighths of a point when every side has the same one, as a run border must; else null. */
    public static function evenBorder(ComputedStyle $i): ?int
    {
        $w = $i->borderWidth;
        $top = $w->top->points;
        if ($top <= 0.0 || abs($w->right->points - $top) > self::EPSILON || abs($w->bottom->points - $top) > self::EPSILON || abs($w->left->points - $top) > self::EPSILON) {
            return null;
        }

        return max(2, (int) round($top * 8));
    }

    /** Whether a run border can draw the style's border: it has none, or the same one on every side. */
    public static function drawable(ComputedStyle $i): bool
    {
        return self::widestBorder($i) === null || self::evenBorder($i) !== null;
    }

    /** The widest side's width in eighths of a point, or null when no side has a border. */
    public static function widestBorder(ComputedStyle $i): ?int
    {
        $w = max($i->borderWidth->top->points, $i->borderWidth->right->points, $i->borderWidth->bottom->points, $i->borderWidth->left->points);

        return $w > 0.0 ? max(2, (int) round($w * 8)) : null;
    }

    /**
     * The run style of a badge in $style's fill and border, from the text's own $run style.
     *
     * @param ?int $border the border's width in eighths of a point, null for none
     * @param bool $keep leave a highlight the run already has (a mark inside a pill) as it is
     */
    public static function style(RunStyle $run, ComputedStyle $style, ?int $border, bool $keep = false): RunStyle
    {
        if ($keep && $run->highlight !== null) {
            return $run;
        }
        $fill = Units::fill($style->background);
        if ($border === null) {
            $mark = $fill === null ? null : DocxPatcher::boxed($fill, self::FILL_BORDER, 'single', $fill);
        } else {
            $mark = DocxPatcher::boxed($fill, $border, $style->borderStyle === BorderStyle::Dashed ? 'dashed' : 'single', Units::hex($style->borderColor));
        }

        return $mark === null ? $run : new RunStyle(...[...get_object_vars($run), 'highlight' => $mark]);
    }

    /** One run as a badge. */
    public static function run(string $label, RunStyle $run, ComputedStyle $style, ?int $border): Run
    {
        return new Run(self::text($label), self::style($run, $style, $border));
    }

    /**
     * Runs (and links of runs) as one badge: every run styled, the label's spaces no-break, the padding in the first
     * and last run so a link around the badge covers it whole.
     *
     * @param list<InlineElement> $children
     * @return list<InlineElement>
     */
    public static function runs(array $children, ComputedStyle $style, ?int $border, bool $pad = true): array
    {
        $flat = [];
        foreach ($children as $c) {
            if ($c instanceof Hyperlink) {
                foreach ($c->children as $r) {
                    $flat[] = $r;
                }
            } else {
                $flat[] = $c;
            }
        }
        $last = count($flat) - 1;
        $at = -1;
        $map = static function (InlineElement $c) use (&$at, $last, $style, $border, $pad): InlineElement {
            $at++;
            if (!$c instanceof Run) {
                return $c;
            }
            $text = str_replace(' ', self::NBSP, $c->text);
            if (!$pad) {
                // A plain highlight: shading alone, as in the PDF.
                $fill = Units::fill($style->background);
                $run = $border === null && $fill !== null && $c->style->highlight === null
                    ? new RunStyle(...[...get_object_vars($c->style), 'highlight' => DocxPatcher::shading($fill)])
                    : self::style($c->style, $style, $border, true);

                return new Run($c->text, $run);
            }
            $space = str_repeat(self::NBSP, self::PAD);

            return new Run(($at === 0 ? $space : '') . $text . ($at === $last ? $space : ''), self::style($c->style, $style, $border, true));
        };
        $out = [];
        foreach ($children as $c) {
            if ($c instanceof Hyperlink) {
                $c = new Hyperlink($c->href, array_map($map, $c->children), $c->anchor);
            } else {
                $c = $map($c);
            }
            $out[] = $c;
        }

        return $out;
    }

    /**
     * Whether blocks are one paragraph of plain text runs (links included), which a badge run can hold.
     *
     * @param list<BlockElement> $blocks
     */
    public static function textOnly(array $blocks): bool
    {
        $p = $blocks[0] ?? null;

        return count($blocks) === 1 && $p instanceof Paragraph && $p->children !== [] && self::plain($p->children);
    }

    /** @param list<InlineElement> $children */
    private static function plain(array $children): bool
    {
        foreach ($children as $c) {
            if ($c instanceof Hyperlink ? !self::plain($c->children) : (!$c instanceof Run || str_contains($c->text, "\n"))) {
                return false;
            }
        }

        return true;
    }
}
