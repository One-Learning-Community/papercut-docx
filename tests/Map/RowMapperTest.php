<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests\Map;

use Papercut\{Column, Page, Row, Spacer, Text, View};
use Papercut\Docx\Map\{BoxMapper, RowMapper};
use Papercut\Docx\Tests\Support\{DocxInspector, Styles};
use Papercut\Export\ExportOptions;
use Papercut\Layout\WidthResolver;
use Papercut\Style\Align;
use PHPUnit\Framework\TestCase;

use function Papercut\{mm, pt};

/** @phpstan-import-type Segment from RowMapper */
final class RowMapperTest extends TestCase
{
    use Styles;

    private const string TBL = '/w:document/w:body/w:tbl';

    /** A4 with 20mm margins: the body's content width in pt. */
    private static function bodyWidth(): float
    {
        $page = Page::a4();

        return $page->width() - $page->margins()->left->points - $page->margins()->right->points;
    }

    /** @return list<int> */
    private static function grid(DocxInspector $d, string $table = self::TBL): array
    {
        return array_map('intval', $d->values("$table/w:tblGrid/w:gridCol/@w:w"));
    }

    public function test_grid_matches_width_resolver_without_free_space(): void
    {
        $row = Row::make(
            View::make(Text::make('a'))->width(pt(120))->background('slate-100'),
            Column::make(Text::make('b'))->grow(),
            View::make(Text::make('c'))->width(mm(30))->background('slate-100'),
        );
        $d = self::export($row);
        $slots = WidthResolver::for(new ExportOptions())->flexLines($row, self::styleOf($row), self::bodyWidth())[0];
        $grid = self::grid($d);
        self::assertCount(3, $grid);
        foreach ($slots as $i => $slot) {
            self::assertEqualsWithDelta($slot->width * 20, $grid[$i], 1.0);
        }
    }

    public function test_row_gap_is_an_empty_column(): void
    {
        $d = self::export(Row::make(Column::make(Text::make('a'))->grow(), Column::make(Text::make('b'))->grow())->gap(pt(16)));
        $grid = self::grid($d);
        self::assertCount(3, $grid);
        self::assertSame(320, $grid[1]);
        self::assertSame(0, $d->count(self::TBL . '/w:tr/w:tc[2]//w:t[normalize-space()]'));
        self::assertSame('exact', $d->value(self::TBL . '/w:tr/w:tc[2]/w:p/w:pPr/w:spacing/@w:lineRule'));
    }

    public function test_row_padding_folds_into_the_edge_cells(): void
    {
        $d = self::export(Row::make(Column::make(Text::make('a'))->grow(), Column::make(Text::make('b'))->grow())->padding(pt(12))->background('slate-50'));
        self::assertCount(2, self::grid($d));
        self::assertSame('240', $d->value(self::TBL . '/w:tr/w:tc[1]/w:tcPr/w:tcMar/w:left/@w:w'));
        self::assertSame('240', $d->value(self::TBL . '/w:tr/w:tc[2]/w:tcPr/w:tcMar/w:right/@w:w'));
        self::assertSame(['f8fafc', 'f8fafc'], $d->values(self::TBL . '/w:tr/w:tc/w:tcPr/w:shd/@w:fill'));
    }

    public function test_vertical_alignment(): void
    {
        $d = self::export(Row::make(
            Column::make(Text::make('a'))->grow(),
            Column::make(Text::make('b'))->grow()->alignSelf(Align::End),
        )->align(Align::Center));
        self::assertSame('center', $d->value(self::TBL . '/w:tr/w:tc[1]/w:tcPr/w:vAlign/@w:val'));
        self::assertSame('bottom', $d->value(self::TBL . '/w:tr/w:tc[2]/w:tcPr/w:vAlign/@w:val'));
    }

    /**
     * @param array{float, float, float, ?Text} ...$parts x0, x1, free space, the item's node (null: empty)
     * @return list<Segment>
     */
    private static function segs(array ...$parts): array
    {
        $out = [];
        foreach ($parts as [$x0, $x1, $free, $node]) {
            $out[] = ['x0' => $x0, 'x1' => $x1, 'item' => $node === null ? null : ['blocks' => [], 'style' => self::styleOf($node), 'index' => 0, 'boxed' => false], 'pad' => [0.0, 0.0], 'free' => $free];
        }

        return $out;
    }

    private static function mapper(): RowMapper
    {
        $f = self::fidelity();

        return new RowMapper($f, new BoxMapper($f));
    }

