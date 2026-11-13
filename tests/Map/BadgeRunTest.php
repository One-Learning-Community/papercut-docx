<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests\Map;

use Papercut\Decorator\Badge;
use Papercut\Docx\Tests\Support\Styles;
use Papercut\Style\Align;
use Papercut\{Image, Row, Span, Table, Text, View};
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

final class BadgeRunTest extends TestCase
{
    use Styles;

    private const string NBSP = "\u{00A0}";

    private static function inline(Span ...$badges): Text
    {
        $t = Text::make('Returns ');
        foreach ($badges as $b) {
            $t = $t->append($b)->append(new Span(' when it works.'));
        }

        return $t;
    }

    public function test_inline_badge_is_one_shaded_run_with_nbsp_padding(): void
    {
        $d = self::export(self::inline(Span::badge('201', 'tip')));
        $run = '//w:r[w:t="' . self::NBSP . self::NBSP . '201' . self::NBSP . self::NBSP . '"]';
        self::assertSame(1, $d->count($run));
        self::assertSame('d1fae5', $d->value($run . '/w:rPr/w:shd/@w:fill'));
        self::assertSame('065f46', $d->value($run . '/w:rPr/w:color/@w:val'));
        self::assertSame(1, $d->count($run . '/w:rPr/w:b'));
        self::assertSame('d1fae5', $d->value($run . '/w:rPr/w:bdr/@w:color'));
    }

    public function test_label_spaces_become_no_break_spaces(): void
    {
        $d = self::export(self::inline(Span::badge('Strongly agree')));
        self::assertSame(1, $d->count('//w:r[w:t="' . self::NBSP . self::NBSP . 'Strongly' . self::NBSP . 'agree' . self::NBSP . self::NBSP . '"]'));
    }

    public function test_outline_badge_has_a_border_and_no_shading(): void
    {
        $d = self::export(self::inline(Span::badge('Beta', 'warning', 'outline')));
        $run = '//w:r[contains(w:t, "Beta")]';
        self::assertSame('fcd34d', $d->value($run . '/w:rPr/w:bdr/@w:color'));
        self::assertSame(0, $d->count($run . '/w:rPr/w:shd'));
    }

    public function test_text_only_block_badge_is_a_paragraph_not_a_nested_table(): void
    {
        $d = self::export(Table::make()->row('Method', Badge::make('POST')->tone('note')));
        self::assertSame(1, $d->count('//w:tbl'));
        self::assertSame(1, $d->count('//w:tc//w:p/w:r[contains(w:t, "POST")][w:rPr/w:shd[@w:fill="e0f2fe"]]'));
        self::assertSame(1, $d->count('//w:r[w:t="' . self::NBSP . self::NBSP . 'POST' . self::NBSP . self::NBSP . '"]'));
    }

    public function test_block_badge_with_non_text_content_keeps_the_nested_table(): void
    {
        $d = self::export(Badge::make(View::make(Image::bytes(self::png())->width(pt(10)))));
        self::assertSame(1, $d->count('//w:tbl'));
    }

    public function test_inline_row_badges_use_the_badge_run(): void
    {
        $d = self::export(Row::make(Badge::make('Website')->tone('tip'), Badge::make('Two words')));
        self::assertSame(0, $d->count('//w:tbl'));
        self::assertSame(1, $d->count('//w:r[w:t="' . self::NBSP . self::NBSP . 'Website' . self::NBSP . self::NBSP . '"][w:rPr/w:shd[@w:fill="d1fae5"]]'));
        self::assertSame(1, $d->count('//w:r[w:t="' . self::NBSP . self::NBSP . 'Two' . self::NBSP . 'words' . self::NBSP . self::NBSP . '"]'));
    }

    public function test_inline_row_non_badge_pills_use_the_badge_run_too(): void
    {
        $pill = Text::make('Pill')->background(\Papercut\Value\Color::hex('#ffeecc'))->padding(\Papercut\Value\Edges::of(pt(1), pt(5)));
        $d = self::export(Row::make($pill, 'tail'));
        self::assertSame(0, $d->count('//w:tbl'));
        self::assertSame(1, $d->count('//w:r[w:t="' . self::NBSP . self::NBSP . 'Pill' . self::NBSP . self::NBSP . '"][w:rPr/w:shd[@w:fill="ffeecc"]][w:rPr/w:bdr[@w:color="ffeecc"]]'));
    }

    public function test_a_centred_block_badge_keeps_its_alignment(): void
    {
        $d = self::export(View::make(Badge::make('Mid')->alignSelf(Align::Center), Badge::make('End')->alignSelf(Align::End)));
        self::assertSame(0, $d->count('//w:tbl'));
        self::assertSame('center', $d->value('//w:p[w:r[contains(w:t, "Mid")]]/w:pPr/w:jc/@w:val'));
        self::assertSame('right', $d->value('//w:p[w:r[contains(w:t, "End")]]/w:pPr/w:jc/@w:val'));
    }

    public function test_a_top_level_block_badge_is_a_paragraph(): void
    {
        $d = self::export(Badge::make('Solo')->tone('danger'));
        self::assertSame(0, $d->count('//w:tbl'));
        self::assertSame(1, $d->count('/w:document/w:body/w:p/w:r[w:t="' . self::NBSP . self::NBSP . 'Solo' . self::NBSP . self::NBSP . '"][w:rPr/w:shd[@w:fill="fee2e2"]]'));
    }

    public function test_a_bordered_filled_inline_badge_has_a_border_in_the_border_colour(): void
    {
        $d = self::export(self::inline(Span::badge('Both', 'warning', 'outline')));
        $run = '//w:r[contains(w:t, "Both")]/w:rPr/w:bdr';
        self::assertSame('fcd34d', $d->value($run . '/@w:color'));
        self::assertSame('6', $d->value($run . '/@w:sz'));
        self::assertSame('single', $d->value($run . '/@w:val'));
    }

    public function test_a_plain_highlight_in_a_row_is_not_padded_or_bordered(): void
    {
        $d = self::export(Row::make(Text::make('Marked')->background(\Papercut\Value\Color::hex('#ffeecc')), 'tail'));
        self::assertSame(1, $d->count('//w:r[w:t="Marked"][w:rPr/w:shd[@w:fill="ffeecc"]]'));
        self::assertSame(0, $d->count('//w:bdr'));
    }

    public function test_a_mark_inside_a_pill_keeps_its_own_shading(): void
    {
        $pill = Text::make('Pill ')->append(Span::text('hot', \Papercut\Style\Style::make(background: \Papercut\Value\Color::hex('#ff0000'))))
            ->background(\Papercut\Value\Color::hex('#ffeecc'))->padding(\Papercut\Value\Edges::of(pt(1), pt(5)));
        $d = self::export(Row::make($pill, 'tail'));
        self::assertSame(1, $d->count('//w:r[contains(w:t, "hot")][w:rPr/w:shd[@w:fill="ff0000"]]'));
        self::assertSame(1, $d->count('//w:r[contains(w:t, "Pill")][w:rPr/w:shd[@w:fill="ffeecc"]]'));
    }

    private static function png(): string
    {
        $im = imagecreatetruecolor(4, 2);
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    public function test_badge_label_whitespace_is_collapsed(): void
    {
        $d = self::export(Text::markup("Status <badge>In\n   progress</badge>"));
        $nb = self::NBSP;
        self::assertSame(1, $d->count('//w:r[w:t="' . $nb . $nb . 'In' . $nb . 'progress' . $nb . $nb . '"]'));
        self::assertSame(0, $d->count('//w:br'));
    }
}
