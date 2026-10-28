<?php

declare(strict_types=1);

namespace Papercut\Docx\Map;

use Dskripchenko\PhpDocx\Element\{BlockElement, Table, TableCell, TableRow};
use Dskripchenko\PhpDocx\Style\{CellStyle, RowHeightRule, TableStyle, VerticalAlign};
use Papercut\Docx\Walk\Frame;
use Papercut\Export\Fidelity;
use Papercut\Style\{Align, ComputedStyle, TextAlign};
use Papercut\Value\Length;

/**
 * A flex row as a borderless fixed-layout table: one grid column per item and per stretch of empty space
 * (padding, gaps, spacers, free space), at the widths WidthResolver gives the PDF.
 *
 * @phpstan-type Item array{blocks: list<BlockElement>, style: ?ComputedStyle, index: int, boxed: bool}
 * @phpstan-type Segment array{x0: float, x1: float, item: ?Item, pad: array{float, float}, free: float}
 */
final class RowMapper
{
    /** Edges closer than this (pt) are one edge, so no grid column is narrower than a twip. */
    private const float MERGE = 0.05;

    public function __construct(
        private readonly Fidelity $fidelity,
        private readonly BoxMapper $boxes,
    ) {
    }

    public function row(ComputedStyle $s, Frame $frame, string $path): Table
    {
        if ($s->radius > 0.0) {
            $this->fidelity->dropped('radius', $path);
        }
        $byIndex = [];
        foreach ($frame->items as $item) {
            $byIndex[$item['index']] = $item;
        }
        $padL = $s->padding->left->points + $s->borderWidth->left->points;
        $padR = $s->padding->right->points + $s->borderWidth->right->points;
        $lines = $frame->lines === [] ? [[]] : $frame->lines;
        $total = $frame->width;

        // Each line as segments over the border box: items, and empty space.
        $segLines = [];
        foreach ($lines as $line) {
            $segs = [];
            $x = 0.0;
            foreach ($line as $k => $slot) {
                $item = $byIndex[$slot->index] ?? null;
                $x0 = max($x, $padL + $slot->x);
                if ($x0 > $x + 0.01) {
                    // 'free' is how much of an empty segment may be lent: not the row's padding, which can be filled.
                    $free = $k === 0 ? $x0 - $padL : $x0 - $x;
                    $segs[] = ['x0' => $x, 'x1' => $x0, 'item' => null, 'pad' => [0.0, 0.0], 'free' => max(0.0, $free)];
                }
                $empty = $item === null || $item['blocks'] === [];
                // A plain item that grows can lend a little of its width too.
                $lend = !$empty && $item['style'] instanceof ComputedStyle && $item['style']->grow > 0.0 && !BoxMapper::decorated($item['style']) ? $slot->width * 0.2 : 0.0;
                $segs[] = ['x0' => $x0, 'x1' => $x0 + $slot->width, 'item' => $empty ? null : $item, 'pad' => [0.0, 0.0], 'free' => $empty ? $slot->width : $lend];
                $x = $x0 + $slot->width;
            }
            // Content wider than the row widens the table rather than vanishing.
            $total = max($total, $x + $padR);
            $segLines[] = [$segs, $x];
        }
        foreach ($segLines as $li => [$segs, $x]) {
            if ($total > $x + 0.01) {
                $segs[] = ['x0' => $x, 'x1' => $total, 'item' => null, 'pad' => [0.0, 0.0], 'free' => max(0.0, $total - $x - $padR)];
            }
            $segLines[$li] = $this->settle($segs, $padL, $padR);
        }

        // Grid edges from every line's segments, merged so each column is at least a twip.
        $raw = [0.0, $total];
        foreach ($segLines as $segs) {
            foreach ($segs as $seg) {
                $raw[] = max(0.0, min($total, $seg['x0']));
                $raw[] = max(0.0, min($total, $seg['x1']));
            }
        }
        sort($raw);
        $edges = [];
        foreach ($raw as $e) {
            if ($edges === [] || $e - end($edges) >= self::MERGE) {
                $edges[] = $e;
            }
        }
        if (count($edges) < 2) {
            $edges = [0.0, max(self::MERGE, $total)];
        }
        $edges[count($edges) - 1] = max(end($edges), $total);
        $grid = [];
        for ($i = 1, $n = count($edges); $i < $n; $i++) {
            $grid[] = max(1, Units::tw($edges[$i]) - Units::tw($edges[$i - 1]));
        }
        $col = static function (float $x) use ($edges): int {
            $best = 0;
            foreach ($edges as $i => $e) {
                if (abs($e - $x) < abs($edges[$best] - $x)) {
                    $best = $i;
                }
            }

            return $best;
        };

        $rows = [];
        $lastLine = count($lines) - 1;
        foreach ($segLines as $li => $segs) {
            $padT = $li === 0 ? $s->padding->top->points + $s->borderWidth->top->points : 0.0;
            $padB = $li === $lastLine ? $s->padding->bottom->points + $s->borderWidth->bottom->points : 0.0;
            /** @var list<array{start: int, end: int, item: ?Item, pad: array{float, float}}> $cells */
            $cells = [];
            foreach ($segs as $seg) {
                $start = $col($seg['x0']);
                $end = $col($seg['x1']);
                $last = count($cells) - 1;
                if ($end <= $start) {
                    // Too narrow for a column of its own: its content joins the previous cell.
                    if ($seg['item'] !== null && $last >= 0) {
                        $prev = $cells[$last]['item'];
                        $cells[$last]['item'] = $prev === null ? $seg['item'] : ['blocks' => [...$prev['blocks'], ...$seg['item']['blocks']]] + $prev;
                    }
                    continue;
                }
                $cells[] = ['start' => $start, 'end' => $end, 'item' => $seg['item'], 'pad' => $seg['pad']];
            }
            if ($cells === []) {
                $cells[] = ['start' => 0, 'end' => count($grid), 'item' => null, 'pad' => [0.0, 0.0]];
            }
            // Every column is covered exactly once: each cell starts where the previous one ended.
            $cursor = 0;
            $lastCell = count($cells) - 1;
            foreach ($cells as $i => $c) {
                $c['start'] = $cursor;
                $cursor = $i === $lastCell ? count($grid) : min(count($grid) - ($lastCell - $i), max($cursor + 1, $c['end']));
                $c['end'] = $cursor;
                $cells[$i] = $c;
            }

            $out = [];
            foreach ($cells as ['start' => $start, 'end' => $end, 'item' => $item, 'pad' => $pad]) {
                $span = max(1, $end - $start);
                $width = array_sum(array_slice($grid, $start, $span));
                if ($item === null) {
                    $out[] = $this->emptyCell($width, $span, $s, $padT, $padB);
                    continue;
                }
                $own = $item['style'] ?? $s;
                $v = match ($own->alignSelf ?? $s->alignItems) {
                    Align::Center => VerticalAlign::Center,
                    Align::End => VerticalAlign::Bottom,
                    default => VerticalAlign::Top,
                };
                if ($own !== $s && $own->radius > 0.0) {
                    $this->fidelity->dropped('radius', $path);
                }
                $out[] = new TableCell(BoxMapper::cellContent($item['blocks']), $this->boxes->cellStyle($own, $width, $span, $v, $s, [$padT, $pad[1], $padB, $pad[0]]));
            }
            $fixed = $s->height instanceof Length && count($lines) === 1 ? $s->height : null;
            $exact = $fixed !== null;
            $height = match (true) {
                $fixed !== null => Units::tw($fixed->points),
                $s->minHeight instanceof Length && count($lines) === 1 => Units::tw($s->minHeight->points),
                default => null,
            };
            $rows[] = new TableRow($out, heightTwips: $height, cantSplit: $s->keepTogether, heightRule: $exact ? RowHeightRule::Exact : RowHeightRule::AtLeast);
        }
        if ($s->height instanceof Length) {
            $this->fidelity->approximated('fixed height', $path);
        }
        if ($s->wrap && count($lines) > 1) {
            $this->fidelity->approximated('wrap', $path);
        }
        $style = BoxMapper::tableStyle($total);
        $borders = BoxMapper::borders($s);
        if ($borders !== null) {
            $style = new TableStyle(...[...get_object_vars($style), 'borders' => $borders]);
        }

        return new Table($rows, $style, gridColumnsTwips: $grid);
    }

