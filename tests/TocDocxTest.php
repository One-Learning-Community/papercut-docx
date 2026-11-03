<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests;

use Papercut\{Document, Image, Page, Row, Table, TableCell, Text, Toc};
use Papercut\Docx\DocxExporter;
use Papercut\Docx\Tests\Support\DocxInspector;
use Papercut\Export\{ExportOptions, FidelityReport};
use Papercut\Style\Style;
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

final class TocDocxTest extends TestCase
{
    private static function export(Document $doc, ?ExportOptions $options = null): DocxInspector
    {
        return DocxInspector::of($doc->export(new DocxExporter(), $options)->bytes());
    }

    private static function threeSections(Toc $toc): Document
    {
        return Document::make(
            Page::a4()->body($toc),
            Page::a4()->body(Text::h1('Alpha'), Text::make('one')),
            Page::a4()->body(Text::h1('Beta'), Text::h2('Beta sub'), Text::make('two')),
            Page::a4()->body(Text::h1('Gamma'), Text::make('three')),
        );
    }

    public function test_the_field_is_pre_filled_with_the_page_numbers_of_the_pdf_layout(): void
    {
        $d = self::export(self::threeSections(Toc::make()->title('Contents')->levels(1, 2)));
        $xml = $d->part('word/document.xml');

        self::assertSame(1, substr_count($xml, 'TOC \o "1-2" \h \z \u'));
        self::assertSame(['_Toc000000001', '_Toc000000002', '_Toc000000003', '_Toc000000004'], $d->values('//w:hyperlink/@w:anchor'));
        self::assertSame(4, preg_match_all('~PAGEREF (_Toc00000000\d) \\\\h~', $xml, $m));
        self::assertSame(['_Toc000000001', '_Toc000000002', '_Toc000000003', '_Toc000000004'], $m[1]);
        // Each entry: text, a tab, then the cached number inside its PAGEREF field.
        self::assertSame(['2', '3', '3', '4'], $d->values('//w:hyperlink//w:r[preceding-sibling::w:r[w:fldChar/@w:fldCharType="separate"]][w:t]/w:t'));
        self::assertSame(['Alpha', 'Beta', 'Beta sub', 'Gamma'], $d->values('//w:hyperlink/w:r[1]/w:t'));
        self::assertSame('Contents', $d->value('//w:p[.//w:t="Contents"][1]//w:t'));
        self::assertSame(1, $d->count('//w:fldChar[@w:fldCharType="end"][not(ancestor::w:hyperlink)]'));
        // The field spans the entries: begin in the first, end in the last.
        self::assertSame(1, $d->count('//w:p[w:r/w:fldChar/@w:fldCharType="begin"][.//w:t="Alpha"]'));
        self::assertSame(1, $d->count('//w:p[w:r/w:fldChar/@w:fldCharType="end"][.//w:t="Gamma"]'));
        self::assertStringNotContainsString('papercut-toc-marker', $xml);
    }

    public function test_headings_that_are_entries_carry_a_bookmark(): void
    {
        $d = self::export(self::threeSections(Toc::make()->levels(1, 2)));

        self::assertSame('_Toc000000001', $d->value('//w:p[w:pPr/w:pStyle/@w:val="Heading1"][.//w:t="Alpha"]/w:bookmarkStart/@w:name'));
        self::assertSame('_Toc000000003', $d->value('//w:p[w:pPr/w:pStyle/@w:val="Heading2"]/w:bookmarkStart/@w:name'));
        $ids = $d->values('//w:bookmarkStart/@w:id');
        self::assertCount(4, $ids);
        self::assertCount(4, array_unique($ids));
        self::assertSame($ids, $d->values('//w:bookmarkEnd/@w:id'));
    }

    public function test_markup_kept_out_of_the_toc_stays_a_heading_without_an_entry(): void
    {
        $d = self::export(Document::make(
            Page::a4()->body(Toc::make()->levels(1, 2)),
            Page::a4()->body(Text::h1('Unit 1'), \Papercut\Markup\Markup::html('<h2>Warm-up</h2><p>Read.</p>', new \Papercut\Markup\MarkupOptions(toc: false))),
        ));

        self::assertSame(['Unit 1'], $d->values('//w:hyperlink/w:r[1]/w:t'));
        self::assertSame(1, $d->count('//w:p[w:pPr/w:pStyle/@w:val="Heading2"][.//w:t="Warm-up"]'));
        self::assertSame(0, $d->count('//w:p[.//w:t="Warm-up"]/w:bookmarkStart'));
    }

