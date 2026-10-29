<?php

declare(strict_types=1);

namespace Papercut\Docx;

use Dskripchenko\PhpDocx\Document as DocxDocument;
use Dskripchenko\PhpDocx\Element\{BlockElement, Image as DocxImage, PageBreak, Paragraph};
use Dskripchenko\PhpDocx\Style\{CoreProperties, ParagraphStyle, RunStyle, StyleRegistry};
use Papercut\Page;
use Dskripchenko\PhpDocx\Section;
use Dskripchenko\PhpDocx\Writer\Word2007Writer;
use Papercut\Backend\DefaultBackend;
use Papercut\Document;
use Papercut\Docx\Map\{BoxMapper, Flow, ImageMapper, LineMetrics, InlineRow, ListMapper, Raster, RowMapper, TableMapper, TextMapper, WordFonts};
use Papercut\Docx\Package\{DocxPatcher, Numbering, PageSetupMapper, PatchSpec};
use Papercut\Docx\Walk\DocxVisitor;
use Papercut\Export\{ExportOptions, Exporter, Fidelity, NoteReporter, Output};
use Papercut\Layout\WidthResolver;
use Papercut\Style\StyleResolver;
use Papercut\Tree\TreeWalker;

/**
 * Word (.docx) export: the document's pages as flowing Word sections that Word paginates, a new section wherever
 * the page setup, header, footer or layer pictures change.
 */
final class DocxExporter implements Exporter
{
    public const string MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    public function export(Document $doc, ExportOptions $options): Output
    {
        $options = DocxOptions::from($options);
        $styles = new StyleResolver($options->theme());
        $fidelity = new Fidelity('docx', $options);
        NoteReporter::report($doc, $styles, $fidelity);

        $pages = $doc->pages();
        foreach ($pages as $i => $page) {
            if ($page->templatePage() !== null) {
                $fidelity->dropped('PDF page template', self::pagePath($i, count($pages)));
            }
        }

        $mirror = false;
        foreach ($pages as $page) {
            $mirror = $mirror || $page->mirrorsMargins();
        }
        if ($mirror) {
            foreach ($pages as $i => $page) {
                $differ = abs($page->margins()->left->points - $page->margins()->right->points) > 0.01;
                if (!$page->mirrorsMargins() && $differ) {
                    $fidelity->approximated('mirrored margins', self::pagePath($i, count($pages)));
                }
            }
        }

        // Word paginates itself, so a later run's unset first part copies the normal one even where the PDF would use the even one.
        foreach ($pages as $i => $page) {
            $fh = $page->firstHeaderNodes() !== null;
            $ff = $page->firstFooterNodes() !== null;
            if ($i > 0 && (($fh && !$ff && $page->evenFooterNodes() !== null) || ($ff && !$fh && $page->evenHeaderNodes() !== null))) {
                $fidelity->approximated('first page of a later run on an even page', sprintf('page[%d]', $i));
            }
        }

        $numbering = new Numbering();
        $boxes = new BoxMapper($fidelity);
        $root = $styles->root();
        $fonts = new WordFonts($options->fonts());
        $fonts->name($root->fontFamily);
        $text = new TextMapper(new LineMetrics($options->fonts()), $fidelity, $fonts, $options->theme());
        $widths = WidthResolver::for($options);
        $visitor = new DocxVisitor(
            $fidelity,
            $widths,
            $text,
            $boxes,
            new RowMapper($fidelity, $boxes),
            new InlineRow($fidelity, $text, $widths),
            new TableMapper($fidelity, $boxes),
            new ListMapper($numbering, $fidelity),
            new ImageMapper($fidelity, DefaultBackend::make(false), Raster::detect()),
        );
        (new TreeWalker($styles))->walk($doc, $visitor);
        $sections = $this->sections($visitor);
        $first = $visitor->pages()[0]['page'];

        $meta = $doc->meta();
        $date = $meta->createdAt ?? new \DateTimeImmutable('2000-01-01T00:00:00Z');
        $docx = new DocxDocument($sections[0], followingSections: array_slice($sections, 1), coreProperties: new CoreProperties(
            title: $meta->title,
            subject: $meta->subject,
            creator: $meta->author,
            keywords: $meta->keywords,
            created: $date,
            modified: $date,
        ));

        // Heading styles carry no formatting of their own: every paragraph has Papercut's values directly.
        $registry = new StyleRegistry();
        for ($level = 1; $level <= 6; $level++) {
            $registry->heading($level, new RunStyle(), new ParagraphStyle());
        }

        $bytes = DocxPatcher::patch(
            (new Word2007Writer($registry))->write($docx),
            new PatchSpec($fonts->name($root->fontFamily), (int) round($root->fontSize * 2), $meta->lang, $date, $mirror, $numbering, $fonts->written()),
        );

        return new Output($bytes, self::MIME, 'docx');
    }

