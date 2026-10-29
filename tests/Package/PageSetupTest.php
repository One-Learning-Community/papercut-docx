<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests\Package;

use Papercut\{Document, Page, PageBreak, Text};
use Papercut\Docx\Tests\Support\Styles;
use PHPUnit\Framework\TestCase;

use function Papercut\{mm, pt};

final class PageSetupTest extends TestCase
{
    use Styles;

    private const string SECT = '/w:document/w:body/w:sectPr';

    /** @return array{string, string, ?string} */
    private static function pageSize(Page $page): array
    {
        $d = self::export($page->body(Text::make('x')));

        return [(string) $d->value(self::SECT . '/w:pgSz/@w:w'), (string) $d->value(self::SECT . '/w:pgSz/@w:h'), $d->value(self::SECT . '/w:pgSz/@w:orient')];
    }

    public function test_page_sizes(): void
    {
        self::assertSame(['11906', '16838', 'portrait'], self::pageSize(Page::a4()));
        self::assertSame(['15840', '12240', 'landscape'], self::pageSize(Page::letter()->landscape()));
        self::assertSame(['5669', '8504', 'portrait'], self::pageSize(Page::size(mm(100), mm(150))));
        self::assertSame([], self::exportedReports());
    }

    public function test_margins_and_header_footer_distances(): void
    {
        $d = self::export(Page::a4()->margin(mm(20))->body(Text::make('x')));
        foreach (['top', 'right', 'bottom', 'left', 'header', 'footer'] as $side) {
            self::assertSame('1134', $d->value(self::SECT . "/w:pgMar/@w:$side"), $side);
        }
    }

    public function test_a_page_with_another_setup_starts_a_section(): void
    {
        $d = self::export(Document::make(
            Page::a4()->body(Text::make('one')),
            Page::a5()->header(Text::make('h'))->body(Text::make('two')),
        ));
        self::assertSame(0, $d->count("//w:br[@w:type='page']"));
        self::assertSame(['one', 'two'], $d->values('/w:document/w:body/w:p/w:r/w:t[normalize-space()]'));
        // The first section ends in its last paragraph, the second at the end of the body.
        self::assertSame(['11906', '16838'], [$d->value('//w:p/w:pPr/w:sectPr/w:pgSz/@w:w'), $d->value('//w:p/w:pPr/w:sectPr/w:pgSz/@w:h')]);
        self::assertSame(['8391', '11906'], [$d->value(self::SECT . '/w:pgSz/@w:w'), $d->value(self::SECT . '/w:pgSz/@w:h')]);
        self::assertSame(0, $d->count('//w:p/w:pPr/w:sectPr/w:headerReference'));
        self::assertSame(1, $d->count(self::SECT . '/w:headerReference'));
        self::assertSame([], self::exportedReports());
    }

    public function test_pages_that_look_alike_share_a_section(): void
    {
        $d = self::export(Document::make(
            Page::a4()->header(Text::make('h'))->body(Text::make('one')),
            Page::a4()->header(Text::make('h'))->body(Text::make('two')),
        ));
        self::assertSame(1, $d->count("//w:br[@w:type='page']"));
        self::assertSame(1, $d->count('//w:sectPr'));
    }

    public function test_a_later_section_without_a_header_does_not_show_the_previous_one(): void
    {
        $d = self::export(Document::make(
            Page::a4()->header(Text::make('h'))->footer(Text::make('f'))->body(Text::make('one')),
            Page::a4()->body(Text::make('two')),
        ));
        self::assertSame(2, $d->count('//w:sectPr'));
        $last = (string) $d->value(self::SECT . "/w:headerReference[@w:type='default']/@r:id");
        $target = $d->xpath('word/_rels/document.xml.rels')->evaluate("string(//*[@Id='$last']/@Target)");
        self::assertSame('', trim($d->bodyText('word/' . $target)));
        self::assertSame(1, $d->count(self::SECT . "/w:footerReference[@w:type='default']"));
    }

    public function test_no_blank_page_between_sections(): void
    {
        foreach ([
            Document::make(Page::a4()->body(Text::make('one')->breakAfter()), Page::a5()->body(Text::make('two'))),
            Document::make(Page::a4()->body(Text::make('one')), Page::a5()->body(Text::make('two')->breakBefore())),
            Document::make(Page::a4()->body(Text::make('one')), Page::a5()->body(PageBreak::make(), Text::make('two'))),
        ] as $doc) {
            $d = self::export($doc);
            self::assertSame(0, $d->count("//w:br[@w:type='page']") + $d->count('//w:pageBreakBefore'));
            self::assertSame(2, $d->count('//w:sectPr'));
        }
    }

