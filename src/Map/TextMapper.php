<?php

declare(strict_types=1);

namespace Papercut\Docx\Map;

use Dskripchenko\PhpDocx\Element\{Bookmark, Field, Hyperlink, InlineElement, Paragraph, Run};
use Dskripchenko\PhpDocx\Style\{Alignment, ParagraphStyle, RunStyle};
use Papercut\{Node, PageNumber, Text, View};
use Papercut\Docx\Package\DocxPatcher;
use Papercut\Exception\InvalidValue;
use Papercut\Export\Fidelity;
use Papercut\Paint\Link;
use Papercut\Style\{ComputedStyle, StyleResolver, TextAlign, Theme, WhiteSpace};
use Papercut\Text\{FontClass, FontInfo};
use Papercut\Tree\Visit;
use Papercut\Value\{Color, Length};

/**
 * Text, spans and page numbers as Word paragraphs and runs.
 *
 * @internal Part of Papercut's engine, not its public API. It may change in any release.
 */
final class TextMapper
{
    /** The whitespace that collapses outside pre-wrap; a no-break space is not part of it. */
    private const string SPACES = " \t\n\r\f";
    private const string MARKER_FONT = 'Segoe UI Symbol';
    /**
     * How far a marker's centre sits above the bottom of an exact line, as a share of its size. Measured in
     * Word for Mac with Segoe UI Symbol markers beside Arial at 10pt and 14pt, 0.75em to 2em markers.
     */
    private const float MARKER_DROP = 0.48;
    /** Half the ink height of ○ ◉ ☐ ▣ in Segoe UI Symbol (0.36em) and a little more: Word clips ink above an exact line. */
    private const float MARKER_INK_HALF = 0.37;
    /** Segoe UI Symbol's PANOSE number, family and pitch, for the font table, so a reader without it picks a close stand-in. */
    private const string MARKER_PANOSE = '020B0502040204020203';

    public function __construct(
        private readonly LineMetrics $metrics,
        private readonly Fidelity $fidelity,
        private readonly WordFonts $fonts,
        private ?Theme $theme = null,
        private readonly EquationMapper $equations = new EquationMapper(),
    ) {
    }

    /** The theme's resolver, which computes each distinct span style once; null until usePage(), and then every span computes its own. */
    private ?StyleResolver $styles = null;

    /** Maps spans with the page's theme, and its resolver, from here on. */
    public function usePage(Theme $theme, ?StyleResolver $styles = null): void
    {
        $this->theme = $theme;
        $this->styles = $styles;
    }

