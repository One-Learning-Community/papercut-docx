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
            sizeHalfPoints: (int) round($s->fontSize * 2),
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
        $round = $s->radius >= min($s->width->points, $s->height->points) / 2 - 0.5;

        return $round ? ($filled ? '◉' : '○') : ($filled ? '▣' : '☐');
    }

    public function glyphParagraph(string $glyph, ComputedStyle $s): Paragraph
    {
        $size = $s->height instanceof Length ? $s->height->points * 1.25 : $s->fontSize;

        return new Paragraph(
            [new Run($glyph, new RunStyle(sizeHalfPoints: (int) round($size * 2), color: Units::hex($s->borderColor)))],
            new ParagraphStyle(lineSpacingTwips: 240, lineSpacingRule: 'auto'),
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