    public function test_content_sized_items_borrow_slack_from_free_space(): void
    {
        $out = self::mapper()->settle(self::segs(
            [0.0, 50.0, 0.0, Text::make('Subtotal')],
            [50.0, 450.0, 400.0, null],
            [450.0, 500.0, 0.0, Text::make('$7,615.00')->right()],
        ), 0.0, 0.0);
        self::assertSame([[0.0, 61.5], [61.5, 438.5], [438.5, 500.0]], array_map(static fn (array $s): array => [$s['x0'], $s['x1']], $out));
    }

    public function test_a_row_gap_lends_slack_too(): void
    {
        $row = Row::make(View::make(Text::make('Respondent')), Column::make(Text::make('Jane Smith'))->grow())->gap(pt(8));
        $d = self::export($row);
        $slots = WidthResolver::for(new ExportOptions())->flexLines($row, self::styleOf($row), self::bodyWidth())[0];
        $grid = array_map('intval', $d->values(self::TBL . '/w:tblGrid/w:gridCol/@w:w'));
        // the label takes half the gap; the value column doesn't move
        self::assertEqualsWithDelta(($slots[0]->width + 4) * 20, $grid[0], 1.0);
        self::assertEqualsWithDelta($slots[1]->x * 20, $grid[0] + $grid[1], 1.0);
    }

    public function test_slack_is_capped_at_half_the_free_space(): void
    {
        $out = self::mapper()->settle(self::segs(
            [0.0, 100.0, 0.0, Text::make('a')],
            [100.0, 110.0, 10.0, null],
        ), 0.0, 0.0);
        self::assertSame(105.0, $out[0]['x1']);
    }

    public function test_decorated_items_get_no_slack(): void
    {
        $out = self::mapper()->settle(self::segs(
            [0.0, 50.0, 0.0, Text::make('a')->background('slate-100')],
            [50.0, 450.0, 400.0, null],
        ), 0.0, 0.0);
        self::assertSame(50.0, $out[0]['x1']);
    }

    public function test_a_plain_growing_sibling_lends_a_little(): void
    {
        $out = self::mapper()->settle(self::segs(
            [0.0, 200.0, 0.0, Text::make('a')],
            [200.0, 300.0, 20.0, Text::make('b')->grow()],
        ), 0.0, 0.0);
        self::assertSame(210.0, $out[0]['x1']);
        self::assertSame(210.0, $out[1]['x0']);
    }

    public function test_wrapped_rows_become_one_table_row_per_line(): void
    {
        $d = self::export(Row::make(
            View::make(Text::make('a'))->width(pt(300)),
            View::make(Text::make('b'))->width(pt(300)),
        )->wrap());
        self::assertSame(2, $d->count(self::TBL . '/w:tr'));
        self::assertSame(['Approximated wrap @ page > body > row[0]'], self::exportedReports());
    }

    /** @return iterable<string, array{View}> */
    public static function awkwardRows(): iterable
    {
        yield 'overflow' => [Row::make(View::make(Text::make('a'))->width(pt(400)), View::make(Text::make('b'))->width(pt(400)))];
        yield 'zero-width spacer' => [Row::make(Text::make('a'), Spacer::size(pt(0)), Text::make('b'))];
        yield 'gap wider than the row' => [Row::make(Text::make('a'), Text::make('b'))->gap(pt(900))];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('awkwardRows')]
    public function test_grid_stays_valid_for_awkward_rows(View $row): void
    {
        $d = self::export($row);
        $grid = self::grid($d);
        self::assertNotSame([], $grid);
        foreach ($grid as $w) {
            self::assertGreaterThanOrEqual(1, $w);
        }
        $xp = $d->xpath();
        foreach ($xp->query(self::TBL . '/w:tr') ?: [] as $tr) {
            $span = 0;
            \assert($tr instanceof \DOMElement);
            foreach ($xp->query('w:tc', $tr) ?: [] as $tc) {
                \assert($tc instanceof \DOMElement);
                $span += (int) ($xp->evaluate('string(w:tcPr/w:gridSpan/@w:val)', $tc) ?: 1);
            }
            self::assertSame(count($grid), $span);
        }
    }

    public function test_rows_split_across_pages_unless_kept_together(): void
    {
        $row = static fn (): View => Row::make(Column::make(Text::make('a'))->grow(), Column::make(Text::make('b'))->grow());
        // Word clips a row that can't split and is taller than a page, so only keep-together rows refuse to split.
        self::assertSame(0, self::export($row())->count(self::TBL . '/w:tr/w:trPr/w:cantSplit'));
        self::assertSame(1, self::export($row()->keepTogether())->count(self::TBL . '/w:tr/w:trPr/w:cantSplit'));
    }
}
