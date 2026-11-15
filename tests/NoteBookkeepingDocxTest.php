<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests;

use Papercut\{Document, Page, PageBreak, Row, Span, Text};
use Papercut\Docx\{DocxExporter, DocxOptions};
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

/** Each fidelity note appears once, at the node it belongs to. */
final class NoteBookkeepingDocxTest extends TestCase
{
    protected function setUp(): void
    {
        set_error_handler(static function (int $no, string $msg): never {
            throw new \ErrorException($msg, 0, $no);
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
    }

    /** @return list<string> */
    private static function notes(Document $doc, bool $strict = false): array
    {
        $m = [];
        $doc->export(new DocxExporter(), new DocxOptions(onWarning: static function (string $x) use (&$m): void {
            $m[] = $x;
        }, strict: $strict));

        return $m;
    }

    public function test_row_and_child_radius_each_report_at_their_own_path(): void
    {
        $doc = Document::make(Page::a4()->body(Row::make(Text::p('a'), Text::p('b')->radius(pt(6)))->radius(pt(6))));
        self::assertSame([
            'docx: dropped radius at page > body > row[0]',
            'docx: dropped radius at page > body > row[0] > text[1]',
        ], self::notes($doc));
    }

    public function test_child_radius_alone_reports_at_the_child(): void
    {
        $doc = Document::make(Page::a4()->body(Row::make(Text::p('a'), Text::p('b')->radius(pt(6)))));
        self::assertSame(['docx: dropped radius at page > body > row[0] > text[1]'], self::notes($doc));
    }

    public function test_two_badges_in_a_paragraph_are_noted_once_each_per_paragraph(): void
    {
        $doc = Document::make(Page::a4()->body(Text::spans(Span::badge('one'), Span::text(' and '), Span::badge('two'))));
        self::assertSame(['docx: approximated badge in a line at page > body > text[0]'], self::notes($doc));
    }

    public function test_badges_in_two_paragraphs_are_noted_at_each(): void
    {
        $doc = Document::make(Page::a4()->body(Text::spans(Span::badge('one')), Text::spans(Span::badge('two'))));
        self::assertSame([
            'docx: approximated badge in a line at page > body > text[0]',
            'docx: approximated badge in a line at page > body > text[1]',
        ], self::notes($doc));
    }

    public function test_page_break_in_a_row_is_noted_like_the_pdf(): void
    {
        $doc = Document::make(Page::a4()->body(Row::make(Text::p('A'), PageBreak::make(), Text::p('B'))));
        self::assertSame(['docx: approximated page break inside a row at page > body > row[0] > page-break[1]'], self::notes($doc));
    }

    public function test_missing_glyphs_are_not_reported_in_word_even_when_strict(): void
    {
        $doc = Document::make(Page::a4()->body(Text::p('ひらがな x'), Row::make(Text::p('日本'), Text::p('b'))));
        self::assertSame([], self::notes($doc, strict: true));
    }
}