    /** @param ?string $blockHref the enclosing block link, applied to runs without a link of their own */
    /** @param ?Color $backdrop what faded text lies on (white when null) */
    /** @param ?string $bookmark a Word bookmark around the paragraph's content, for a table of contents entry to link to */
    public function paragraph(Text $t, ComputedStyle $s, Visit $at, ?string $blockHref = null, ?Color $backdrop = null, ?string $bookmark = null): Paragraph
    {
        $backdrop ??= Color::white();
        /** @var list<array{Run, ?string, bool, bool}> $runs run, link, whether it is a line break, whether its spaces collapse */
        $runs = [];
        // Whitespace collapses as the PDF's itemizer does it: a run of spaces, tabs and newlines is one space, even
        // across spans, and none is kept at the start of a line or before a break or the paragraph's end.
        $atStart = true;
        $endsSpace = false;
        foreach ($t->spanList() as $span) {
            // A span's own colours fade with the text; inherited ones already have.
            $own = ($this->theme ?? Theme::default())->spanStyle($span);
            $cs = $this->styles?->span($s, $own) ?? $s->child($own);
            $spanBg = Fade::over($own->background, $s->opacity, $backdrop);
            $faded = $own->color === null ? $cs : new ComputedStyle(...[...get_object_vars($cs), 'color' => Fade::over($cs->color, $s->opacity, $spanBg ?? $backdrop) ?? $cs->color]);
            $rs = $this->runStyle($faded);
            // Only a size the span sets itself: the text's own, and what it inherits, is noted where the style was set.
            if ($cs->fontSize > Units::MAX_HALF_POINTS / 2 && $cs->fontSize !== $s->fontSize) {
                $this->fidelity->approximated(Units::FONT_SIZE_CLAMPED, $at->path());
            }
            $bg = Units::fill($spanBg);
            if ($bg !== null) {
                // php-docx writes no run shading, but writes a highlight as given: DocxPatcher turns this one into w:shd.
                $rs = new RunStyle(...[...get_object_vars($rs), 'highlight' => DocxPatcher::shading($bg)]);
            }
            if ($span->math !== null) {
                // An inline equation: a marker run for DocxPatcher, in the span's size, colour and shading, with no whitespace of its own.
                $runs[] = [$this->equations->run($span->math, $faded, $bg), $span->href ?? $blockHref, false, false];
                $atStart = false;
                $endsSpace = false;
                continue;
            }
            if ($cs->isInlineBox() && !$span->lineBreak) {
                // An inline badge: Word has no inline box, so it is a shaded run with padding spaces.
                if (trim($span->text, self::SPACES) === '') {
                    continue;
                }
                $run = BadgeRun::run($span->text, $rs, new ComputedStyle(...[...get_object_vars($cs), 'background' => $spanBg]), BadgeRun::widestBorder($cs));
                if (max($cs->borderWidth->top->points, $cs->borderWidth->right->points, $cs->borderWidth->bottom->points, $cs->borderWidth->left->points) > Units::MAX_BORDER_EIGHTHS / 8) {
                    $this->fidelity->approximated(Units::BORDER_CLAMPED, $at->path());
                }
                if ($cs->radius > 0.0) {
                    $this->fidelity->approximated('badge in a line', $at->path());
                }
                // Spaces just inside the badge space it from its neighbours, as in the PDF.
                $space = new Run(' ', $this->runStyle($s));
                if (!$atStart && !$endsSpace && strspn($span->text, self::SPACES) > 0) {
                    $runs[] = [$space, $span->href ?? $blockHref, false, true];
                }
                $runs[] = [$run, $span->href ?? $blockHref, false, false];
                $atStart = false;
                $endsSpace = strspn(strrev($span->text), self::SPACES) > 0;
                if ($endsSpace) {
                    $runs[] = [$space, $span->href ?? $blockHref, false, true];
                }
                continue;
            }
            if ($span->lineBreak) {
                // A line break is a run of its own, so a blank line takes the height of the text around it.
                self::trimEnd($runs);
                $runs[] = [new Run("\n", $rs), $span->href ?? $blockHref, true, false];
                $atStart = true;
                $endsSpace = false;
                continue;
            }
            if ($cs->whiteSpace === WhiteSpace::PreWrap) {
                if ($span->text !== '') {
                    $runs[] = [new Run($span->text, $rs), $span->href ?? $blockHref, false, false];
                    $atStart = str_ends_with($span->text, "\n");
                    $endsSpace = false;
                }
                continue;
            }
            $text = preg_replace('/[' . self::SPACES . ']+/', ' ', $span->text) ?? $span->text;
            if (($atStart || $endsSpace) && str_starts_with($text, ' ')) {
                $text = substr($text, 1);
            }
            if ($text === '') {
                continue;
            }
            $runs[] = [new Run($text, $rs), $span->href ?? $blockHref, false, true];
            $atStart = false;
            $endsSpace = str_ends_with($text, ' ');
        }
        self::trimEnd($runs);
        $children = self::linked($runs);
        if ($s->minLines > 2) {
            // Word's widow control keeps two lines together, never more.
            $this->fidelity->approximated('min-lines ' . $s->minLines, $at->path());
        }
        if ($s->fontWeight !== 400 && $s->fontWeight !== 700) {
            $this->fidelity->approximated('font weight ' . $s->fontWeight, $at->path());
        }
        $level = $t->headingLevel();

        if ($bookmark !== null) {
            $children = [new Bookmark($bookmark, $children)];
        }

        return new Paragraph($children, $this->paragraphStyle($s), $level);
    }

