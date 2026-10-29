<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests\Walk;

use Papercut\{Document, Image, Page, Text};
use Papercut\Docx\Tests\Support\{DocxInspector, Styles};
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

final class PageVariantsTest extends TestCase
{
    use Styles;

    private static function png(): string
    {
        $im = imagecreatetruecolor(4, 2);
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    private static function text(DocxInspector $d, string $part): string
    {
        return trim(preg_replace('/\s+/', ' ', $d->bodyText($part)) ?? '');
    }

    private static function base(): Page
    {
        return Page::a4()->header(Text::make('Default head'))->footer(Text::make('Default foot'))->body(Text::make('Body'));
    }

    public function test_first_header_writes_title_page_and_both_first_parts(): void
    {
        $d = self::export(self::base()->firstHeader(Text::make('Letterhead')));
        self::assertSame(1, $d->count('//w:sectPr/w:titlePg'));
        self::assertSame(1, $d->count('//w:sectPr/w:headerReference[@w:type="first"]'));
        self::assertSame(1, $d->count('//w:sectPr/w:footerReference[@w:type="first"]'));
        self::assertSame('Letterhead', self::text($d, self::partOf($d, 'header', 'first')));
    }

    public function test_first_header_alone_keeps_the_normal_footer_on_page_one(): void
    {
        $d = self::export(self::base()->firstHeader(Text::make('Letterhead')));
        self::assertSame('Default foot', self::text($d, self::partOf($d, 'footer', 'first')));
        self::assertSame('Default foot', self::text($d, self::partOf($d, 'footer', 'default')));
    }

    public function test_set_empty_first_header_is_a_hairline(): void
    {
        $d = self::export(self::base()->firstHeader());
        $part = self::partOf($d, 'header', 'first');
        self::assertSame(1, $d->count('//w:p', $part));
        self::assertSame('', self::text($d, $part));
    }

    public function test_even_parts_are_filled_for_every_section_once_any_run_uses_them(): void
    {
        $a = self::base();
        $b = Page::a4()->size(pt(400), pt(600))->header(Text::make('Other head'))->body(Text::make('B'))->evenHeader(Text::make('Even head'));
        $d = self::export(Document::make($a, $b));
        self::assertSame(2, $d->count('//w:sectPr'));
        self::assertSame(1, $d->count('//w:evenAndOddHeaders', 'word/settings.xml'));
        $xp = $d->xpath();
        $ids = [];
        foreach ($xp->query('//w:sectPr') ?: [] as $s) {
            if (!$s instanceof \DOMElement) {
                continue;
            }
            $ids[] = (string) $xp->evaluate('string(w:headerReference[@w:type="even"]/@r:id)', $s);
        }
        self::assertCount(2, $ids);
        $texts = array_map(fn (string $id): string => self::text($d, self::target($d, $id)), $ids);
        self::assertSame(['Default head', 'Even head'], $texts);
    }

    public function test_no_variants_write_no_first_or_even_parts(): void
    {
        $d = self::export(self::base());
        self::assertSame(0, $d->count('//w:titlePg'));
        self::assertStringNotContainsString('w:type="first"', $d->part('word/document.xml'));
        self::assertStringNotContainsString('w:type="even"', $d->part('word/document.xml'));
        self::assertFalse($d->has('word/settings.xml'));
    }

    public function test_background_picture_is_in_every_header_part(): void
    {
        $d = self::export(self::base()->background(Image::bytes(self::png()))->firstHeader()->evenHeader(Text::make('Even')));
        foreach (['default', 'first', 'even'] as $type) {
            self::assertSame(1, $d->count('//wp:anchor', self::partOf($d, 'header', $type)), $type);
        }
        // The floats paragraph alone: no hairline beside it.
        self::assertSame(1, $d->count('//w:p', self::partOf($d, 'header', 'first')));
    }

    public function test_alike_runs_with_a_first_header_stay_separate_sections(): void
    {
        $d = self::export(Document::make(self::base(), self::base()->firstHeader(Text::make('Letterhead'))));
        self::assertSame(2, $d->count('//w:sectPr'));
        self::assertSame(1, $d->count('//w:titlePg'));
        self::assertSame(1, $d->count('(//w:sectPr)[2]/w:titlePg'));
    }

    public function test_empty_normal_header_gives_a_hairline_even_header(): void
    {
        $a = Page::a4()->footer(Text::make('Foot A'))->body(Text::make('A'));
        $b = Page::a4()->size(pt(400), pt(600))->header(Text::make('Head B'))->body(Text::make('B'))->evenHeader(Text::make('Even B'));
        $d = self::export(Document::make($a, $b));
        $xp = $d->xpath();
        $h = self::target($d, (string) $xp->evaluate('string((//w:sectPr)[1]/w:headerReference[@w:type="even"]/@r:id)'));
        self::assertSame(1, $d->count('//w:p', $h));
        self::assertSame('', self::text($d, $h));
        self::assertSame('Foot A', self::text($d, self::partOf($d, 'footer', 'even')));
    }

    public function test_consecutive_runs_with_the_same_first_header_stay_separate(): void
    {
        $d = self::export(Document::make(self::base()->firstHeader(Text::make('L')), self::base()->firstHeader(Text::make('L'))));
        self::assertSame(2, $d->count('//w:sectPr'));
        self::assertSame(2, $d->count('//w:titlePg'));
    }

    public function test_gaps_apply_to_variant_parts(): void
    {
        $d = self::export(self::base()->headerGap(pt(10))->footerGap(pt(6))->firstHeader(Text::make('H'))->firstFooter(Text::make('F')));
        self::assertSame('200', $d->value('(//w:p)[last()]/w:pPr/w:spacing/@w:after', self::partOf($d, 'header', 'first')));
        self::assertSame('120', $d->value('(//w:p)[1]/w:pPr/w:spacing/@w:before', self::partOf($d, 'footer', 'first')));
    }

    public function test_later_run_first_header_beside_an_even_footer_is_reported(): void
    {
        self::export(Document::make(self::base(), self::base()->firstHeader(Text::make('L'))->evenFooter(Text::make('E'))));
        self::assertSame(['Approximated first page of a later run on an even page @ page[1]'], self::exportedReports());
    }

    public function test_later_run_first_footer_beside_an_even_header_is_reported(): void
    {
        self::export(Document::make(self::base(), self::base()->firstFooter(Text::make('L'))->evenHeader(Text::make('E'))));
        self::assertSame(['Approximated first page of a later run on an even page @ page[1]'], self::exportedReports());
    }

    public function test_the_even_page_note_skips_a_first_run(): void
    {
        self::export(self::base()->firstHeader(Text::make('L'))->evenFooter(Text::make('E')));
        self::assertSame([], self::exportedReports());
    }

    public function test_the_even_page_note_skips_a_missing_even_variant(): void
    {
        self::export(Document::make(self::base(), self::base()->firstHeader(Text::make('L'))));
        self::assertSame([], self::exportedReports());
    }

    public function test_the_even_page_note_skips_a_run_that_sets_both_first_parts(): void
    {
        self::export(Document::make(self::base(), self::base()->firstHeader(Text::make('L'))->firstFooter(Text::make('F'))->evenFooter(Text::make('E'))));
        self::assertSame([], self::exportedReports());
    }

    private static function target(DocxInspector $d, string $id): string
    {
        return 'word/' . $d->xpath('word/_rels/document.xml.rels')->evaluate("string(//*[@Id='$id']/@Target)");
    }

    /** The part the first section references as a header or footer of this type. */
    private static function partOf(DocxInspector $d, string $kind, string $type): string
    {
        $id = $d->xpath()->evaluate("string((//w:sectPr)[1]/w:{$kind}Reference[@w:type='$type']/@r:id)");

        return self::target($d, (string) $id);
    }
}
