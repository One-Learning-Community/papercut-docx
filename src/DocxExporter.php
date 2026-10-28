<?php

declare(strict_types=1);

namespace Papercut\Docx;

use Dskripchenko\PhpDocx\Document as DocxDocument;
use Dskripchenko\PhpDocx\Element\{BlockElement, PageBreak, Paragraph};
use Dskripchenko\PhpDocx\Style\{CoreProperties, ParagraphStyle, RunStyle, StyleRegistry};
use Dskripchenko\PhpDocx\Section;
use Dskripchenko\PhpDocx\Writer\Word2007Writer;
use Papercut\Backend\DefaultBackend;
use Papercut\Document;
use Papercut\Docx\Map\{BoxMapper, Flow, ImageMapper, LineMetrics, ListMapper, RowMapper, TableMapper, TextMapper};
use Papercut\Docx\Package\{DocxPatcher, PageSetupMapper, PatchSpec};
use Papercut\Docx\Walk\DocxVisitor;
use Papercut\Export\{ExportOptions, Exporter, Fidelity, NoteReporter, Output};
use Papercut\Layout\WidthResolver;
use Papercut\Style\StyleResolver;
use Papercut\Tree\TreeWalker;

/** Word (.docx) export: the document's pages as one flowing Word section that Word paginates. */
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
            if ($page->backgroundNodes() !== []) {
                $fidelity->dropped('background layer', $name . ' > background');
            }
            if ($page->overlayPlaces() !== []) {
                $fidelity->dropped('overlay layer', $name . ' > overlay');
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
            new ImageMapper($fidelity, DefaultBackend::make(false)),
        );
        (new TreeWalker($styles))->walk($doc, $visitor);
        $first = $visitor->pages()[0] ?? throw new \LogicException('A document needs a page');
        $page = $first['page'];

        foreach ($visitor->pages() as $i => $p) {
            if ($i === 0) {
                continue;
            }
            $other = $p['page'];
            if (abs($other->width() - $page->width()) > 0.01 || abs($other->height() - $page->height()) > 0.01 || $other->margins() != $page->margins()) {
                $fidelity->approximated("page setup other than the first page's", sprintf('page[%d]', $i));
            }
            if ($other->headerNodes() !== [] || $other->footerNodes() !== []) {
                $fidelity->approximated("header or footer other than the first page's", sprintf('page[%d]', $i));
            }
        }

        $body = $this->body($visitor);
        // Word needs the body to end in a paragraph; php-docx's own is default height and can spill onto a new page.
        if ($body === [] || !end($body) instanceof Paragraph) {
            $body[] = Flow::hairline();
        }
        $section = new Section($body, $first['regions']['header'] ?? [], $first['regions']['footer'] ?? [], PageSetupMapper::setup($page));

        $meta = $doc->meta();
        $date = $meta->createdAt ?? new \DateTimeImmutable('2000-01-01T00:00:00Z');
        $docx = new DocxDocument($section, coreProperties: new CoreProperties(
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
        [$w, $h] = PageSetupMapper::pageSize($page);
        $bytes = DocxPatcher::patch(
            (new Word2007Writer($registry))->write($docx),
            new PatchSpec($w, $h, $root->fontFamily, (int) round($root->fontSize * 2), $meta->lang, $date),
        );

        return new Output($bytes, self::MIME, 'docx');
    }

    /** @return list<BlockElement> every page's body, joined by page breaks */
    private function body(DocxVisitor $visitor): array
    {
        $body = [];
        foreach ($visitor->pages() as $i => $p) {
            $blocks = $p['regions']['body'] ?? [];
            $first = $blocks[0] ?? null;
            // The pages already break when one ends in a page break or the next starts on a new page.
            if ($i > 0 && !end($body) instanceof PageBreak && !($first instanceof Paragraph && $first->style->pageBreakBefore)) {
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