    /**
     * Drops the space a line ends with, as the PDF does, before a break or at the paragraph's end.
     *
     * @param list<array{Run, ?string, bool, bool}> $runs
     */
    private static function trimEnd(array &$runs): void
    {
        $last = count($runs) - 1;
        if ($last < 0 || !$runs[$last][3] || !str_ends_with($runs[$last][0]->text, ' ')) {
            return;
        }
        $text = substr($runs[$last][0]->text, 0, -1);
        if ($text === '') {
            array_pop($runs);

            return;
        }
        $runs[$last][0] = new Run($text, $runs[$last][0]->style);
    }

    /**
     * Runs in Word order, each with its link. A line break between two runs of one link belongs to that link, and
     * neighbouring runs of one link share a single hyperlink element.
     *
     * @param list<array{Run, ?string, bool, bool}> $runs
     * @return list<InlineElement>
     */
    private static function linked(array $runs): array
    {
        $count = count($runs);
        $hrefs = array_map(static fn (array $r): ?string => $r[1], $runs);
        for ($i = 0; $i < $count; $i++) {
            if (!$runs[$i][2] || $hrefs[$i] !== null) {
                continue;
            }
            $end = $i;
            while ($end + 1 < $count && $runs[$end + 1][2] && $hrefs[$end + 1] === null) {
                $end++;
            }
            if ($i > 0 && $end + 1 < $count && $hrefs[$i - 1] === $hrefs[$end + 1]) {
                for ($k = $i; $k <= $end; $k++) {
                    $hrefs[$k] = $hrefs[$i - 1];
                }
            }
            $i = $end;
        }
        /** @var list<array{?string, list<Run>}> $groups */
        $groups = [];
        foreach ($runs as $i => [$run]) {
            $href = $hrefs[$i];
            $last = count($groups) - 1;
            if ($last >= 0 && $groups[$last][0] === $href) {
                $groups[$last][1][] = $run;
            } else {
                $groups[] = [$href, [$run]];
            }
        }
        $children = [];
        foreach ($groups as [$href, $members]) {
            if ($href === null) {
                array_push($children, ...$members);
            } else {
                $children[] = self::hyperlink($href, $members);
            }
        }

        return $children;
    }

