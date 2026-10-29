<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests\Walk;

use Dskripchenko\PhpDocx\Element\Paragraph;
use Dskripchenko\PhpDocx\Style\ParagraphStyle;
use Papercut\Decorator\Blockquote;
use Papercut\Docx\Map\ListMapper;
use Papercut\Docx\Package\Numbering;
use Papercut\Export\{ExportOptions, Fidelity};
use function Papercut\pt;
use Papercut\Markup\Markup;
use Papercut\Value\Percent;
use Papercut\{Column, Document, ListItem, ListNode, Page, Row, Table, TableCell, Text, View};
use Papercut\Docx\Tests\Support\{DocxInspector, Styles};
use Papercut\Style\ListMarker;
use PHPUnit\Framework\TestCase;

final class WordListsTest extends TestCase
{
    use Styles;

    private const NUMBERING = 'word/numbering.xml';

    public function test_lists_are_word_numbering(): void
    {
        $d = self::export(ListNode::ordered('a', 'b', 'c'));
        self::assertSame(3, $d->count('//w:p/w:pPr/w:numPr'));
        self::assertCount(1, array_unique($d->values('//w:numPr/w:numId/@w:val')));
        self::assertSame(['0', '0', '0'], $d->values('//w:numPr/w:ilvl/@w:val'));
        self::assertSame(0, $d->count('//w:tab'));
        self::assertSame('a b c', trim(preg_replace('/\s+/', ' ', $d->bodyText()) ?? ''));
        self::assertSame(0, $d->count('//w:pStyle[starts-with(@w:val, "papercut-num-")]'));
    }

    public function test_adjacent_lists_restart(): void
    {
        $d = self::export(Column::make(ListNode::ordered('a', 'b'), ListNode::ordered('c', 'd')));
        $ids = array_values(array_unique($d->values('//w:numPr/w:numId/@w:val')));
        self::assertCount(2, $ids);
        foreach ($ids as $id) {
            self::assertSame('1', $d->value("//w:num[@w:numId='$id']/w:lvlOverride[@w:ilvl='0']/w:startOverride/@w:val", self::NUMBERING));
        }
    }

    public function test_start_number(): void
    {
        $d = self::export(ListNode::ordered('a', 'b')->start(5));
        self::assertSame('5', $d->value('//w:num/w:lvlOverride[@w:ilvl="0"]/w:startOverride/@w:val', self::NUMBERING));
    }

    public function test_nested_levels_and_markers(): void
    {
        $d = self::export(ListNode::ordered(ListItem::make(Text::make('a'), ListNode::unordered(ListItem::make(Text::make('b'), ListNode::unordered('c'))))));
        self::assertSame(['0', '1', '2'], $d->values('//w:numPr/w:ilvl/@w:val'));
        $texts = $d->values('//w:abstractNum/w:lvl[@w:ilvl="0"]/w:lvlText/@w:val', self::NUMBERING);
        self::assertContains(ListMarker::forDepth(false, 1)->label(1), $texts);
        self::assertContains(ListMarker::forDepth(false, 2)->label(1), $texts);
        self::assertSame('•', ListMarker::forDepth(false, 1)->label(1));
        self::assertSame('◦', ListMarker::forDepth(false, 2)->label(1));
    }

    public function test_item_with_paragraph_and_table_continues(): void
    {
        $table = Table::make()->columns('1fr')->row(TableCell::make('cell'));
        $d = self::export(ListNode::ordered(ListItem::make(Text::make('one'), $table), 'two'));
        self::assertCount(1, array_unique($d->values('//w:numPr/w:numId/@w:val')));
        self::assertSame(2, $d->count('//w:p[w:pPr/w:numPr]'));
        self::assertSame(1, $d->count('//w:p[w:pPr/w:numPr]/following-sibling::w:tbl/following-sibling::w:p[w:pPr/w:numPr]'));
    }

    public function test_item_starting_with_a_nested_list_gets_an_empty_numbered_paragraph(): void
    {
        $d = self::export(ListNode::ordered(ListItem::make(ListNode::unordered('inner'))));
        self::assertSame(['0', '1'], $d->values('//w:numPr/w:ilvl/@w:val'));
        self::assertSame(1, $d->count('//w:p[w:pPr/w:numPr/w:ilvl/@w:val="0"][not(w:r)]'));
    }

