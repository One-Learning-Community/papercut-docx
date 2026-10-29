<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests\Map;

use Papercut\{Column, ListItem, ListNode, Table, TableCell, Text};
use Papercut\Docx\Tests\Support\{DocxInspector, Styles};
use Papercut\Style\ListMarker;
use PHPUnit\Framework\TestCase;

final class ListMapperTest extends TestCase
{
    use Styles;

    public function test_ordered_list_with_a_nested_list(): void
    {
        $list = ListNode::ordered(
            'a',
            ListItem::make(Text::make('b'), ListNode::unordered('x', ListItem::make(Text::make('y'), ListNode::unordered('z')))),
            'c',
        )->start(3);
        $d = self::export($list);
        // Levels are the nesting depth; the label comes from Word, so no text starts with a marker or a tab.
        self::assertSame(['0', '0', '1', '1', '2', '0'], $d->values('//w:p/w:pPr/w:numPr/w:ilvl/@w:val'));
        self::assertSame(0, $d->count('//w:tab'));
        self::assertSame('3', $d->value('//w:num[@w:numId="1"]/w:lvlOverride[@w:ilvl="0"]/w:startOverride/@w:val', 'word/numbering.xml'));
        self::assertSame(['1', '1', '2', '2', '3', '1'], $d->values('//w:p/w:pPr/w:numPr/w:numId/@w:val'));
    }

    public function test_each_list_is_its_own_numbering_instance(): void
    {
        $d = self::export(Column::make(
            ListNode::ordered('a', 'b'),
            ListNode::ordered('c', 'd', 'e'),
        ));
        self::assertSame(['1', '1', '2', '2', '2'], $d->values('//w:p/w:pPr/w:numPr/w:numId/@w:val'));
    }

    public function test_list_inside_a_table_cell(): void
    {
        $d = self::export(Table::make()->columns('1fr')->row(TableCell::make(ListNode::ordered('a', 'b'))));
        self::assertSame(1, $d->count('//w:tc'));
        self::assertSame(2, $d->count('//w:tc//w:p[w:pPr/w:numPr]'));
    }
}
