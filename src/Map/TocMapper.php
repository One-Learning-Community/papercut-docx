<?php

declare(strict_types=1);

namespace Papercut\Docx\Map;

use Dskripchenko\PhpDocx\Element\{BlockElement, Paragraph, Run};
use Dskripchenko\PhpDocx\Style\ParagraphStyle;
use Papercut\{Text, Toc};
use Papercut\Docx\Package\{TocField, TocFieldEntry};
use Papercut\Docx\Walk\Frame;
use Papercut\Style\{ComputedStyle, StyleResolver};
use Papercut\Toc\{TocIndex, TocLine};
use Papercut\Tree\Visit;

/**
 * Tables of contents as Word sees them: the title as a paragraph, and a marker paragraph that DocxPatcher turns into the
 * TOC field, pre-filled from the PDF layout's page numbers. Headings that are entries get a `_Toc` bookmark to link to.
 */
final class TocMapper
{
    /** @var list<TocField> */
    private array $fields = [];

    /** @param array<string, int> $pages destination => 1-based page of the PDF layout */
    public function __construct(
        private readonly TocIndex $index,
        private readonly array $pages,
        private StyleResolver $styles,
        private readonly TextMapper $text,
        private readonly WordFonts $fonts,
    ) {
    }

    /** Resolves the title and entry styles with the page's theme from here on. */
    public function usePage(StyleResolver $styles): void
    {
        $this->styles = $styles;
    }

    /** The bookmark a heading at this walk path carries, or null when no table of contents lists it. */
    public function bookmark(string $path): ?string
    {
        $entry = $this->index->forKey($path);

        return $entry === null ? null : self::bookmarkName($entry->destination);
    }

    /** @return list<TocField> */
    public function fields(): array
    {
        return $this->fields;
    }

    /** @return list<BlockElement> */
    public function blocks(Toc $toc, ComputedStyle $style, Frame $frame, Visit $at): array
    {
        $blocks = [];
        if ($toc->titleText() !== null) {
            $title = Text::make($toc->titleText())->apply('toc.title');
            $s = $this->styles->resolve($title, $style);
            $p = $this->text->paragraph($title, $s, $at);
            $blocks[] = new Paragraph($p->children, new ParagraphStyle(...[...get_object_vars($p->style),
                'spaceBeforeTwips' => Units::tw($s->margin->top->points),
                'spaceAfterTwips' => Units::tw($s->margin->bottom->points),
                'keepWithNext' => true,
            ]));
        }
        $entries = $this->index->entries($toc->fromLevel(), $toc->toLevel());
        if ($entries === []) {
            return $blocks;
        }

        $lines = [];
        foreach ($entries as $entry) {
            $s = $this->styles->resolve(new TocLine($entry, $toc->hasLeaders()), $style);
            $lines[] = new TocFieldEntry(
                $entry->text,
                self::bookmarkName($entry->destination),
                $this->pages[$entry->destination] ?? null,
                Units::tw($s->padding->left->points + $s->borderWidth->left->points),
                Units::tw($s->margin->top->points),
                Units::tw($s->margin->bottom->points),
                $this->text->paragraphStyle($s)->lineSpacingTwips ?? 240,
                $this->runProperties($s),
            );
        }
        $this->fields[] = new TocField($toc->fromLevel(), $toc->toLevel(), Units::tw($frame->content), $toc->hasLeaders(), $lines);
        $blocks[] = new Paragraph([new Run(TocField::MARKER . count($this->fields))], new ParagraphStyle());

        return $blocks;
    }

    private static function bookmarkName(string $destination): string
    {
        return sprintf('_Toc%09d', (int) substr($destination, strlen('papercut-toc-')) + 1);
    }

    /** The run properties of an entry's font, size, weight, slant and colour, in the order CT_RPr writes them. */
    private function runProperties(ComputedStyle $s): string
    {
        $font = htmlspecialchars($this->fonts->name($s->fontFamily), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $size = (int) round($s->fontSize * 2);

        return '<w:rPr><w:rFonts w:ascii="' . $font . '" w:hAnsi="' . $font . '" w:cs="' . $font . '"/>'
            . ($s->fontWeight >= 600 ? '<w:b/><w:bCs/>' : '')
            . ($s->italic ? '<w:i/><w:iCs/>' : '')
            . '<w:color w:val="' . Units::hex($s->color) . '"/>'
            . '<w:sz w:val="' . $size . '"/><w:szCs w:val="' . $size . '"/></w:rPr>';
    }
}
