<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests\Map;

use Papercut\{Column, Text, View};
use Papercut\Decorator\Card;
use Papercut\Docx\Tests\Support\Styles;
use Papercut\Style\{Align, BorderStyle, Style};
use Papercut\Value\Edges;
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

final class BoxMapperTest extends TestCase
{
    use Styles;

    private const string TBL = '/w:document/w:body/w:tbl';

    private static function panel(): View
    {
        return Column::make(Text::make('Inside'))->padding(pt(12))->background('slate-50')->border(pt(1), 'slate-200');
    }

    public function test_decorated_column_is_a_single_cell_table(): void
    {
        $d = self::export(self::panel());
        self::assertSame(1, $d->count(self::TBL));
        self::assertSame(1, $d->count(self::TBL . '/w:tr/w:tc'));
        self::assertSame('fixed', $d->value(self::TBL . '/w:tblPr/w:tblLayout/@w:type'));
        self::assertSame('240', $d->value(self::TBL . '//w:tc/w:tcPr/w:tcMar/w:left/@w:w'));
        self::assertSame('f8fafc', $d->value(self::TBL . '//w:tc/w:tcPr/w:shd/@w:fill'));
        self::assertSame('8', $d->value(self::TBL . '//w:tc/w:tcPr/w:tcBorders/w:top/@w:sz'));
        self::assertSame('e2e8f0', $d->value(self::TBL . '//w:tc/w:tcPr/w:tcBorders/w:top/@w:color'));
    }

    public function test_every_table_zeroes_cell_margins(): void
    {
        $d = self::export(self::panel());
        self::assertSame('0', $d->value(self::TBL . '/w:tblPr/w:tblCellMar/w:left/@w:w'));
        self::assertSame('0', $d->value(self::TBL . '/w:tblPr/w:tblCellMar/w:right/@w:w'));
    }

    public function test_dashed_border(): void
    {
        $d = self::export(Column::make(Text::make('x'))->border(pt(1), 'slate-200', BorderStyle::Dashed));
        self::assertSame('dashed', $d->value(self::TBL . '//w:tcBorders/w:top/@w:val'));
    }

    public function test_fixed_height_is_an_exact_row(): void
    {
        $d = self::export(Column::make(Text::make('x'))->padding(pt(4))->height(pt(30)));
        self::assertSame('600', $d->value(self::TBL . '/w:tr/w:trPr/w:trHeight/@w:val'));
        self::assertSame('exact', $d->value(self::TBL . '/w:tr/w:trPr/w:trHeight/@w:hRule'));
        self::assertSame(['Approximated fixed height @ page > body > column[0]'], self::exportedReports());
    }

    public function test_min_height_is_an_at_least_row(): void
    {
        $d = self::export(Column::make(Text::make('x'))->padding(pt(4))->minHeight(pt(40)));
        // 40 less the 4 + 4 padding, which LibreOffice adds to an at-least row
        self::assertSame('640', $d->value(self::TBL . '/w:tr/w:trPr/w:trHeight/@w:val'));
        self::assertNotSame('exact', $d->value(self::TBL . '/w:tr/w:trPr/w:trHeight/@w:hRule'));
        self::assertSame([], self::exportedReports());
    }

    public function test_a_lone_childs_min_height_reaches_the_box(): void
    {
        $d = self::export(Column::make(Column::make(Text::make('x'))->minHeight(pt(40)))->padding(pt(5))->border(pt(1), 'slate-200'));
        // the child's 40, plus the box's 5 + 5 padding and 1 + 1 border, less what LibreOffice adds (padding, top border)
        self::assertSame('820', $d->value(self::TBL . '/w:tr/w:trPr/w:trHeight/@w:val'));
        self::assertSame(1, $d->count('//w:tbl'));
    }