    public function test_heading_first_block_keeps_its_style(): void
    {
        $d = self::export(ListNode::ordered(ListItem::make(Text::h2('Title'))));
        self::assertSame(1, $d->count('//w:p[w:pPr/w:pStyle/@w:val="Heading2"][w:pPr/w:numPr]'));
    }

    public function test_numbered_paragraphs_have_no_direct_indent(): void
    {
        $d = self::export(ListNode::ordered(ListItem::make(Text::make('a'), Text::make('more')), ListItem::make(Text::make('b'), ListNode::ordered('c'))));
        self::assertSame(0, $d->count('//w:p[w:pPr/w:numPr]/w:pPr/w:ind'));
        // The continuation paragraph is indented to the text of its level.
        self::assertSame(['360'], $d->values('//w:p[w:pPr/w:ind]/w:pPr/w:ind/@w:left'));
    }

    public function test_marker_none_writes_no_numbering(): void
    {
        $d = self::export(ListNode::unordered('a', 'b')->marker(ListMarker::None));
        self::assertFalse($d->has(self::NUMBERING));
        self::assertSame(0, $d->count('//w:numPr'));
        self::assertSame(['360', '360'], $d->values('//w:pPr/w:ind/@w:left'));
        self::assertSame(0, $d->count('//w:tab'));
    }

    public function test_none_list_nested_in_a_numbered_item_is_indented_once(): void
    {
        $d = self::export(ListNode::ordered(ListItem::make(Text::make('a'), ListNode::unordered('x')->marker(ListMarker::None))));
        self::assertSame(['720'], $d->values('//w:pPr/w:ind/@w:left'));
        self::assertSame(1, $d->count('//w:numPr'));
    }

    public function test_none_list_in_none_list_is_indented_once(): void
    {
        $none = static fn (mixed ...$i): ListNode => ListNode::unordered(...$i)->marker(ListMarker::None);
        $d = self::export($none(ListItem::make(Text::make('a'), $none('x'))));
        self::assertSame(['360', '720'], $d->values('//w:pPr/w:ind/@w:left'));
    }

    public function test_none_list_as_first_block_gets_an_empty_numbered_paragraph_first(): void
    {
        $d = self::export(ListNode::ordered(ListItem::make(ListNode::unordered('x')->marker(ListMarker::None))));
        self::assertSame(1, $d->count('//w:p[w:pPr/w:numPr][not(w:r)]'));
        self::assertSame(0, $d->count('//w:p[w:pPr/w:numPr]/w:pPr/w:ind'));
        self::assertSame(1, $d->count('//w:p[w:r][not(w:pPr/w:numPr)]'));
        self::assertSame(['720'], $d->values('//w:p[w:r]/w:pPr/w:ind/@w:left'));
    }

    public function test_list_in_cell_inside_item_is_level_zero(): void
    {
        $table = Table::make()->columns('1fr')->row(TableCell::make(ListNode::ordered('x', 'y')));
        $d = self::export(ListNode::ordered(ListItem::make(Text::make('one'), $table)));
        self::assertSame(['0', '0', '0'], $d->values('//w:numPr/w:ilvl/@w:val'));
        $cellIds = $d->values('//w:tc//w:numPr/w:numId/@w:val');
        self::assertCount(2, $cellIds);
        $abstract = $d->value("//w:num[@w:numId='{$cellIds[0]}']/w:abstractNumId/@w:val", self::NUMBERING);
        self::assertSame('360', $d->value("//w:abstractNum[@w:abstractNumId='$abstract']/w:lvl[@w:ilvl='0']/w:pPr/w:ind/@w:left", self::NUMBERING));
    }

    public function test_list_in_blockquote_is_level_zero_in_its_box(): void
    {
        $d = self::export(Blockquote::make(ListNode::ordered('x', 'y')));
        self::assertSame(['0', '0'], $d->values('//w:tc//w:numPr/w:ilvl/@w:val'));
        self::assertSame(0, $d->count('//w:numPr/../w:ind'));
    }

    public function test_numbered_paragraph_with_a_container_indent_keeps_a_direct_indent(): void
    {
        $mapper = new ListMapper(new Numbering(), new Fidelity('docx', new ExportOptions()));
        $mapper->enter(ListNode::ordered('a'), 'list');
        $in = new Paragraph([], new ParagraphStyle(indentLeftTwips: 200));
        $out = $mapper->item([$in], 'list > item[0]')[0];
        self::assertInstanceOf(Paragraph::class, $out);
        self::assertSame(560, $out->style->indentLeftTwips);
        self::assertSame(-360, $out->style->indentFirstLineTwips);
        self::assertNotNull($out->styleId);
    }