    /** The path fidelity reports use for a page: indexed only when the document has several. */
    private static function pagePath(int $index, int $count): string
    {
        return $count > 1 ? sprintf('page[%d]', $index) : 'page';
    }

    /**
     * One section per run of pages that look alike: the same page setup, header, footer and layer pictures.
     *
     * @return non-empty-list<Section>
     */
    private function sections(DocxVisitor $visitor): array
    {
        $groups = [];
        foreach ($visitor->pages() as $p) {
            $last = array_key_last($groups);
            if ($last !== null && !self::setsFirst($p['page']) && self::alike($groups[$last][0], $p)) {
                $groups[$last][] = $p;
            } else {
                $groups[] = [$p];
            }
        }
        if ($groups === []) {
            throw new \LogicException('A document needs a page');
        }

        $anyEven = false;
        foreach ($visitor->pages() as $p) {
            $anyEven = $anyEven || $p['page']->evenHeaderNodes() !== null || $p['page']->evenFooterNodes() !== null;
        }

        $sections = [];
        foreach ($groups as $g => $pages) {
            $body = $this->body($pages);
            if ($g > 0) {
                $body = self::withoutLeadingBreak($body);
            }
            if ($g < count($groups) - 1 && end($body) instanceof PageBreak) {
                // The next section starts on a new page by itself.
                array_pop($body);
            }
            // Word needs a section to end in a paragraph; php-docx's own is default height and can spill onto a new page.
            if ($body === [] || !end($body) instanceof Paragraph) {
                $body[] = Flow::hairline();
            }
            $first = $pages[0];
            $floats = $first['floats'];
            $normalHeader = $first['regions']['header'] ?? [];
            $normalFooter = $first['regions']['footer'] ?? [];
            $header = self::withFloats($normalHeader, $floats);
            $footer = $normalFooter;
            // A section without a header or footer shows the previous section's in Word; an empty one stops that.
            $previous = $sections[$g - 1] ?? null;
            if ($previous !== null && $header === [] && $previous->header !== []) {
                $header = [Flow::hairline()];
            }
            if ($previous !== null && $footer === [] && $previous->footer !== []) {
                $footer = [Flow::hairline()];
            }
            // A variant that is set gives its blocks, or a hairline when set empty, except that a header part with floats needs none. An unset one copies the normal part.
            [$fh, $ff, $eh, $ef] = [[], [], [], []];
            $page = $first['page'];
            if (self::setsFirst($page)) {
                $fh = self::variant($page->firstHeaderNodes() !== null, $first, 'first-header', $normalHeader, true, $floats);
                $ff = self::variant($page->firstFooterNodes() !== null, $first, 'first-footer', $normalFooter, false, $floats);
            }
            if ($anyEven) {
                $eh = self::variant($page->evenHeaderNodes() !== null, $first, 'even-header', $normalHeader, true, $floats);
                $ef = self::variant($page->evenFooterNodes() !== null, $first, 'even-footer', $normalFooter, false, $floats);
            }
            $sections[] = new Section($body, $header, $footer, PageSetupMapper::setup($page), $fh, $ff, $eh, $ef);
        }

        return $sections;
    }

    /**
     * @param array{page: Page, regions: array<string, list<BlockElement>>, floats: list<DocxImage>} $a
     * @param array{page: Page, regions: array<string, list<BlockElement>>, floats: list<DocxImage>} $b
     */
    private static function alike(array $a, array $b): bool
    {
        [$pa, $pb] = [$a['page'], $b['page']];

        return abs($pa->width() - $pb->width()) <= 0.01 && abs($pa->height() - $pb->height()) <= 0.01
            && $pa->margins() == $pb->margins()
            && ($a['regions']['header'] ?? []) == ($b['regions']['header'] ?? [])
            && ($a['regions']['footer'] ?? []) == ($b['regions']['footer'] ?? [])
            && $pa->mirrorsMargins() === $pb->mirrorsMargins()
            && self::variantsAlike($a, $b)
            && $a['floats'] == $b['floats'];
    }

