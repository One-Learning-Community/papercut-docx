<?php

declare(strict_types=1);

namespace Papercut\Docx;

use Dskripchenko\PhpDocx\Document as DocxDocument;
use Dskripchenko\PhpDocx\Element\{BlockElement, Image as DocxImage, PageBreak, Paragraph};
use Dskripchenko\PhpDocx\Style\{CoreProperties, ParagraphStyle, RunStyle, SectionStart, StyleRegistry};
use Papercut\Page;
use Dskripchenko\PhpDocx\Section;
use Dskripchenko\PhpDocx\Writer\Word2007Writer;
use Papercut\Backend\DefaultBackend;
use Papercut\Document;
use Papercut\Docx\Map\{BoxMapper, ColumnsMark, Flow, ImageMapper, LineMetrics, InlineRow, ListMapper, Raster, RowMapper, TableMapper, TextMapper, TocMapper, WordFonts};
use Papercut\Docx\Package\{DocxPatcher, Numbering, PageSetupMapper, PatchSpec};
use Papercut\Docx\Walk\DocxVisitor;
use Papercut\Export\{ExportOptions, Exporter, Fidelity, FidelityReport, NoteReporter, Output, PdfOptions};
use Papercut\Layout\{LayoutContext, LayoutEngine, Paginator};
use Papercut\OverflowMode;
use Papercut\Paint\Painter;
use Papercut\Text\FontRegistry;
use Papercut\Toc\TocIndex;
use Papercut\Layout\WidthResolver;
use Papercut\Style\PageThemes;
use Papercut\Tree\TreeWalker;

/**
 * Word (.docx) export: the document's pages as flowing Word sections that Word paginates, a new section wherever
 * the page setup, header, footer or layer pictures change.
 */
final class DocxExporter implements Exporter
{
    /** @internal How many times the hidden page layout for a table of contents has started; tests read it. */
    public static int $layoutPasses = 0;

