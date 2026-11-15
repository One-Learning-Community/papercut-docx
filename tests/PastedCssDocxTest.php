<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests;

use Papercut\{Document, Page};
use Papercut\Docx\{DocxExporter, DocxOptions};
use Papercut\Docx\Tests\Support\DocxInspector;
use Papercut\Markup\{Markup, MarkupOptions, StyleMode};
use PHPUnit\Framework\TestCase;

/** Pasted CSS in Original mode exports to Word with the same notes as the PDF, and never throws. */
final class PastedCssDocxTest extends TestCase
{
    /** @return array{DocxInspector, list<string>} */
    private static function docx(string $html): array
    {
        $messages = [];
        $doc = Document::make(Page::a4()->body(Markup::html($html, new MarkupOptions(styles: StyleMode::Original))));
        $bytes = $doc->export(new DocxExporter(), new DocxOptions(onWarning: function (string $m) use (&$messages): void {
            $messages[] = $m;
        }))->bytes();
        return [DocxInspector::of($bytes), $messages];
    }

    /** @param list<string> $messages */
    private static function matching(array $messages, string $needle): int
    {
        return count(array_filter($messages, static fn (string $m): bool => str_contains($m, $needle)));
    }

    public function test_column_count_is_reduced_to_what_fits_with_one_note(): void
    {
        [$d, $messages] = self::docx('<div style="column-count:20"><p>a</p><p>b</p></div>');
        self::assertSame(1, self::matching($messages, 'markup column-count (reduced to 4'), implode("\n", $messages));
        self::assertSame(['4'], $d->values('//w:sectPr/w:cols/@w:num'));
    }

    public function test_absurd_column_count_is_one_note_only(): void
    {
        [, $messages] = self::docx('<div style="column-count:99999"><p>a</p><p>b</p></div>');
        self::assertSame(1, self::matching($messages, 'column'), implode("\n", $messages));
    }

    /** @return iterable<string, array{string}> */
    public static function negativeMargins(): iterable
    {
        yield 'all sides' => ['<div style="margin:-20px">a</div>'];
        yield 'top only' => ['<div style="margin:-30px 0 0 0">a</div>'];
        yield 'after a paragraph' => ['<p>a</p><div style="margin:-30px">b</div>'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('negativeMargins')]
    public function test_negative_margins_export(string $html): void
    {
        [$d] = self::docx($html);
        self::assertStringContainsString('a', $d->bodyText());
    }

    public function test_a_percent_width_cell_is_fitted_with_the_shared_note(): void
    {
        foreach (['100%', '150%', '99999%'] as $w) {
            [, $messages] = self::docx('<table><tr><td style="width:' . $w . '">a</td><td>b</td></tr></table>');
            self::assertSame(1, self::matching($messages, 'markup column widths (wider than the page; fitted)'), $w . ': ' . implode("\n", $messages));
            self::assertSame(0, self::matching($messages, 'Table columns need'), implode("\n", $messages));
        }
    }

    /** @return iterable<string, array{string}> */
    public static function percentTables(): iterable
    {
        $row = '<tr><td style="width:100%">a</td><td>b</td></tr>';
        yield 'in columns' => ['<div style="column-count:3"><table>' . $row . '</table></div>'];
        yield 'table 200px' => ['<table style="width:200px">' . $row . '</table>'];
        yield 'table 120px' => ['<table style="width:120px">' . $row . '</table>'];
        yield '14 autos' => ['<table><tr><td style="width:100%">a</td>' . str_repeat('<td>b</td>', 14) . '</tr></table>'];
        yield '20 autos' => ['<table><tr><td style="width:100%">a</td>' . str_repeat('<td>b</td>', 20) . '</tr></table>'];
        yield 'next to 50px' => ['<table><tr><td style="width:100%">a</td><td style="width:50px">b</td></tr></table>'];
        yield 'next to 200px' => ['<table><tr><td style="width:100%">a</td><td style="width:200px">b</td></tr></table>'];
        yield 'in 40% div' => ['<div style="width:40%"><table>' . $row . '</table></div>'];
        yield 'over 100%' => ['<table><tr><td style="width:150%">a</td><td>b</td></tr></table>'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('percentTables')]
    public function test_percent_tables_are_fitted_with_the_shared_note(string $html): void
    {
        [, $messages] = self::docx($html);
        self::assertSame(1, self::matching($messages, 'markup column widths (wider than the page; fitted)'), implode("\n", $messages));
        self::assertSame(0, self::matching($messages, 'Table columns need'), implode("\n", $messages));
    }

    public function test_a_narrow_column_next_to_an_overflowing_one_gets_no_fitted_note(): void
    {
        $word = str_repeat('<td>Supercalifragilisticexpialidocious</td>', 12);
        foreach (['<table><tr><td style="width:20px">x</td><td>' . str_repeat('W', 120) . '</td></tr></table>', '<table><tr><td style="width:5%">x</td>' . $word . '</tr></table>'] as $html) {
            [, $messages] = self::docx($html);
            self::assertSame(0, self::matching($messages, 'fitted'), implode("\n", $messages));
        }
    }
}