    private static function setsFirst(Page $page): bool
    {
        return $page->firstHeaderNodes() !== null || $page->firstFooterNodes() !== null;
    }

    /**
     * Pictures placed on the page from the header repeat on every page of the section, as layers do.
     *
     * @param list<BlockElement> $part
     * @param list<DocxImage> $floats
     * @return list<BlockElement>
     */
    private static function withFloats(array $part, array $floats): array
    {
        return $floats === [] ? $part : [new Paragraph($floats, Flow::hairline()->style), ...$part];
    }

    /**
     * A first or even part: the variant's blocks if it is set, with a hairline when it is set empty, except that a header part with floats needs none. An unset variant copies the normal part.
     *
     * @param array{page: Page, regions: array<string, list<BlockElement>>, floats: list<DocxImage>} $run
     * @param list<BlockElement> $normal
     * @param list<DocxImage> $floats
     * @return list<BlockElement>
     */
    private static function variant(bool $set, array $run, string $region, array $normal, bool $head, array $floats): array
    {
        // A set variant that maps to no blocks falls back like a set-empty one. A header with floats needs no hairline: the floats paragraph is its content.
        $blocks = $set ? ($run['regions'][$region] ?? []) : $normal;
        if ($blocks === [] && (!$head || $floats === [])) {
            $blocks = [Flow::hairline()];
        }

        return $head ? self::withFloats($blocks, $floats) : $blocks;
    }

    /**
     * @param array{page: Page, regions: array<string, list<BlockElement>>, floats: list<DocxImage>} $a
     * @param array{page: Page, regions: array<string, list<BlockElement>>, floats: list<DocxImage>} $b
     */
    private static function variantsAlike(array $a, array $b): bool
    {
        foreach (['first-header', 'first-footer', 'even-header', 'even-footer'] as $region) {
            if (($a['regions'][$region] ?? []) != ($b['regions'][$region] ?? [])) {
                return false;
            }
        }
        [$pa, $pb] = [$a['page'], $b['page']];

        return ($pa->firstHeaderNodes() === null) === ($pb->firstHeaderNodes() === null)
            && ($pa->firstFooterNodes() === null) === ($pb->firstFooterNodes() === null)
            && ($pa->evenHeaderNodes() === null) === ($pb->evenHeaderNodes() === null)
            && ($pa->evenFooterNodes() === null) === ($pb->evenFooterNodes() === null);
    }

    /**
     * A section starts on a new page, so a later section's first paragraph needs no break of its own.
     *
     * @param list<BlockElement> $body
     * @return list<BlockElement>
     */
    private static function withoutLeadingBreak(array $body): array
    {
        while (($body[0] ?? null) instanceof PageBreak) {
            array_shift($body);
        }
        $first = $body[0] ?? null;
        if ($first instanceof Paragraph && $first->style->pageBreakBefore) {
            $style = new ParagraphStyle(...array_merge(get_object_vars($first->style), ['pageBreakBefore' => false]));
            $body[0] = new Paragraph($first->children, $style, $first->headingLevel, $first->styleId);
        }

        return $body;
    }

    /**
     * Pages' bodies, joined by page breaks.
     *
     * @param list<array{page: Page, regions: array<string, list<BlockElement>>, floats: list<DocxImage>}> $pages
     * @return list<BlockElement>
     */
    private function body(array $pages): array
    {
        $body = [];
        foreach ($pages as $i => $p) {
            $blocks = $p['regions']['body'] ?? [];
            $first = $blocks[0] ?? null;
            // The pages already break when one ends in a page break or the next starts on a new page.
            if ($i > 0 && !end($body) instanceof PageBreak && !$first instanceof PageBreak && !($first instanceof Paragraph && $first->style->pageBreakBefore)) {
                $body[] = new PageBreak();
            }
            foreach ($blocks as $b) {
                $body[] = $b;
            }
        }

        return $body;
    }

    public function defaultOptions(): DocxOptions
    {
        return new DocxOptions();
    }
}
