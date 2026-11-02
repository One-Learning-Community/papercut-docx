<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests;

use Papercut\{ColumnBreak, ColumnFlow, Column, Document, Page, Table, TableCell, Text, View};
use Papercut\Docx\Tests\Support\{DocxInspector, Styles};
use Papercut\Exception\InvalidStructure;
use Papercut\Value\{ColumnSpec, Color, ColumnRule};
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

final class ColumnFlowDocxTest extends TestCase
{
    use Styles;

    private const string ANY_SECT = '//w:sectPr';

    public function test_on_page_flow_is_a_continuous_section_with_cols(): void
    {
        $d = self::export(Page::a4()->body(ColumnFlow::make(Text::make('one'), Text::make('two'))->columns(2)));
        self::assertSame(2, $d->count(self::ANY_SECT));
        self::assertSame(1, $d->count('//w:cols'));
        // The flow's section is the first one and starts the page; the levelling section after it is the body's own.
        self::assertSame(1, $d->count('/w:document/w:body/w:p[2]/w:pPr/w:sectPr/w:cols'));
        self::assertSame(0, $d->count('/w:document/w:body/w:p/w:pPr/w:sectPr/w:type'));
        self::assertSame(1, $d->count('/w:document/w:body/w:sectPr/w:type[@w:val="continuous"]'));
        self::assertSame('2', $d->value('//w:sectPr/w:cols/@w:num'));
        self::assertSame('720', $d->value('//w:sectPr/w:cols/@w:space'));
        self::assertSame(['one', 'two'], $d->values('//w:t[normalize-space()]'));
        self::assertSame([], self::exportedReports());
    }

    public function test_title_then_flow_then_note_gives_three_sections_on_one_page(): void
    {
        $d = self::export(Page::a4()->body(
            Text::make('title'),
            ColumnFlow::make(Text::make('a'), Text::make('b'))->columns(2)->columnRule(pt(0.5), Color::hex('#000000')),
            Text::make('note'),
        ));
        self::assertSame(3, $d->count(self::ANY_SECT));
        self::assertSame(0, $d->count("//w:br[@w:type='page']"));
        // Document order: title section, flow section, note section (the body's own sectPr).
        $types = $d->values('//w:sectPr/w:type/@w:val');
        self::assertSame(['continuous', 'continuous'], $types);
        self::assertSame(1, $d->count('//w:cols'));
        self::assertSame(1, $d->count('/w:document/w:body/w:p[3]/w:pPr/w:sectPr/w:cols[@w:sep="1"]'));
        self::assertSame(0, $d->count('/w:document/w:body/w:p[1]/w:pPr/w:sectPr/w:type'));
        self::assertSame(['title', 'a', 'b', 'note'], $d->values('//w:t[normalize-space()]'));
    }

    public function test_a_leading_flow_takes_the_page_start(): void
    {
        $d = self::export(Document::make(
            Page::a4()->body(Text::make('first')),
            Page::a5()->body(ColumnFlow::make(Text::make('a'))->columns(2), Text::make('after')),
        ));
        // Section 2 (the flow) starts the new page; no empty section sits between.
        self::assertSame(3, $d->count(self::ANY_SECT));
        self::assertSame(['continuous'], $d->values('//w:sectPr/w:type/@w:val'));
        self::assertSame(1, $d->count('/w:document/w:body/w:p[2]/w:pPr/w:sectPr/w:cols'));
    }

    public function test_flow_before_a_new_template_gets_a_levelling_section(): void
    {
        $d = self::export(Document::make(
            Page::a4()->body(ColumnFlow::make(Text::make('a'), Text::make('b'))->columns(2)),
            Page::a5()->body(Text::make('next')),
        ));
        // flow (next page), hairline levelling (continuous), A5 section (next page).
        self::assertSame(3, $d->count(self::ANY_SECT));
        self::assertSame(['continuous'], $d->values('//w:sectPr/w:type/@w:val'));
        self::assertSame(1, $d->count('//w:cols'));
        self::assertSame(1, $d->count('/w:document/w:body/w:p[2]/w:pPr/w:sectPr/w:cols'));
        self::assertSame(1, $d->count('/w:document/w:body/w:p[3]/w:pPr/w:sectPr/w:type[@w:val="continuous"]'));
        self::assertSame(0, $d->count('/w:document/w:body/w:p[3]//w:t[normalize-space()]'));
        self::assertSame(0, $d->count('/w:document/w:body/w:sectPr/w:type'));
    }