    public function test_table_in_item_is_indented_and_fits(): void
    {
        $table = Table::make()->columns('1fr')->row(TableCell::make('cell'));
        $d = self::export(ListNode::ordered(ListItem::make(Text::make('one'), $table)));
        self::assertSame('360', $d->value('//w:tbl/w:tblPr/w:tblInd/@w:w'));
        $w = (int) $d->value('//w:tbl/w:tblPr/w:tblW/@w:w');
        $page = (int) $d->value('//w:sectPr/w:pgSz/@w:w') - (int) $d->value('//w:sectPr/w:pgMar/@w:left') - (int) $d->value('//w:sectPr/w:pgMar/@w:right');
        self::assertGreaterThan(0, $w);
        self::assertLessThanOrEqual($page, $w + 360);
        self::assertSame((string) $w, (string) array_sum(array_map('intval', $d->values('//w:tblGrid/w:gridCol/@w:w'))));
    }

    public function test_table_in_nested_item_is_indented_once_by_its_own_level(): void
    {
        $table = Table::make()->columns('1fr')->row(TableCell::make('cell'));
        $d = self::export(ListNode::ordered(ListItem::make(Text::make('one'), ListNode::ordered(ListItem::make(Text::make('in'), $table)))));
        self::assertSame(['720'], $d->values('//w:tbl/w:tblPr/w:tblInd/@w:w'));
    }

    public function test_value_override_starts_a_new_instance_and_is_noted(): void
    {
        $d = self::export(ListNode::ordered('a', 'b', ListItem::make(Text::make('c'))->withValue(10), 'd'));
        $ids = $d->values('//w:numPr/w:numId/@w:val');
        self::assertCount(4, $ids);
        self::assertSame($ids[0], $ids[1]);
        self::assertNotSame($ids[1], $ids[2]);
        self::assertSame($ids[2], $ids[3]);
        self::assertSame('10', $d->value("//w:num[@w:numId='{$ids[2]}']/w:lvlOverride[@w:ilvl='0']/w:startOverride/@w:val", self::NUMBERING));
        self::assertCount(1, array_filter(self::exportedReports(), static fn (string $r): bool => str_contains($r, 'Approximated list item number @ ')));
    }

    public function test_value_equal_to_the_running_number_is_not_a_new_instance(): void
    {
        $d = self::export(ListNode::ordered('a', ListItem::make(Text::make('b'))->withValue(2)));
        self::assertCount(1, array_unique($d->values('//w:numPr/w:numId/@w:val')));
        self::assertSame([], self::exportedReports());
    }

    public function test_value_on_first_item_is_the_start(): void
    {
        $d = self::export(ListNode::ordered(ListItem::make(Text::make('a'))->withValue(7), 'b'));
        self::assertSame(['7'], $d->values('//w:num/w:lvlOverride[@w:ilvl="0"]/w:startOverride/@w:val', self::NUMBERING));
        self::assertSame([], self::exportedReports());
    }

    public function test_lists_word_cannot_number_keep_typed_markers(): void
    {
        $d = self::export(ListNode::ordered('a', 'b')->start(0));
        self::assertSame(0, $d->count('//w:numPr'));
        self::assertFalse($d->has(self::NUMBERING));
        self::assertSame(2, $d->count('//w:tab'));
        self::assertStringContainsString('0.', $d->bodyText());
        self::assertStringContainsString('1.', $d->bodyText());
        self::assertSame(['360', '360'], $d->values('//w:pPr/w:ind/@w:left'));
        self::assertSame(['360', '360'], $d->values('//w:pPr/w:ind/@w:hanging'));
        self::assertCount(1, array_filter(self::exportedReports(), static fn (string $r): bool => str_contains($r, 'Approximated list numbering @ ')));
    }

    public function test_roman_list_reaching_4000_keeps_typed_markers(): void
    {
        $d = self::export(ListNode::ordered('a', 'b')->start(3999)->marker(ListMarker::LowerRoman));
        self::assertSame(0, $d->count('//w:numPr'));
        self::assertStringContainsString('mmmcmxcix.', $d->bodyText());
        self::assertCount(1, array_filter(self::exportedReports(), static fn (string $r): bool => str_contains($r, 'list numbering')));
    }