    public function test_section_and_card_heading_titles_are_word_headings_and_entries(): void
    {
        $d = self::export(Document::make(
            Page::a4()->body(Toc::make()->levels(1, 2)),
            Page::a4()->body(
                \Papercut\Decorator\Section::make('one')->title('Units')->number(2)->heading(1),
                \Papercut\Decorator\Card::make('two')->title('Unit 1')->heading(2),
            ),
        ));

        self::assertSame(['2 Units', 'Unit 1'], $d->values('//w:hyperlink/w:r[1]/w:t'));
        self::assertSame(1, $d->count('//w:p[w:pPr/w:pStyle/@w:val="Heading1"][.//w:t="Units"]'));
        self::assertSame(1, $d->count('//w:p[w:pPr/w:pStyle/@w:val="Heading2"][.//w:t="Unit 1"]'));
    }

    public function test_levels_limit_the_field_and_the_bookmarks(): void
    {
        $d = self::export(self::threeSections(Toc::make()->levels(1, 1)));

        self::assertSame(1, substr_count($d->part('word/document.xml'), 'TOC \o "1-1" \h \z \u'));
        self::assertSame(['Alpha', 'Beta', 'Gamma'], $d->values('//w:hyperlink/w:r[1]/w:t'));
        self::assertSame(0, $d->count('//w:p[w:pPr/w:pStyle/@w:val="Heading2"]/w:bookmarkStart'));
    }

    public function test_entries_are_indented_by_level_with_a_right_tab_at_the_text_width(): void
    {
        $d = self::export(self::threeSections(Toc::make()->levels(1, 2)));
        $entry = static fn (string $t): string => "//w:p[w:pPr/w:tabs][.//w:hyperlink/w:r[1]/w:t='$t']/w:pPr";

        // The width is the page width less both margins, in twips.
        $width = (int) round((595.28 - 2 * (float) self::margin()) * 20);
        self::assertSame('right', $d->value($entry('Alpha') . '/w:tabs/w:tab/@w:val'));
        self::assertEqualsWithDelta($width, (int) $d->value($entry('Alpha') . '/w:tabs/w:tab/@w:pos'), 1);
        self::assertSame('dot', $d->value($entry('Alpha') . '/w:tabs/w:tab/@w:leader'));
        self::assertSame('0', $d->value($entry('Alpha') . '/w:ind/@w:left') ?? '0');
        self::assertSame('240', $d->value($entry('Beta sub') . '/w:ind/@w:left'));
        // Level 1 is bold, as in the PDF; level 2 is not.
        self::assertSame(1, $d->count('//w:hyperlink[w:r[1]/w:t="Alpha"]/w:r[1]/w:rPr/w:b'));
        self::assertSame(0, $d->count('//w:hyperlink[w:r[1]/w:t="Beta sub"]/w:r[1]/w:rPr/w:b'));
    }