    public function test_a_page_opening_with_a_page_break_breaks_once(): void
    {
        $d = self::export(Document::make(Page::a4()->body(Text::make('one')), Page::a4()->body(PageBreak::make(), Text::make('two'))));
        self::assertSame(1, $d->count("//w:br[@w:type='page']"));
    }

    public function test_core_properties(): void
    {
        $doc = Document::make(Page::a4()->body(Text::make('x')))
            ->title('Invoice 42')->author('Papercut')->subject('Billing')->keywords('invoice, test')
            ->createdAt(new \DateTimeImmutable('2026-01-02T03:04:05Z'));
        $d = self::export($doc);
        $core = 'docProps/core.xml';
        self::assertSame('Invoice 42', $d->value('//dc:title', $core));
        self::assertSame('Papercut', $d->value('//dc:creator', $core));
        self::assertSame('Billing', $d->value('//dc:subject', $core));
        self::assertSame('invoice, test', $d->value('//cp:keywords', $core));
        self::assertSame('2026-01-02T03:04:05Z', $d->value('//dcterms:created', $core));
    }

    public function test_an_empty_body_still_ends_in_a_paragraph(): void
    {
        foreach ([Page::a4(), Page::a4()->header(Text::make('only a header'))] as $page) {
            $d = self::export($page);
            self::assertSame(1, $d->count('/w:document/w:body/*[last()][self::w:sectPr]'));
            self::assertSame(1, $d->count('/w:document/w:body/*[last()-1][self::w:p]'));
        }
    }

    public function test_a_body_ending_in_a_table_ends_in_a_paragraph(): void
    {
        $d = self::export(Text::make('x')->padding(pt(4)));
        // Our hairline, not php-docx's default-height paragraph, which can spill onto a new page.
        self::assertSame('exact', $d->value('/w:document/w:body/*[last()-1][self::w:p]/w:pPr/w:spacing/@w:lineRule'));
        self::assertSame(1, $d->count('/w:document/w:body/*[last()-2][self::w:tbl]'));
    }

    public function test_no_blank_page_when_a_page_already_breaks(): void
    {
        $d = self::export(Document::make(Page::a4()->body(Text::make('one')->breakAfter()), Page::a4()->body(Text::make('two'))));
        self::assertSame(1, $d->count("//w:br[@w:type='page']") + $d->count('//w:pageBreakBefore'));
        $d = self::export(Document::make(Page::a4()->body(Text::make('one')), Page::a4()->body(Text::make('two')->breakBefore())));
        self::assertSame(1, $d->count("//w:br[@w:type='page']") + $d->count('//w:pageBreakBefore'));
    }

    public function test_mirrored_run_turns_on_mirror_margins(): void
    {
        $d = self::export(Page::a4()->margin(mm(20), mm(30), mm(20), mm(10))->mirrorMargins()->body(Text::make('x')));
        self::assertSame(1, $d->count('//w:mirrorMargins', 'word/settings.xml'));
        self::assertSame('567', $d->value(self::SECT . '/w:pgMar/@w:left'));
        self::assertSame('1701', $d->value(self::SECT . '/w:pgMar/@w:right'));
        self::assertSame([], self::exportedReports());
    }

    public function test_mixing_mirrored_and_unequal_margins_is_approximated(): void
    {
        $mirrored = Page::a4()->mirrorMargins()->body(Text::make('one'));
        $d = self::export(Document::make($mirrored, Page::a5()->margin(mm(20), mm(30), mm(20), mm(20))->body(Text::make('two'))));
        self::assertSame(['Approximated mirrored margins @ page[1]'], self::exportedReports());

        self::export(Document::make($mirrored, Page::a5()->body(Text::make('two'))));
        self::assertSame([], self::exportedReports());
        self::export(Document::make(Page::a5()->margin(mm(20), mm(30), mm(20), mm(20))->body(Text::make('two'))));
        self::assertCount(0, self::exportedReports());
    }

    public function test_mirrored_docx_opens(): void
    {
        $bytes = Document::make(Page::a4()->mirrorMargins()->body(Text::make('x')))->export(new \Papercut\Docx\DocxExporter())->bytes();
        $file = (string) tempnam(sys_get_temp_dir(), 'docx');
        file_put_contents($file, $bytes);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($file));
        preg_match_all('~Target="([^"]+)"~', (string) $zip->getFromName('word/_rels/document.xml.rels'), $m);
        foreach ($m[1] as $target) {
            if (!str_starts_with($target, 'http')) {
                self::assertNotFalse($zip->locateName('word/' . $target), $target);
            }
        }
        self::assertNotFalse($zip->locateName('word/settings.xml'));
        $zip->close();
        unlink($file);
    }
}