    public function test_typed_list_nested_in_a_numbered_list_is_indented_by_level(): void
    {
        $d = self::export(ListNode::ordered(ListItem::make(Text::make('a'), ListNode::ordered('x')->start(0))));
        self::assertSame(1, $d->count('//w:numPr'));
        self::assertSame(['720'], $d->values('//w:pPr/w:ind/@w:left'));
    }

    public function test_nested_list_with_margin_keeps_its_level(): void
    {
        $d = self::export(ListNode::ordered(ListItem::make(Text::make('a'), ListNode::unordered('x', 'y')->margin(pt(6))), 'b'));
        self::assertSame(['0', '1', '1', '0'], $d->values('//w:numPr/w:ilvl/@w:val'));
        self::assertSame(0, $d->count('//w:p[w:pPr/w:numPr]/w:pPr/w:ind'));
    }

    public function test_nested_list_with_margin_first_in_item_keeps_its_bullets(): void
    {
        $d = self::export(ListNode::ordered(ListItem::make(ListNode::unordered('x', 'y')->margin(pt(6))), 'b'));
        self::assertSame(['0', '1', '1', '0'], $d->values('//w:numPr/w:ilvl/@w:val'));
        self::assertSame(1, $d->count('//w:p[w:pPr/w:numPr/w:ilvl/@w:val="0"][not(w:r)]'));
    }

    public function test_gapped_nested_list_that_is_not_the_last_block(): void
    {
        $d = self::export(ListNode::ordered(ListItem::make(Text::make('a'), ListNode::unordered('x')->margin(pt(6)), Text::make('tail'))->margin(pt(2)), 'b'));
        self::assertSame(['0', '1', '0'], $d->values('//w:numPr/w:ilvl/@w:val'));
        self::assertSame(0, $d->count('//w:p[w:pPr/w:numPr]/w:pPr/w:ind'));
        self::assertSame(['360'], $d->values('//w:p[w:pPr/w:ind]/w:pPr/w:ind/@w:left'));
    }

    public function test_value_on_a_bullet_item_is_ignored(): void
    {
        $d = self::export(ListNode::unordered('a', ListItem::make(Text::make('b'))->withValue(5)));
        self::assertCount(1, array_unique($d->values('//w:numPr/w:numId/@w:val')));
        self::assertSame([], self::exportedReports());
    }

    /** @return array{int, int} table width and indent in twips */
    private static function itemTable(ListItem $item): array
    {
        $d = self::export(ListNode::ordered($item, 'b'));

        return [(int) $d->value('//w:tbl/w:tblPr/w:tblW/@w:w'), (int) $d->value('//w:tbl/w:tblPr/w:tblInd/@w:w')];
    }

    private static function cellTable(): Table
    {
        return Table::make()->columns('1fr')->row(TableCell::make('cell'));
    }

    public function test_item_width_includes_the_list_indent(): void
    {
        [$w, $ind] = self::itemTable(ListItem::make(Text::make('a'), self::cellTable())->width(pt(200)));
        self::assertEqualsWithDelta(3640, $w, 1);
        self::assertEqualsWithDelta(4000, $w + $ind, 1);
    }

    public function test_item_max_width_includes_the_list_indent(): void
    {
        [$w, $ind] = self::itemTable(ListItem::make(Text::make('a'), self::cellTable())->maxWidth(pt(200)));
        self::assertEqualsWithDelta(3640, $w, 1);
        self::assertEqualsWithDelta(4000, $w + $ind, 1);
    }

    public function test_item_percent_width_is_of_the_list_and_includes_the_indent(): void
    {
        [$full, ] = self::itemTable(ListItem::make(Text::make('a'), self::cellTable()));
        [$w, $ind] = self::itemTable(ListItem::make(Text::make('a'), self::cellTable())->width(Percent::of(50)));
        self::assertEqualsWithDelta(($full + 360) / 2, $w + $ind, 2);
    }

