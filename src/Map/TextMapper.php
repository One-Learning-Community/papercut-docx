<?php

declare(strict_types=1);

namespace Papercut\Docx\Map;

use Dskripchenko\PhpDocx\Element\{Field, Hyperlink, InlineElement, LineBreak, Paragraph, Run};
use Dskripchenko\PhpDocx\Style\{Alignment, ParagraphStyle, RunStyle};
use Papercut\{Node, PageNumber, Text, View};
use Papercut\Docx\Package\DocxPatcher;
use Papercut\Exception\InvalidValue;
use Papercut\Export\Fidelity;
use Papercut\Paint\Link;
use Papercut\Style\{ComputedStyle, TextAlign};
use Papercut\Tree\Visit;
use Papercut\Value\{Color, Length};

/** Text, spans and page numbers as Word paragraphs and runs. */
final class TextMapper
{
    public function __construct(
        private readonly LineMetrics $metrics,
        private readonly Fidelity $fidelity,
    ) {
    }

    /** @param ?string $blockHref the enclosing block link, applied to runs without a link of their own */
    /** @param ?Color $backdrop what faded text lies on (white when null) */
    public function paragraph(Text $t, ComputedStyle $s, Visit $at, ?string $blockHref = null, ?Color $backdrop = null): Paragraph
    {
        $backdrop ??= Color::white();
        $children = [];
        foreach ($t->spanList() as $span) {
            if ($span->lineBreak) {
                $children[] = new LineBreak();
                continue;
            }
            // A span's own colours fade with the text; inherited ones already have.
            $cs = $s->child($span->style);
            $spanBg = Fade::over($span->style->background, $s->opacity, $backdrop);
            $rs = self::runStyle($span->style->color === null ? $cs : new ComputedStyle(...[...get_object_vars($cs), 'color' => Fade::over($cs->color, $s->opacity, $spanBg ?? $backdrop) ?? $cs->color]));
            $bg = Units::fill($spanBg);
            if ($bg !== null) {
                // php-docx writes no run shading, but writes a highlight as given: DocxPatcher turns this one into w:shd.
                $rs = new RunStyle(...[...get_object_vars($rs), 'highlight' => DocxPatcher::shading($bg)]);
            }
            $run = new Run($span->text, $rs);
            $href = $span->href ?? $blockHref;
            $children[] = $href !== null ? self::hyperlink($href, [$run]) : $run;
        }
        if ($s->minLines > 2) {
            // Word's widow control keeps two lines together, never more.
            $this->fidelity->approximated('min-lines ' . $s->minLines, $at->path());
        }
        if ($s->fontWeight !== 400 && $s->fontWeight !== 700) {
            $this->fidelity->approximated('font weight ' . $s->fontWeight, $at->path());
        }
        $level = match ($t->preset()) {
            'h1' => 1, 'h2' => 2, 'h3' => 3, 'h4' => 4,
            default => null,
        };

        return new Paragraph($children, $this->paragraphStyle($s), $level);
    }

    public function pageNumber(PageNumber $n, ComputedStyle $s): Paragraph
    {
        $rs = self::runStyle($s);
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

    public static function runStyle(ComputedStyle $s): RunStyle
    {
        return new RunStyle(
            sizeHalfPoints: (int) round($s->fontSize * 2),
            color: Units::hex($s->color),
            fontFamily: $s->fontFamily,
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
                TextAlign::Justify => Alignment::Justify,
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
