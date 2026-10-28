<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests\Walk;

use Papercut\{Column, Document, Image, Link, Page, PdfPage, Place, Span, Text};
use Papercut\Docx\Tests\Support\Styles;
use Papercut\Exception\InvalidValue;
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

final class RegionsTest extends TestCase
{
    use Styles;

    public function test_block_link_wraps_every_paragraph(): void
    {
        $d = self::export(Link::to('https://x.test', Column::make(Text::make('a'), Text::make('b'))));
        self::assertSame(2, $d->count('/w:document/w:body/w:p/w:hyperlink'));
        $targets = [];
        foreach ($d->values('/w:document/w:body/w:p/w:hyperlink/@r:id') as $id) {
            $targets[] = $d->xpath('word/_rels/document.xml.rels')->evaluate("string(//*[@Id='$id']/@Target)");
        }
        self::assertSame(['https://x.test', 'https://x.test'], $targets);
        self::assertSame(['Approximated block link @ page > body > link[0]'], self::exportedReports());
    }

    public function test_images_inside_a_block_link_are_not_linked(): void
    {
        $png = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true);
        $d = self::export(Link::to('https://x.test', Column::make(Image::bytes($png), Text::make('a'))));
        self::assertSame(0, $d->count('//w:hyperlink//w:drawing'));
        self::assertSame(1, $d->count('//w:drawing'));
    }

    public function test_disallowed_links_throw(): void
    {
        foreach ([
            Text::make('x')->append(new Span('go', href: 'javascript:alert(1)')),
            Link::to('javascript:alert(1)', Text::make('x')),
        ] as $node) {
            try {
                self::export($node);
                self::fail('expected InvalidValue');
            } catch (InvalidValue $e) {
                self::assertStringContainsString('javascript', $e->getMessage());
            }
        }
    }

    public function test_header_and_footer_with_their_gaps(): void
    {
        $page = Page::a4()->headerGap(pt(10))->footerGap(pt(6))
            ->header(Text::make('Head'))->body(Text::make('Body'))->footer(Text::make('Foot'));
        $d = self::export($page);
        self::assertTrue($d->has('word/header1.xml'));
        self::assertTrue($d->has('word/footer1.xml'));
        self::assertSame('200', $d->value('(//w:p)[last()]/w:pPr/w:spacing/@w:after', 'word/header1.xml'));
        self::assertSame('120', $d->value('(//w:p)[1]/w:pPr/w:spacing/@w:before', 'word/footer1.xml'));
    }

    public function test_layers_and_pdf_only_nodes_are_dropped(): void
    {
        self::export(Page::a4()->background(Text::make('bg'))->body(Text::make('x')));
        self::assertSame(['Dropped background layer @ page > background'], self::exportedReports());

        self::export(Page::a4()->body(Text::make('x'))->overlay(Place::at(pt(0), pt(0), Text::make('o'))));
        self::assertSame(['Dropped overlay layer @ page > overlay'], self::exportedReports());

        $template = Document::make(Page::a4()->body('t'))->toPdf()->bytes();
        self::export(Page::template(PdfPage::bytes($template))->body(Text::make('x')));
        self::assertSame(['Dropped PDF page template @ page'], self::exportedReports());

        self::export(Column::make(PdfPage::bytes($template)));
        self::assertSame(['Dropped pdf-page @ page > body > column[0] > pdf-page[0]'], self::exportedReports());
    }
}