    public function test_margined_table_first_in_an_item_keeps_the_number_on_a_normal_paragraph(): void
    {
        $d = self::export(ListNode::ordered(ListItem::make(self::cellTable()->margin(pt(6))), 'b'));
        self::assertSame(2, $d->count('//w:p[w:pPr/w:numPr]'));
        self::assertSame(0, $d->count('//w:p[w:pPr/w:numPr]/w:pPr/w:spacing[@w:line="2"]'));
        self::assertSame(['120'], array_slice($d->values('//w:p[w:pPr/w:numPr]/w:pPr/w:spacing/@w:after'), 0, 1));
        self::assertSame(1, $d->count('//w:p[w:pPr/w:numPr]/following-sibling::w:tbl'));
    }

    public function test_margined_table_in_a_none_list_item_keeps_the_number_on_a_normal_paragraph(): void
    {
        $none = ListNode::unordered(ListItem::make(self::cellTable())->margin(pt(6)))->marker(ListMarker::None);
        $d = self::export(ListNode::ordered(ListItem::make($none, Text::make('t')), 'b'));
        self::assertSame(0, $d->count('//w:p[w:pPr/w:numPr]/w:pPr/w:spacing[@w:line="2"]'));
        self::assertSame(['120'], array_slice($d->values('//w:p[w:pPr/w:numPr]/w:pPr/w:spacing/@w:after'), 0, 1));
    }

    public function test_unnumbered_item_keeps_its_exact_height_hairline(): void
    {
        $d = self::export(ListNode::unordered(ListItem::make(self::cellTable()->margin(pt(6))), 'b')->marker(ListMarker::None));
        self::assertSame(0, $d->count('//w:numPr'));
        self::assertGreaterThanOrEqual(1, $d->count('//w:p/w:pPr/w:spacing[@w:line="2"][@w:lineRule="exact"]'));
        self::assertSame(0, $d->count('//w:p[not(.//w:t[normalize-space()])][not(w:pPr/w:spacing/@w:line="2")][not(ancestor::w:tc)]'));
    }

    public function test_page_break_before_a_leading_table_moves_to_the_numbered_paragraph(): void
    {
        $d = self::export(ListNode::ordered(ListItem::make(self::cellTable()->breakBefore()), 'b'));
        self::assertSame(1, $d->count('//w:p[w:pPr/w:numPr][w:pPr/w:pageBreakBefore]'));
        self::assertSame(0, $d->count('//w:p[w:pPr/w:numPr]/w:pPr/w:spacing[@w:line="2"]'));
    }

    public function test_lists_in_header_and_body_share_one_numbering_part(): void
    {
        $d = self::export(Page::a4()->header(ListNode::ordered('h'))->body(ListNode::ordered('b')));
        self::assertSame(1, count(array_filter($d->names(), static fn (string $n): bool => str_ends_with($n, 'numbering.xml'))));
        $headers = array_values(array_filter($d->names(), static fn (string $n): bool => preg_match('~^word/header\d+\.xml$~', $n) === 1));
        self::assertNotEmpty($headers);
        $head = $d->value('//w:numPr/w:numId/@w:val', $headers[0]);
        $body = $d->value('//w:numPr/w:numId/@w:val');
        self::assertNotNull($head);
        self::assertNotSame($head, $body);
        foreach ([$head, $body] as $id) {
            self::assertSame(1, $d->count("//w:num[@w:numId='$id']", self::NUMBERING));
        }
    }

    public function test_no_lists_no_numbering_part(): void
    {
        $d = self::export(Text::make('plain'));
        self::assertFalse($d->has(self::NUMBERING));
    }

    private static function fmtOf(DocxInspector $d, string $numId): ?string
    {
        $abstract = $d->value("//w:num[@w:numId='$numId']/w:abstractNumId/@w:val", self::NUMBERING);

        return $d->value("//w:abstractNum[@w:abstractNumId='$abstract']/w:lvl[@w:ilvl='0']/w:numFmt/@w:val", self::NUMBERING);
    }

    public function test_list_in_cell_inside_item_keeps_the_marker_of_its_depth(): void
    {
        $table = Table::make()->columns('1fr')->row(TableCell::make(ListNode::ordered('n1', 'n2')));
        $d = self::export(ListNode::ordered(ListItem::make(Text::make('one'), $table)));
        $cellIds = $d->values('//w:tc//w:numPr/w:numId/@w:val');
        self::assertSame(ListMarker::LowerAlpha, ListMarker::forDepth(true, 2));
        self::assertSame('lowerLetter', self::fmtOf($d, $cellIds[0]));
        self::assertSame(['0', '0'], $d->values('//w:tc//w:numPr/w:ilvl/@w:val'));
    }

