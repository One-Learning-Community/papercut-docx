<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests;

use Papercut\{Document, Node, Page, Text};
use Papercut\Docx\DocxExporter;
use Papercut\Docx\Tests\Support\{Documents, DocxInspector};
use Papercut\Style\{ComputedStyle, StyleResolver, Theme};
use Papercut\Tree\{Region, TreeVisitor, TreeWalker, Visit};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

/**
 * The words pdftotext finds in the PDF equal the words of the DOCX body, counted with multiplicity (body only).
 * Table header rows repeat on every PDF page and once in Word, so their words are left out of both sides.
 * Checkbox and radio markers are drawn shapes in the PDF and characters in Word, so they are left out too.
 */
final class ContentParityTest extends TestCase
{
    private const array MARKERS = ['☐', '▣', '○', '◉'];

    /** @return iterable<string, array{string}> */
    public static function documents(): iterable
    {
        return Documents::all();
    }

    protected function setUp(): void
    {
        exec('command -v pdftotext 2>/dev/null', $out, $code);
        if ($code !== 0) {
            self::markTestSkipped('pdftotext (poppler) is not installed.');
        }
    }

    #[DataProvider('documents')]
    public function test_pdf_and_docx_have_the_same_word_multisets(string $file): void
    {
        $source = Documents::load($file);
        $pages = array_map(static fn (Page $p): Page => Page::size(pt($p->width()), pt($p->height()))->margin($p->margins())->body(...$p->bodyNodes()), $source->pages());
        $document = Document::make(...$pages);

        $skip = $this->headerWords($document) + array_fill_keys(self::MARKERS, true);
        $docx = DocxInspector::of($document->export(new DocxExporter())->bytes())->bodyText();
        $words = $this->words($docx, $skip);
        self::assertNotSame([], $words);
        self::assertSame($this->words($this->pdfText($document), $skip), $words);
    }

    private function pdfText(Document $document): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pc') ?: self::fail('tempnam');
        try {
            file_put_contents($path, $document->toPdf()->bytes());

            return (string) shell_exec('pdftotext -enc UTF-8 ' . escapeshellarg($path) . ' - 2>/dev/null');
        } finally {
            @unlink($path);
        }
    }

    /** @return array<string,true> every word in every table header row of the document */
    private function headerWords(Document $document): array
    {
        $collector = new class () implements TreeVisitor {
            /** @var array<string,true> */
            public array $words = [];

            public function enterPage(Page $page, int $index): void
            {
            }

            public function leavePage(Page $page, int $index): void
            {
            }

            public function enterRegion(Region $region, ComputedStyle $root): void
            {
            }

            public function leaveRegion(Region $region): void
            {
            }

            public function enter(Node $node, ComputedStyle $style, Visit $at): bool
            {
                if ($node instanceof Text && $at->inHeader()) {
                    foreach ($node->spanList() as $span) {
                        foreach (preg_split('/\s+/u', trim(str_replace('-', '', $span->text)), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
                            $this->words[$w] = true;
                        }
                    }
                }

                return true;
            }

            public function leave(Node $node, ComputedStyle $style, Visit $at): void
            {
            }
        };
        (new TreeWalker(new StyleResolver(Theme::default())))->walk($document, $collector);

        return $collector->words;
    }

    /**
     * @param array<string,true> $skip
     * @return array<string,int> word => occurrences, sorted by word
     */
    private function words(string $text, array $skip): array
    {
        // pdftotext dehyphenates words that wrap at a hyphen, so hyphens are ignored on both sides.
        $words = preg_split('/\s+/u', trim(str_replace('-', '', $text)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $counts = [];
        foreach ($words as $w) {
            if (!isset($skip[$w])) {
                $counts[$w] = ($counts[$w] ?? 0) + 1;
            }
        }
        ksort($counts, SORT_STRING);

        return $counts;
    }
}