    public function test_radius_and_opacity_are_dropped(): void
    {
        self::export(Column::make(Text::make('x'))->padding(pt(4))->radius(pt(6)));
        self::assertSame(['Dropped radius @ page > body > column[0]'], self::exportedReports());
        self::export(Column::make(Text::make('x'))->padding(pt(4))->opacity(0.5));
        self::assertSame(['Dropped opacity @ page > body > column[0]'], self::exportedReports());
    }

    public function test_a_box_of_boxes_is_one_table_with_a_row_each(): void
    {
        $d = self::export(Card::make(Text::make('Body'))->title('Response details'));
        self::assertSame(1, $d->count('//w:tbl'));
        self::assertSame(2, $d->count(self::TBL . '/w:tr'));
        self::assertSame(0, $d->count(self::TBL . "//w:p[w:pPr/w:spacing/@w:lineRule='exact']"));
        // the card's own border on the table, the title's fill and padding on its row
        self::assertSame('8', $d->value(self::TBL . '/w:tblPr/w:tblBorders/w:top/@w:sz'));
        self::assertSame('f1f5f9', $d->value(self::TBL . '/w:tr[1]/w:tc/w:tcPr/w:shd/@w:fill'));
        self::assertSame('200', $d->value(self::TBL . '/w:tr[1]/w:tc/w:tcPr/w:tcMar/w:left/@w:w'));
        self::assertSame('200', $d->value(self::TBL . '/w:tr[2]/w:tc/w:tcPr/w:tcMar/w:left/@w:w'));
        self::assertSame(['Response details', 'Body'], $d->values(self::TBL . '/w:tr/w:tc/w:p/w:r/w:t'));
    }

    public function test_a_box_of_boxes_keeps_children_that_sit_in_from_its_edges(): void
    {
        // Stacked rows span the outer box, so a child with side margins or its own alignment stays a nested table.
        $child = static fn (): View => View::make(Text::make('x'))->padding(pt(4))->background('slate-100');
        $outer = static fn (View ...$c): View => Column::make(...$c)->border(pt(1), 'slate-200');

        self::assertSame(1, self::export($outer($child(), $child()))->count('//w:tbl'));
        self::assertSame(3, self::export($outer($child()->margin(pt(0), pt(12)), $child()))->count('//w:tbl'));
        self::assertSame(3, self::export($outer($child()->alignSelf(Align::Center), $child()))->count('//w:tbl'));
    }

    public function test_adjacent_boxes_are_kept_apart_by_one_hairline(): void
    {
        $d = self::export(Column::make(self::panel(), self::panel()));
        self::assertSame(2, $d->count(self::TBL));
        self::assertSame(1, $d->count(self::TBL . '[1]/following-sibling::*[1][self::w:p]'));
        self::assertSame(1, $d->count(self::TBL . '[1]/following-sibling::*[2][self::w:tbl]'));
    }

    public function test_a_box_aligned_in_its_column_shrinks_to_its_content(): void
    {
        $d = self::export(Column::make(
            View::make(Text::make('Tip'))->padding(pt(2))->background('slate-100')->alignSelf(Align::Start),
            View::make(Text::make('Tip'))->padding(pt(2))->background('slate-100')->alignSelf(Align::End),
        ));
        $widths = array_map('intval', $d->values(self::TBL . '/w:tblGrid/w:gridCol/@w:w'));
        self::assertCount(2, $widths);
        self::assertLessThan(1000, $widths[0]);
        self::assertSame($widths[0], $widths[1]);
        self::assertSame('right', $d->value(self::TBL . '[2]/w:tblPr/w:jc/@w:val'));
    }

    public function test_checkbox_marker_is_a_character(): void
    {
        $d = self::export(Column::make(View::make()->width(pt(8))->height(pt(8))->border(pt(1), '#000000')));
        self::assertSame(0, $d->count('//w:tbl'));
        self::assertSame(['☐'], $d->values('//w:t'));
        self::assertSame([], self::exportedReports());
    }
}