    public function test_list_in_box_inside_item_is_level_zero_with_the_marker_of_its_depth(): void
    {
        $box = View::make(ListNode::unordered('x', 'y'))->border(pt(1), 'slate-200');
        $d = self::export(ListNode::ordered(ListItem::make('a', $box)));
        self::assertSame(['0', '0'], $d->values('//w:tc//w:numPr/w:ilvl/@w:val'));
        $ids = $d->values('//w:tc//w:numPr/w:numId/@w:val');
        $abstract = $d->value("//w:num[@w:numId='{$ids[0]}']/w:abstractNumId/@w:val", self::NUMBERING);
        self::assertSame(ListMarker::forDepth(false, 1)->label(1), $d->value("//w:abstractNum[@w:abstractNumId='$abstract']/w:lvl[@w:ilvl='0']/w:lvlText/@w:val", self::NUMBERING));
    }

    public function test_label_takes_the_size_of_the_item(): void
    {
        $d = self::export(ListNode::ordered(ListItem::make(Text::make('big')->size(pt(16)))));
        self::assertSame('32', $d->value('//w:p[w:pPr/w:numPr]/w:pPr/w:rPr/w:sz/@w:val'));
        self::assertSame('32', $d->value('//w:p[w:pPr/w:numPr]/w:pPr/w:rPr/w:szCs/@w:val'));
    }

    public function test_label_takes_bold_and_colour_of_the_item(): void
    {
        $d = self::export(ListNode::unordered(ListItem::make(Text::make('loud')->bold()->color('red-600'))));
        self::assertSame(1, $d->count('//w:p[w:pPr/w:numPr]/w:pPr/w:rPr/w:b'));
        self::assertSame($d->value('//w:r/w:rPr/w:color/@w:val'), $d->value('//w:p[w:pPr/w:numPr]/w:pPr/w:rPr/w:color/@w:val'));
        self::assertNotNull($d->value('//w:p[w:pPr/w:numPr]/w:pPr/w:rPr/w:color/@w:val'));
    }

    public function test_heading_item_label_keeps_heading_style_and_run_size(): void
    {
        $d = self::export(ListNode::ordered(ListItem::make(Text::h2('Title')->size(pt(20)))));
        self::assertSame(1, $d->count('//w:p[w:pPr/w:pStyle/@w:val="Heading2"][w:pPr/w:numPr]'));
        self::assertSame('40', $d->value('//w:p[w:pPr/w:numPr]/w:pPr/w:rPr/w:sz/@w:val'));
    }

    public function test_label_rpr_comes_after_the_paragraph_properties_and_before_the_run(): void
    {
        $d = self::export(ListNode::ordered(ListItem::make(Text::make('big')->size(pt(16)))));
        self::assertSame(1, $d->count('//w:p[w:pPr/w:numPr]/w:pPr/w:rPr[not(following-sibling::*)]'));
        self::assertSame(0, $d->count('//w:p[w:pPr/w:numPr]/w:pPr/w:rPr/following-sibling::*'));
    }