    public function pageNumber(PageNumber $n, ComputedStyle $s): Paragraph
    {
        $rs = $this->runStyle($s);
        $children = [];
        foreach (preg_split('/(\{page\}|\{total\})/', $n->format(), -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            $children[] = match ($part) {
                '{page}' => new Field('PAGE', $rs),
                '{total}' => new Field('NUMPAGES', $rs),
                default => new Run($part, $rs),
            };
        }

        return new Paragraph($children, $this->paragraphStyle($s));
    }

    public function runStyle(ComputedStyle $s): RunStyle
    {
        return new RunStyle(
            sizeHalfPoints: Units::halfPoints($s->fontSize),
            color: Units::hex($s->color),
            fontFamily: $this->fonts->name($s->fontFamily),
            bold: $s->fontWeight >= 600,
            italic: $s->italic,
            underline: $s->underline,
            strikethrough: $s->strike,
            letterSpacingTwips: $s->letterSpacing !== 0.0 ? Units::tw($s->letterSpacing) : null,
        );
    }

    public function paragraphStyle(ComputedStyle $s): ParagraphStyle
    {
        return new ParagraphStyle(
            alignment: match ($s->textAlign) {
                TextAlign::Left => Alignment::Start,
                TextAlign::Right => Alignment::End,
                TextAlign::Center => Alignment::Center,
                TextAlign::Justify => $s->whiteSpace === WhiteSpace::PreWrap ? Alignment::Start : Alignment::Justify,
            },
            // A multiple, never exact: Google Docs turns an exact height into a multiple of its default font's line.
            lineSpacingTwips: (int) round(240 * $s->lineHeight / $this->metrics->natural($s->fontFamily)),
            lineSpacingRule: 'auto',
            widowControl: $s->minLines >= 2 ? true : null,
        );
    }

    /**
     * A small fixed-size bordered box holding nothing but empty boxes (a checkbox or radio marker), as a
     * character: nested tables a few points tall don't survive Google Docs, and a character reads as a marker anywhere.
     */
    public static function glyph(Node $node, ComputedStyle $s): ?string
    {
        if (!$node instanceof View || !$s->width instanceof Length || !$s->height instanceof Length
            || $s->width->points > 2 * $s->fontSize || $s->height->points > 2 * $s->fontSize
            || $s->borderWidth->horizontal() <= 0.0) {
            return null;
        }
        // A coloured fill makes it a swatch, which a character would lose.
        $fill = Units::fill($s->background);
        if ($fill !== null && strtoupper($fill) !== 'FFFFFF') {
            return null;
        }
        $filled = false;
        foreach ($node->children() as $child) {
            if (!$child instanceof View || array_filter($child->children(), static fn ($c): bool => !$c instanceof View) !== []) {
                return null;
            }
            $filled = true;
        }
        // Round once the corners take more than a third of each side, not only at a full half: a theme's own radio
        // radius may not reach half of a box a variant enlarged, yet still draws round in the PDF and HTML. The
        // built-in checkbox (a sixth of its side) stays a square, even shrunk to half its size (a third).
        $round = $s->radius > min($s->width->points, $s->height->points) / 3;

        return $round ? ($filled ? '◉' : '○') : ($filled ? '▣' : '☐');
    }

    /**
     * The marker character in its cell, centred on the capitals of the label's first line, which sits in the
     * next cell with the same font, size and line height. The marker font is named so all four characters
     * come from one font at one size: Word ships Segoe UI Symbol on Windows and Mac, and a reader without it
     * falls back. Left to the text font, ○ came from it and ◉ ☐ ▣ from a bigger fallback font.
     *
     * Word honours neither a raised run nor centring here (an auto line grows around a raised glyph), so the
     * line is exact and its height places the glyph, whose centre Word sets a fixed share of its size above the
     * line's bottom. This is exact only when Word has the label's font: a font it stands in for has other metrics.
     * The label is taken to share the marker's font, size and line height, as the PDF's one-line slot does. Google
     * Docs turns an exact height into a multiple of its own line, so there the marker sits less exactly; Word is
     * the reader this is tuned for.
     */
    public function glyphParagraph(string $glyph, ComputedStyle $s): Paragraph
    {
        if (!$s->height instanceof Length) {
            throw new \LogicException('A marker glyph comes from glyph(), which only accepts a fixed height.');
        }
        $halfPoints = Units::halfPoints($s->height->points * 1.25);
        $size = $halfPoints / 2;
        // Word adds a multiple's extra space above the text, so the label's baseline sits its descent above the line's bottom.
        $capitals = $s->lineHeight * $s->fontSize
            - ($this->metrics->descent($s->fontFamily) + $this->metrics->capHeight($s->fontFamily) / 2) * $s->fontSize;
        // A marker too big to centre there hangs from the top of the line rather than lose its top.
        $centre = max($capitals, self::MARKER_INK_HALF * $size);
        $font = $this->fonts->fixed(self::MARKER_FONT, new FontInfo(FontClass::Sans, self::MARKER_PANOSE, false));

        return new Paragraph(
            [new Run($glyph, new RunStyle(
                sizeHalfPoints: $halfPoints,
                color: Units::hex($s->borderColor),
                fontFamily: $font,
                fontFamilyEastAsia: $font,
            ))],
            new ParagraphStyle(lineSpacingTwips: Units::tw($centre + self::MARKER_DROP * $size), lineSpacingRule: 'exact'),
        );
    }

    /** @throws InvalidValue for a scheme other than http, https, mailto, tel or a #fragment */
    public static function href(string $uri): string
    {
        if (!Link::allowed($uri)) {
            throw InvalidValue::for('link', $uri)->because('only http, https, mailto, tel and #fragment links are allowed');
        }

        return ltrim($uri);
    }

    /** @param list<InlineElement> $children */
    public static function hyperlink(string $uri, array $children): Hyperlink
    {
        $uri = self::href($uri);

        return str_starts_with($uri, '#') ? new Hyperlink(null, $children, substr($uri, 1)) : new Hyperlink($uri, $children);
    }
}
