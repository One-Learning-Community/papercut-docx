<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests\Package;

use Papercut\{Document, Page, Text};
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

    public function test_pages_are_joined_by_page_breaks_and_later_setups_are_approximated(): void
    {
        $d = self::export(Document::make(
            Page::a4()->body(Text::make('one')),
            Page::a5()->header(Text::make('h'))->body(Text::make('two')),
        ));
        self::assertSame(1, $d->count("//w:br[@w:type='page']"));
        self::assertSame(['one', 'two'], $d->values('/w:document/w:body/w:p/w:r/w:t[normalize-space()]'));
        self::assertSame([
            "Approximated page setup other than the first page's @ page[1]",
            "Approximated header or footer other than the first page's @ page[1]",
        ], self::exportedReports());
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
}