    /**
     * Folds the row's padding into the cells beside it, gives content-sized items slack from neighbouring
     * empty space (Word's text never measures exactly like ours), and drops empty slivers.
     *
     * @param list<Segment> $segs
     * @return list<Segment>
     */
    public function settle(array $segs, float $padL, float $padR): array
    {
        $n = count($segs);
        // The first item absorbs the left padding when nothing but padding precedes it; likewise on the right.
        if ($n >= 2 && $segs[0]['item'] === null && $segs[1]['item'] !== null && abs($segs[0]['x1'] - $padL) < 0.01) {
            $first = $segs[1];
            $first['x0'] = 0.0;
            $first['pad'][0] += $padL;
            $segs = [$first, ...array_slice($segs, 2)];
            $n--;
        }
        if ($n >= 2 && $segs[$n - 1]['item'] === null && $segs[$n - 2]['item'] !== null && abs($segs[$n - 1]['x1'] - $segs[$n - 1]['x0'] - $padR) < 0.01) {
            $lastItem = $segs[$n - 2];
            $lastItem['x1'] = $segs[$n - 1]['x1'];
            $lastItem['pad'][1] += $padR;
            $segs = [...array_slice($segs, 0, $n - 2), $lastItem];
            $n--;
        }
        // Slack: up to 15% + 4pt from a neighbour's free space, on the side the text doesn't align to.
        for ($i = 0; $i < $n; $i++) {
            $style = $segs[$i]['item']['style'] ?? null;
            // A box with a background or border would visibly grow, so only plain content gets slack.
            if (!$style instanceof ComputedStyle || BoxMapper::decorated($style) || $style->grow > 0.0) {
                continue;
            }
            $want = ($segs[$i]['x1'] - $segs[$i]['x0']) * 0.15 + 4.0;
            $order = $style->textAlign !== TextAlign::Right ? [$i + 1, $i - 1] : [$i - 1, $i + 1];
            foreach ($order as $j) {
                if (!isset($segs[$j]) || $segs[$j]['free'] <= 0.0) {
                    continue;
                }
                $me = $segs[$i];
                $other = $segs[$j];
                $take = min($want, $other['free'] / 2);
                if ($j > $i) {
                    $me['x1'] += $take;
                    $other['x0'] += $take;
                } else {
                    $me['x0'] -= $take;
                    $other['x1'] -= $take;
                }
                $other['free'] -= $take;
                $segs[$i] = $me;
                $segs[$j] = $other;
                break;
            }
        }

        $out = [];
        foreach ($segs as $seg) {
            if ($seg['x1'] - $seg['x0'] > 0.01) {
                $out[] = $seg;
            }
        }

        return $out;
    }

    private function emptyCell(int $width, int $span, ComputedStyle $row, float $padT, float $padB): TableCell
    {
        return new TableCell([Flow::hairline()], new CellStyle(
            widthTwips: $width,
            paddingTopTwips: Units::tw($padT),
            paddingBottomTwips: Units::tw($padB),
            backgroundColor: Units::fill($row->background),
            gridSpan: $span,
        ));
    }
}