    private static function png(): string
    {
        $im = imagecreatetruecolor(20, 20);
        \assert($im !== false);
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    private static function margin(): float
    {
        return Page::a4()->margins()->left->points;
    }

    public function test_leaders_off_writes_a_tab_without_a_leader(): void
    {
        $d = self::export(self::threeSections(Toc::make()->leaders(false)));

        self::assertSame(['none', 'none', 'none', 'none'], $d->values('//w:tabs/w:tab/@w:leader'));
    }

    public function test_updating_the_field_is_left_to_the_reader(): void
    {
        $d = self::export(self::threeSections(Toc::make()));

        if ($d->has('word/settings.xml')) {
            self::assertStringNotContainsString('updateFields', $d->part('word/settings.xml'));
        }
        self::assertStringNotContainsString('w:dirty', $d->part('word/document.xml'));
    }

    public function test_a_table_of_contents_without_entries_is_its_title_alone(): void
    {
        $d = self::export(Document::make(Page::a4()->body(Toc::make()->title('Contents'), Text::make('no headings here'))));
        $xml = $d->part('word/document.xml');

        self::assertStringContainsString('Contents', $xml);
        self::assertStringNotContainsString('TOC \o', $xml);
        self::assertStringNotContainsString('fldChar', $xml);
        self::assertStringNotContainsString('papercut-toc-marker', $xml);
    }

    public function test_a_body_that_overflows_a_page_exports_without_a_warning(): void
    {
        $warnings = [];
        $options = new ExportOptions(onWarning: static function (string $m, ?FidelityReport $r = null) use (&$warnings): void {
            $warnings[] = $m;
        });
        $doc = Document::make(Page::a4()->body(
            Toc::make(),
            Text::h1('Tall picture'),
            Image::bytes(self::png())->width(pt(100))->height(pt(2000)),
        ));

        $d = self::export($doc, $options);

        self::assertSame([], $warnings);
        self::assertSame(1, $d->count('//w:hyperlink'));
    }

    public function test_a_table_of_contents_beside_text_in_a_row_is_written(): void
    {
        $d = self::export(Document::make(Page::a4()->body(
            Row::make(Toc::make(), Text::make('side')),
            Text::h1('Alpha'),
            Text::h1('Beta'),
        )));
        $xml = $d->part('word/document.xml');

        self::assertSame(1, substr_count($xml, 'TOC \o "1-2" \h \z \u'));
        self::assertSame(['Alpha', 'Beta'], $d->values('//w:hyperlink/w:r[1]/w:t'));
        self::assertStringNotContainsString('papercut-toc-marker', $xml);
    }

    public function test_a_table_of_contents_in_an_auto_width_table_column_is_written(): void
    {
        $d = self::export(Document::make(Page::a4()->body(
            Table::make()->columns('auto auto')->row(TableCell::make(Toc::make()), 'other'),
            Text::h1('Alpha'),
        )));

        self::assertSame(1, substr_count($d->part('word/document.xml'), 'TOC \o "1-2" \h \z \u'));
        self::assertSame(['Alpha'], $d->values('//w:hyperlink/w:r[1]/w:t'));
    }

    public function test_a_layout_that_cannot_run_writes_the_field_without_page_numbers(): void
    {
        // The PDF layout throws for a font family nobody registered; Word export alone only names it.
        $warnings = [];
        $options = new ExportOptions(onWarning: static function (string $m, ?FidelityReport $r = null) use (&$warnings): void {
            $warnings[] = $m;
        });
        $d = self::export(Document::make(Page::a4()->body(
            Toc::make(),
            Text::h1('Heading')->apply(Style::make(fontFamily: 'Unregistered')),
        )), $options);
        $xml = $d->part('word/document.xml');

        self::assertSame([], $warnings);
        self::assertSame(1, substr_count($xml, 'TOC \o "1-2" \h \z \u'));
        self::assertSame(1, substr_count($xml, 'PAGEREF _Toc000000001'));
        // The PAGEREF field has its separator and end but no cached result.
        self::assertSame(0, $d->count('//w:hyperlink//w:r[preceding-sibling::w:r[w:fldChar/@w:fldCharType="separate"]][w:t]'));
        self::assertSame(2, $d->count('//w:hyperlink//w:fldChar'.'[@w:fldCharType="separate" or @w:fldCharType="end"]'));
    }

    public function test_page_numbers_off_skips_the_layout_and_writes_the_field_without_them(): void
    {
        $before = DocxExporter::$layoutPasses;
        $d = self::export(self::threeSections(Toc::make()->levels(1, 2)), new \Papercut\Docx\DocxOptions(tocPageNumbers: false));
        self::assertSame($before, DocxExporter::$layoutPasses);

        self::assertSame(['Alpha', 'Beta', 'Beta sub', 'Gamma'], $d->values('//w:hyperlink/w:r[1]/w:t'));
        self::assertSame(0, $d->count('//w:hyperlink//w:r[preceding-sibling::w:r[w:fldChar/@w:fldCharType="separate"]][w:t]'));
        self::assertSame(4, preg_match_all('~PAGEREF _Toc~', $d->part('word/document.xml')));
    }

    public function test_docx_options_keep_their_setting_through_from(): void
    {
        $off = new \Papercut\Docx\DocxOptions(tocPageNumbers: false);
        self::assertFalse(\Papercut\Docx\DocxOptions::from($off)->tocPageNumbers);
        self::assertTrue(\Papercut\Docx\DocxOptions::from(new ExportOptions())->tocPageNumbers);
    }

    public function test_the_page_layout_stops_after_the_last_listed_heading(): void
    {
        // Twenty pages of text after the last h1: the layout stops on page 4, where Gamma is.
        $tail = array_map(static fn (int $i): Page => Page::a4()->body(Text::make(str_repeat("Filler $i. ", 400))), range(1, 20));
        $doc = Document::make(...[...self::threeSections(Toc::make()->levels(1, 1))->pages(), ...$tail]);
        $d = self::export($doc);

        self::assertSame(['2', '3', '4'], $d->values('//w:hyperlink//w:r[preceding-sibling::w:r[w:fldChar/@w:fldCharType="separate"]][w:t]/w:t'));
        self::assertSame(4, DocxExporter::$pagesLaidOut);
    }

    public function test_a_document_without_one_never_lays_out_pages(): void
    {
        $before = DocxExporter::$layoutPasses;
        self::export(Document::make(Page::a4()->body(Text::h1('Heading'))));
        self::assertSame($before, DocxExporter::$layoutPasses);

        self::export(Document::make(Page::a4()->body(Toc::make(), Text::h1('Heading'))));
        self::assertSame($before + 1, DocxExporter::$layoutPasses);
    }
}