    public const string MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    public function export(Document $doc, ExportOptions $options): Output
    {
        $options = DocxOptions::from($options);
        $themes = PageThemes::for($options->theme(), $doc);
        $styles = $themes->documentStyles();
        $fidelity = new Fidelity('docx', $options);
        NoteReporter::report($doc, $themes, $fidelity);

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
        $text = new TextMapper(new LineMetrics($options->fonts()), $fidelity, $fonts, $themes->document());
        $index = TocIndex::collect($doc, $themes);
        $widths = WidthResolver::for($options, 'docx', $index, $themes);
        $toc = $index->hasToc() ? new TocMapper($index, $options->tocPageNumbers ? self::anchorPages($doc, $options, $index, $themes) : [], $styles, $text, $fonts) : null;
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
            $toc,
            $themes,
        );
        (new TreeWalker($themes))->walk($doc, $visitor);
        [$sections, $columns] = $this->sections($visitor);
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
            new PatchSpec($fonts->name($root->fontFamily), (int) round($root->fontSize * 2), $meta->lang, $date, $mirror, $numbering, $fonts->written(), $toc?->fields() ?? [], $columns, $visitor->columnBreaks()),
            $fidelity,
        );

        return new Output($bytes, self::MIME, 'docx');
    }

    /**
     * The page each heading that a table of contents lists falls on in the PDF layout, by destination. Word paginates
     * itself, so this runs only for a document with a table of contents, to fill in its page numbers. It clips what is
     * too tall instead of throwing, says nothing, and gives no pages at all when the layout fails for any reason, so a
     * document that exports today never starts failing; the field is then written without page numbers.
     *
     * @return array<string, int>
     */
    private static function anchorPages(Document $doc, DocxOptions $options, TocIndex $index, PageThemes $themes): array
    {
        self::$layoutPasses++;
        try {
            $pdf = PdfOptions::from($options);
            $backend = $pdf->backend();
            $ctx = new LayoutContext(
                FontRegistry::fromSet($pdf->fonts(), $backend),
                $themes->document(),
                $backend,
                OverflowMode::Clip,
                static function (string $message, ?FidelityReport $report = null): void {
                },
                $pdf->fonts()->fallbacks,
            );
            $ctx->themes = $themes;
            $ctx->toc = $index;
            $ctx->measureOnly = true;

            return Painter::anchorPages((new Paginator(new LayoutEngine($ctx), $ctx))->paginate($doc));
        } catch (\Throwable) {
            // Word export must never fail for want of page numbers: the field is written without them.
            return [];
        }
    }

    /** The path fidelity reports use for a page: indexed only when the document has several. */
    private static function pagePath(int $index, int $count): string
    {
        return $count > 1 ? sprintf('page[%d]', $index) : 'page';
    }

    /**
     * One section per run of pages that look alike: the same page setup, header, footer and layer pictures.
     *
     * A page's flows split its body at their marks: each flow is a continuous section with its columns, and what follows
     * is a continuous one-column section (a hairline alone when nothing does, which levels the columns).
     *
     * @return array{non-empty-list<Section>, array<int, string>} the sections, and each flow section's `w:cols` XML by index
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
        $columns = [];
        foreach ($groups as $g => $pages) {
            $segments = self::withoutEmptySegments(self::segments($this->body($pages)));
            if ($g > 0) {
                $segments[0]['blocks'] = self::withoutLeadingBreak($segments[0]['blocks']);
            }
            $lastSegment = array_key_last($segments);
            if ($g < count($groups) - 1 && end($segments[$lastSegment]['blocks']) instanceof PageBreak) {
                // The next section starts on a new page by itself.
                array_pop($segments[$lastSegment]['blocks']);
            }
            foreach ($segments as $i => $segment) {
                $body = $segment['blocks'];
                // Word needs a section to end in a paragraph; php-docx's own is default height and can spill onto a new page.
                // A column break there would leave the paragraph mark alone in the next column.
                if ($body === [] || !end($body) instanceof Paragraph || Flow::isColumnBreak(end($body))) {
                    $body[] = Flow::hairline();
                }
                $first = $pages[0];
                $floats = $first['floats'];
                $normalHeader = $first['regions']['header'] ?? [];
                $normalFooter = $first['regions']['footer'] ?? [];
                $header = self::withFloats($normalHeader, $floats);
                $footer = $normalFooter;
                // A section without a header or footer shows the previous section's in Word; an empty one stops that.
                $previous = $sections[count($sections) - 1] ?? null;
                if ($previous !== null && $header === [] && $previous->header !== []) {
                    $header = [Flow::hairline()];
                }
                if ($previous !== null && $footer === [] && $previous->footer !== []) {
                    $footer = [Flow::hairline()];
                }
                // A variant that is set gives its blocks, or a hairline when set empty, except that a header part with floats needs none. An unset one copies the normal part.
                [$fh, $ff, $eh, $ef] = [[], [], [], []];
                $page = $first['page'];
                // Only the section that starts the page can show the first-page parts.
                if ($i === 0 && self::setsFirst($page)) {
                    $fh = self::variant($page->firstHeaderNodes() !== null, $first, 'first-header', $normalHeader, true, $floats);
                    $ff = self::variant($page->firstFooterNodes() !== null, $first, 'first-footer', $normalFooter, false, $floats);
                }
                if ($anyEven) {
                    $eh = self::variant($page->evenHeaderNodes() !== null, $first, 'even-header', $normalHeader, true, $floats);
                    $ef = self::variant($page->evenFooterNodes() !== null, $first, 'even-footer', $normalFooter, false, $floats);
                }
                if ($segment['cols'] !== null) {
                    $columns[count($sections)] = $segment['cols'];
                }
                $sections[] = new Section($body, $header, $footer, PageSetupMapper::setup($page), $fh, $ff, $eh, $ef, $i === 0 ? SectionStart::NextPage : SectionStart::Continuous);
            }
        }

        return [$sections, $columns];
    }

    /**
     * Drops the segments with nothing in them: the one before a flow that starts the body, and the one between two flows.
     * The one after a flow that ends the body stays, as the hairline that levels the flow's columns, and so does a
     * body's only segment.
     *
     * @param non-empty-list<array{blocks: list<BlockElement>, cols: ?string}> $segments
     * @return non-empty-list<array{blocks: list<BlockElement>, cols: ?string}>
     */
    private static function withoutEmptySegments(array $segments): array
    {
        $last = array_key_last($segments);
        $kept = [];
        foreach ($segments as $i => $segment) {
            $levels = $i === $last && $i > 0 && $segments[$i - 1]['cols'] !== null;
            if ($segment['blocks'] !== [] || $segment['cols'] !== null || $levels) {
                $kept[] = $segment;
            }
        }

        return $kept === [] ? [$segments[0]] : $kept;
    }

    /**
     * A body cut at its column marks: the blocks before a flow, the flow's own with its `w:cols`, and the blocks after it.
     *
     * @param list<BlockElement> $body
     * @return non-empty-list<array{blocks: list<BlockElement>, cols: ?string}>
     */
    private static function segments(array $body): array
    {
        $segments = [];
        $blocks = [];
        $cols = null;
        foreach ($body as $block) {
            if (!$block instanceof ColumnsMark) {
                $blocks[] = $block;
                continue;
            }
            $segments[] = ['blocks' => $blocks, 'cols' => $cols];
            [$blocks, $cols] = [[], $block->cols];
        }
        $segments[] = ['blocks' => $blocks, 'cols' => $cols];

        return $segments;
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