    public function test_last_flow_in_the_document_gets_a_levelling_section(): void
    {
        $d = self::export(Page::a4()->body(Text::make('t'), ColumnFlow::make(Text::make('a'))->columns(2)));
        self::assertSame(3, $d->count(self::ANY_SECT));
        self::assertSame(0, $d->count('/w:document/w:body/w:sectPr/w:cols'));
    }

    public function test_flow_in_a_card_is_one_column_with_a_note(): void
    {
        $d = self::export(Page::a4()->body(View::make(ColumnFlow::make(Text::make('a'), Text::make('b'))->columns(2))->padding(pt(6))));
        self::assertSame(1, $d->count(self::ANY_SECT));
        self::assertSame(0, $d->count('//w:cols'));
        self::assertSame(['Approximated columns inside a box @ page > body > column[0] > column-flow[0]'], self::exportedReports());
        self::assertSame(['a', 'b'], $d->values('//w:t[normalize-space()]'));
    }

    public function test_markup_flow_in_a_cell_is_one_column_with_a_note(): void
    {
        $flow = ColumnFlow::make(Text::make('a'))->withColumnSpec((ColumnSpec::equal(2))->withFromMarkup());
        $d = self::export(Table::make()->columns('1fr')->row(TableCell::make($flow)));
        self::assertSame(0, $d->count('//w:cols'));
        self::assertSame(1, $d->count(self::ANY_SECT));
        $reports = self::exportedReports();
        self::assertCount(1, $reports);
        self::assertStringContainsString('columns in a table cell', $reports[0]);
    }

    public function test_builder_flow_in_a_cell_throws(): void
    {
        $this->expectException(InvalidStructure::class);
        $this->expectExceptionMessage('inside a table cell');
        self::export(Table::make()->columns('1fr')->row(TableCell::make(ColumnFlow::make(Text::make('a'))->columns(2))));
    }

    public function test_column_break_becomes_w_br_column(): void
    {
        $d = self::export(Page::a4()->body(ColumnFlow::make(Text::make('a'), ColumnBreak::make(), Text::make('b'))->columns(2)));
        self::assertSame(1, $d->count("//w:p/w:r/w:br[@w:type='column']"));
        self::assertSame(['a', 'b'], $d->values('//w:t[normalize-space()]'));
        self::assertSame([], self::exportedReports());
    }

    public function test_column_break_in_a_boxed_flow_is_dropped_with_a_note(): void
    {
        $d = self::export(Page::a4()->body(View::make(ColumnFlow::make(Text::make('a'), ColumnBreak::make(), Text::make('b'))->columns(2))->padding(pt(6))));
        self::assertSame(0, $d->count("//w:br[@w:type='column']"));
        self::assertCount(2, self::exportedReports());
        self::assertStringContainsString('column break', self::exportedReports()[1]);
    }

    public function test_stray_column_break_throws(): void
    {
        $this->expectException(InvalidStructure::class);
        self::export(Page::a4()->body(Column::make(ColumnBreak::make())));
    }

    public function test_shares_write_per_column_widths(): void
    {
        $spec = new ColumnSpec(2, [1.0, 2.0], [36.0]);
        $d = self::export(Page::a4()->margin(pt(72))->body(ColumnFlow::make(Text::make('a'))->withColumnSpec($spec)));
        // A4 is 595.3pt wide; 72pt margins leave 451.3pt, less the 36pt gap, shared 1:2.
        self::assertSame('0', $d->value('//w:cols/@w:equalWidth'));
        self::assertSame(2, $d->count('//w:cols/w:col'));
        self::assertSame('720', $d->value('//w:cols/w:col[1]/@w:space'));
        self::assertNull($d->value('//w:cols/w:col[2]/@w:space'));
        self::assertSame(['2769', '5537'], $d->values('//w:cols/w:col/@w:w'));
    }

