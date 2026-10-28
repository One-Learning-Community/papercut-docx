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

    /** @return list<string> the first run of every paragraph that has text */
    private static function markers(DocxInspector $d): array
    {
        $xp = $d->xpath();
        $out = [];
        foreach ($xp->query('//w:p[w:r/w:t[normalize-space()]]/w:r[1]') ?: [] as $run) {
            \assert($run instanceof \DOMElement);
            $out[] = $xp->evaluate('string(w:t)', $run) . ($xp->evaluate('count(w:tab)', $run) > 0 ? "\t" : '');
        }

        return $out;
    }

    public function test_ordered_list_with_a_nested_list(): void
    {
        $list = ListNode::ordered(
            'a',
            ListItem::make(Text::make('b'), ListNode::unordered('x', ListItem::make(Text::make('y'), ListNode::unordered('z')))),
            'c',
        )->start(3);
        $d = self::export($list);
        // Markers per kind and depth, as in PDF and HTML: the unordered list is the first unordered one.
        $disc = ListMarker::forDepth(false, 1)->label(1);
        $circle = ListMarker::forDepth(false, 2)->label(1);
        self::assertSame(["3.\t", "4.\t", "$disc\t", "$disc\t", "$circle\t", "5.\t"], self::markers($d));
        self::assertSame(['360', '360', '720', '720', '1080', '360'], $d->values("//w:p[w:r/w:t[normalize-space()]]/w:pPr/w:ind/@w:left"));
        self::assertSame(['360', '360', '360', '360', '360', '360'], $d->values("//w:p[w:r/w:t[normalize-space()]]/w:pPr/w:ind/@w:hanging"));
    }

    public function test_numbering_restarts_per_list_and_follows_value_overrides(): void
    {
        $d = self::export(Column::make(
            ListNode::ordered('a', 'b'),
            ListNode::ordered('c', ListItem::make('d')->withValue(10), 'e'),
        ));
        self::assertSame(["1.\t", "2.\t", "1.\t", "10.\t", "11.\t"], self::markers($d));
    }

    public function test_list_inside_a_table_cell(): void
    {
        $d = self::export(Table::make()->columns('1fr')->row(TableCell::make(ListNode::ordered('a', 'b'))));
        self::assertSame(1, $d->count('//w:tc'));
        self::assertSame(["1.\t", "2.\t"], self::markers($d));
    }
}
