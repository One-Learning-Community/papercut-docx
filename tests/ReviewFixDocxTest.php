<?php

declare(strict_types=1);

namespace Quoin\Docx\Tests;

use Quoin\{Column, Document, Image, Page, Row, Table, TableCell, Text, View};
use Quoin\Docx\{DocxExporter, DocxOptions};
use Quoin\Docx\Tests\Support\OoxmlValidator;
use Quoin\Markup\Markup;
use PHPUnit\Framework\TestCase;

use function Quoin\{mm, pt};

/** The final fix wave in Word: notes that every format reports, and the backstop clamps that used to be silent. */
final class ReviewFixDocxTest extends TestCase
{
    protected function setUp(): void
    {
        set_error_handler(static function (int $no, string $msg, string $file, int $line): never {
            throw new \ErrorException($msg, 0, $no, $file, $line);
        });
    }

    protected function tearDown(): void
    {
        restore_error_handler();
    }

    /** @return array{string, list<string>} the bytes and every message */
    private static function export(Document $doc): array
    {
        $messages = [];
        $bytes = $doc->export(new DocxExporter(), new DocxOptions(onWarning: static function (string $m) use (&$messages): void {
            $messages[] = $m;
        }))->bytes();

        return [$bytes, $messages];
    }

    /** @param list<string> $messages */
    private static function noteCount(array $messages, string $needle): int
    {
        return count(array_filter($messages, static fn (string $m): bool => str_contains($m, $needle)));
    }

    private static function png(): string
    {
        $im = imagecreatetruecolor(40, 20);
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    public function test_colspan_past_column_1000_is_noted_once_and_the_file_validates(): void
    {
        [$bytes, $notes] = self::export(Document::make(Page::a4()->body(Table::make()->row(TableCell::make('a')->colspan(2000))->row('b', 'c'))));
        self::assertSame(1, self::noteCount($notes, 'colspan runs past column 1000; clamped'), implode("\n", $notes));
        OoxmlValidator::assertValid($bytes);
    }

    public function test_a_table_with_more_columns_than_word_shows_is_noted(): void
    {
        $cells = array_fill(0, 70, 'x');
        [, $notes] = self::export(Document::make(Page::a4()->body(Table::make()->row(...$cells))));
        self::assertSame(1, self::noteCount($notes, 'Word shows at most 63 columns'), implode("\n", $notes));

        [, $few] = self::export(Document::make(Page::a4()->body(Table::make()->row(...array_fill(0, 10, 'x')))));
        self::assertSame(0, self::noteCount($few, 'Word shows at most'));
    }

    public function test_a_border_wider_than_word_allows_is_noted(): void
    {
        [$bytes, $notes] = self::export(Document::make(Page::a4()->body(Column::make(Text::make('x'))->border(pt(20)))));
        self::assertSame(1, self::noteCount($notes, 'border wider than Word allows (12pt), clamped'), implode("\n", $notes));
        OoxmlValidator::assertValid($bytes);

        [, $fine] = self::export(Document::make(Page::a4()->body(Column::make(Text::make('x'))->border(pt(12)))));
        self::assertSame(0, self::noteCount($fine, 'border wider'));
    }

    public function test_a_font_size_larger_than_word_allows_is_noted(): void
    {
        [$bytes, $notes] = self::export(Document::make(Page::a4()->body(Text::make('x')->size(pt(2000)))));
        self::assertSame(1, self::noteCount($notes, 'font size larger than Word allows (1638pt), clamped'), implode("\n", $notes));
        OoxmlValidator::assertValid($bytes);

        [, $span] = self::export(Document::make(Page::a4()->body(Text::spans(new \Quoin\Span('big', new \Quoin\Style\Style(fontSize: pt(2000)))))));
        self::assertSame(1, self::noteCount($span, 'font size larger than Word allows'), implode("\n", $span));

        [, $fine] = self::export(Document::make(Page::a4()->body(Text::make('x')->size(pt(400)))));
        self::assertSame(0, self::noteCount($fine, 'font size larger'));
    }

    public function test_a_badge_border_wider_than_word_allows_is_noted_once(): void
    {
        $badge = new \Quoin\Span('tag', new \Quoin\Style\Style(borderWidth: \Quoin\Value\Edges::all(pt(20)), padding: \Quoin\Value\Edges::all(pt(2))));
        [$bytes, $notes] = self::export(Document::make(Page::a4()->body(Text::spans($badge, new \Quoin\Span(' and '), $badge))));
        self::assertSame(1, self::noteCount($notes, 'border wider than Word allows (12pt), clamped'), implode("\n", $notes));
        OoxmlValidator::assertValid($bytes);
    }

    public function test_an_inherited_font_size_clamp_is_noted_once_at_the_node_that_set_it(): void
    {
        [, $notes] = self::export(Document::make(Page::a4()->body(Column::make(Text::make('a'), Text::make('b'))->size(pt(2000)))));
        self::assertSame(1, self::noteCount($notes, 'font size larger than Word allows'), implode("\n", $notes));
    }

    public function test_a_row_that_runs_past_the_edge_is_noted_like_the_pdf(): void
    {
        $row = Row::make(Text::make('a')->minWidth(pt(400)), Text::make('b')->minWidth(pt(400)));
        [, $notes] = self::export(Document::make(Page::a4()->body($row)));
        self::assertSame(1, self::noteCount($notes, 'row content wider than the row'), implode("\n", $notes));

        [, $img] = self::export(Document::make(Page::a4()->margin(mm(15))->body(Row::make(Image::bytes(self::png())->width(mm(250))->shrink(0)))));
        self::assertSame(1, self::noteCount($img, 'row content wider than the row'), implode("\n", $img));
    }

    public function test_a_row_that_fits_or_shrinks_makes_no_row_note(): void
    {
        [, $notes] = self::export(Document::make(Page::a4()->body(Row::make(Text::make('LEFTT'), Text::make(str_repeat('W', 120))))));
        self::assertSame(0, self::noteCount($notes, 'row content wider'), implode("\n", $notes));
    }

    public function test_a_table_in_a_view_in_a_row_gets_only_its_own_note(): void
    {
        $table = Table::make()->columns('auto auto')->row('aa', str_repeat('a', 400));
        [, $notes] = self::export(Document::make(Page::a4()->body(Row::make(View::make($table)))));
        self::assertSame(0, self::noteCount($notes, 'row content wider'), implode("\n", $notes));
    }

    public function test_invalid_utf8_in_pasted_markup_is_noted_once(): void
    {
        [, $notes] = self::export(Document::make(Page::a4()->body(Markup::html("<p>\xff\xfe bad \xc3</p><p>again \xff</p>"))));
        self::assertSame(1, self::noteCount($notes, 'invalid UTF-8 replaced with U+FFFD'), implode("\n", $notes));
    }
}
