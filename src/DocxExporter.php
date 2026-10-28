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
use Papercut\Docx\Map\{BoxMapper, Flow, ImageMapper, LineMetrics, ListMapper, Raster, RowMapper, TableMapper, TextMapper};
use Papercut\Docx\Package\{DocxPatcher, PageSetupMapper, PatchSpec};
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
            $name = count($pages) > 1 ? sprintf('page[%d]', $i) : 'page';
            if ($page->templatePage() !== null) {
                $fidelity->dropped('PDF page template', $name);
            }
        }

        $boxes = new BoxMapper($fidelity);
        $visitor = new DocxVisitor(
            $fidelity,
            WidthResolver::for($options),
            new TextMapper(new LineMetrics($options->fonts()), $fidelity),
            $boxes,
            new RowMapper($fidelity, $boxes),
            new TableMapper($fidelity, $boxes),
            new ListMapper(),
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

        $root = $styles->root();
        $bytes = DocxPatcher::patch(
            (new Word2007Writer($registry))->write($docx),
            new PatchSpec($root->fontFamily, (int) round($root->fontSize * 2), $meta->lang, $date),
        );

        return new Output($bytes, self::MIME, 'docx');
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
            if ($last !== null && self::alike($groups[$last][0], $p)) {
                $groups[$last][] = $p;
            } else {
                $groups[] = [$p];
            }
        }
        if ($groups === []) {
            throw new \LogicException('A document needs a page');
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
            $header = $pages[0]['regions']['header'] ?? [];
            if ($pages[0]['floats'] !== []) {
                // Pictures placed on the page from the header repeat on every page of the section, as layers do.
                $header = [new Paragraph($pages[0]['floats'], Flow::hairline()->style), ...$header];
            }
            $footer = $pages[0]['regions']['footer'] ?? [];
            // A section without a header or footer shows the previous section's in Word; an empty one stops that.
            $previous = $sections[$g - 1] ?? null;
            if ($previous !== null && $header === [] && $previous->header !== []) {
                $header = [Flow::hairline()];
            }
            if ($previous !== null && $footer === [] && $previous->footer !== []) {
                $footer = [Flow::hairline()];
            }
            $sections[] = new Section($body, $header, $footer, PageSetupMapper::setup($pages[0]['page']));
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
            && $a['floats'] == $b['floats'];
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