    public function test_word_lists_open_in_libreoffice(): void
    {
        $soffice = is_executable('/opt/homebrew/bin/soffice') ? '/opt/homebrew/bin/soffice' : trim((string) shell_exec('command -v soffice 2>/dev/null'));
        if ($soffice === '') {
            self::markTestSkipped('soffice not installed');
        }
        $pdftotext = trim((string) shell_exec('command -v pdftotext 2>/dev/null'));
        if ($pdftotext === '') {
            self::markTestSkipped('pdftotext not installed');
        }
        $doc = Document::make(Page::a4()->body(Column::make(
            ListNode::ordered('alpha', ListItem::make(Text::make('beta'), ListNode::ordered('gamma', 'delta'))),
            Text::make('between'),
            ListNode::ordered('epsilon', ListItem::make(Text::make('zeta')), ListItem::make(Text::make('large')->size(pt(24)))),
        )));
        $dir = sys_get_temp_dir() . '/papercut-lo-' . bin2hex(random_bytes(6));
        mkdir($dir);
        try {
            file_put_contents("$dir/lists.docx", $doc->export(new \Papercut\Docx\DocxExporter())->bytes());
            $proc = proc_open(
                [$soffice, '-env:UserInstallation=file://' . $dir . '/lo-profile', '--headless', '--convert-to', 'pdf', '--outdir', $dir, "$dir/lists.docx"],
                [1 => ['file', "$dir/soffice.out", 'w'], 2 => ['file', "$dir/soffice.err", 'w']],
                $pipes,
            );
            self::assertIsResource($proc);
            $deadline = microtime(true) + 60;
            while (($status = proc_get_status($proc))['running']) {
                if (microtime(true) > $deadline) {
                    proc_terminate($proc, 9);
                    proc_close($proc);
                    self::markTestSkipped('soffice did not finish within 60 seconds');
                }
                usleep(100_000);
            }
            proc_close($proc);
            self::assertFileExists("$dir/lists.pdf", sprintf(
                "soffice wrote no PDF\nstdout: %s\nstderr: %s",
                trim((string) @file_get_contents("$dir/soffice.out")),
                trim((string) @file_get_contents("$dir/soffice.err")),
            ));
            $text = (string) shell_exec(sprintf('%s -layout %s - 2>/dev/null', escapeshellarg($pdftotext), escapeshellarg("$dir/lists.pdf")));
            self::assertMatchesRegularExpression('~1\.\s+alpha~', $text);
            self::assertMatchesRegularExpression('~2\.\s+beta~', $text);
            self::assertMatchesRegularExpression('~a\.\s+gamma~', $text);
            self::assertMatchesRegularExpression('~b\.\s+delta~', $text);
            self::assertMatchesRegularExpression('~1\.\s+epsilon~', $text);
            self::assertMatchesRegularExpression('~2\.\s+zeta~', $text);
            self::assertMatchesRegularExpression('~3\.\s+large~', $text);
        } finally {
            // soffice.bin outlives the launcher it was started by, so stop whatever still runs on this profile before deleting it.
            exec('pkill -9 -f ' . escapeshellarg("$dir/lo-profile") . ' 2>/dev/null');
            exec('rm -rf ' . escapeshellarg($dir));
        }
    }

    public function test_list_ending_a_cell_adds_no_space_after(): void
    {
        $cell = TableCell::make(Text::make('P'), ListNode::unordered('A'), ListNode::unordered('B'));
        $d = self::export(Table::make()->columns('1fr')->row($cell));
        $after = array_map(static fn (string $v): string => $v, $d->values('//w:tc//w:p[w:pPr/w:numPr]/w:pPr/w:spacing/@w:after'));
        self::assertSame(['120'], $after, 'only the list followed by another list keeps its space');
        self::assertSame(2, $d->count('//w:tc//w:p[w:pPr/w:numPr]'));
        self::assertNull($d->value('(//w:tc//w:p[w:pPr/w:numPr])[2]/w:pPr/w:spacing/@w:after'));
    }

    public function test_a_list_in_a_rows_child_starts_at_level_zero(): void
    {
        $d = self::export(ListNode::ordered(ListItem::make(Row::make(Text::make('left'), ListNode::unordered('x', 'y')))));
        self::assertSame(['0', '0'], $d->values('//w:tbl//w:numPr/w:ilvl/@w:val'));
    }

    public function test_a_bordered_list_in_an_item_indents_once_through_its_box(): void
    {
        $d = self::export(ListNode::ordered('a', ListItem::make(Text::make('b'), ListNode::unordered('c')->border(pt(1), '#000'))));
        self::assertSame(['0', '0', '0'], $d->values('//w:numPr/w:ilvl/@w:val'));
        self::assertSame(['0'], $d->values('//w:tbl//w:numPr/w:ilvl/@w:val'));
        self::assertSame(['360'], $d->values('//w:tbl/w:tblPr/w:tblInd/@w:w'));
    }

    public function test_an_item_that_starts_with_a_rule_numbers_a_line_of_its_own_above_it(): void
    {
        $d = self::export(ListNode::ordered(ListItem::make(Markup::html('<hr>'), Text::make('after')), 'next'));
        self::assertSame(2, $d->count('//w:p[w:pPr/w:numPr]'));
        self::assertSame(0, $d->count('//w:p[w:pPr/w:numPr][w:pPr/w:spacing/@w:lineRule="exact"]'));
        self::assertSame(1, $d->count('//w:p[w:pPr/w:pBdr][w:pPr/w:spacing/@w:lineRule="exact"]'));
        self::assertSame(1, $d->count('//w:p[w:pPr/w:numPr][not(w:r)]/following-sibling::w:p[1][w:pPr/w:pBdr]'));
    }
}