    public function test_a_page_break_after_the_flow_does_not_leave_it_unlevelled(): void
    {
        $d = self::export(Page::a4()->body(ColumnFlow::make(Text::make('a'))->columns(2)->breakAfter(), Text::make('next')));
        self::assertSame(1, $d->count('//w:cols'));
        // A continuous section follows the flow's, and the break after the flow is the only page break.
        self::assertSame(1, $d->count('/w:document/w:body/w:p[1]/w:pPr/w:sectPr/w:cols'));
        self::assertSame(1, $d->count('/w:document/w:body/w:sectPr/w:type[@w:val="continuous"]'));
        self::assertSame(1, $d->count("//w:br[@w:type='page']"));
    }

    public function test_two_flows_on_one_page(): void
    {
        $d = self::export(Page::a4()->body(
            Text::make('t'),
            ColumnFlow::make(Text::make('a'))->columns(2),
            ColumnFlow::make(Text::make('b'))->columns(3),
        ));
        // plain, cols 2, cols 3, levelling.
        self::assertSame(4, $d->count(self::ANY_SECT));
        self::assertSame(['2', '3'], $d->values('//w:cols/@w:num'));
        self::assertSame(['continuous', 'continuous', 'continuous'], $d->values('//w:sectPr/w:type/@w:val'));
        self::assertSame(1, $d->count('/w:document/w:body/w:p[2]/w:pPr/w:sectPr/w:cols[@w:num="2"]'));
        self::assertSame(1, $d->count('/w:document/w:body/w:p[3]/w:pPr/w:sectPr/w:cols[@w:num="3"]'));
        self::assertSame(0, $d->count('/w:document/w:body/w:sectPr/w:cols'));
    }

    public function test_a_later_template_starting_with_a_flow_drops_its_leading_break(): void
    {
        $d = self::export(Document::make(
            Page::a4()->body(Text::make('first')),
            Page::a5()->body(ColumnFlow::make(Text::make('a')->breakBefore(), Text::make('b'))->columns(2)),
        ));
        self::assertSame(0, $d->count('//w:pageBreakBefore'));
        self::assertSame(0, $d->count("//w:br[@w:type='page']"));
    }

    public function test_body_text_equal_to_the_column_break_marker_survives(): void
    {
        $d = self::export(Page::a4()->body(ColumnFlow::make(Text::make('papercut-column-break'), ColumnBreak::make(), Text::make('see papercut-column-break'))->columns(2)));
        self::assertSame(1, $d->count("//w:br[@w:type='column']"));
        self::assertSame(['papercut-column-break', 'see papercut-column-break'], $d->values('//w:t[normalize-space()]'));
    }

    public function test_a_table_in_a_flow_is_sized_to_its_column(): void
    {
        $table = Table::make()->columns('1fr 1fr 1fr')->row(TableCell::make(Text::make('a')), TableCell::make(Text::make('b')), TableCell::make(Text::make('c')));
        $d = self::export(Page::a4()->margin(pt(72))->body(ColumnFlow::make($table)->withColumnSpec(new ColumnSpec(2, null, [36.0]))));
        // 451.3pt less the 36pt gap, over two columns: 207.6pt = 4153 twips.
        $column = (int) round((595.276 - 144 - 36) / 2 * 20);
        self::assertLessThanOrEqual($column, (int) $d->value('//w:tblW/@w:w'));
        self::assertEqualsWithDelta($column, array_sum(array_map('intval', $d->values('//w:tblGrid/w:gridCol/@w:w'))), 3);
    }

    public function test_a_bordered_box_in_a_flow_is_sized_to_its_column(): void
    {
        $box = View::make(Text::make('boxed'))->padding(pt(6))->border(pt(1), Color::hex('#000000'));
        $d = self::export(Page::a4()->margin(pt(72))->body(ColumnFlow::make($box)->withColumnSpec(new ColumnSpec(2, null, [36.0]))));
        $column = (int) round((595.276 - 144 - 36) / 2 * 20);
        self::assertLessThanOrEqual($column, (int) $d->value('//w:tblW/@w:w'));
    }

    public function test_unequal_shares_size_to_the_narrowest_column(): void
    {
        $table = Table::make()->columns('1fr')->row(TableCell::make(Text::make('a')));
        $d = self::export(Page::a4()->margin(pt(72))->body(ColumnFlow::make($table)->withColumnSpec(new ColumnSpec(2, [1.0, 2.0], [36.0]))));
        self::assertLessThanOrEqual(2769, (int) $d->value('//w:tblW/@w:w'));
    }
}
