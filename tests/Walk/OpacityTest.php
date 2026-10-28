<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests\Walk;

use Papercut\{Column, Image, Span, Text};
use Papercut\Docx\Tests\Support\Styles;
use Papercut\Style\Style;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

use function Papercut\pt;

/** Opacity fades a node and everything in it, as in the PDF: colours are blended onto white. */
final class OpacityTest extends TestCase
{
    use Styles;

    public function test_faded_text_is_a_lighter_colour(): void
    {
        $d = self::export(Text::make('x')->color('#000000')->opacity(0.5));
        self::assertSame('808080', $d->value('//w:r/w:rPr/w:color/@w:val'));
        self::assertSame([], self::exportedReports());
    }

    public function test_a_faded_box_fades_its_fill_border_and_content(): void
    {
        $d = self::export(Column::make(
            Text::spans(new Span('plain'), new Span('red', Style::make(color: \Papercut\Value\Color::hex('#ff0000')))),
        )->padding(pt(4))->background('#000000')->border(pt(1), '#0000ff')->color('#000000')->opacity(0.5));
        self::assertSame([], self::exportedReports());
        self::assertSame('808080', $d->value('//w:tc/w:tcPr/w:shd/@w:fill'));
        // the border and text at half over the faded fill
        self::assertSame('4040c0', $d->value('//w:tc/w:tcPr/w:tcBorders/w:top/@w:color'));
        self::assertSame(['404040', 'c04040'], $d->values('//w:r/w:rPr/w:color/@w:val'));
    }

    public function test_faded_text_and_borders_blend_onto_the_fill_beneath_them(): void
    {
        $d = self::export(Column::make(Text::make('x')->color('#000000'))
            ->padding(pt(4))->background('#0000ff')->border(pt(1), '#000000')->opacity(0.5));
        // the fill onto white: 8080ff; black at half onto that: 4040 80
        self::assertSame('8080ff', $d->value('//w:tc/w:tcPr/w:shd/@w:fill'));
        self::assertSame('404080', $d->value('//w:r/w:rPr/w:color/@w:val'));
        self::assertSame('404080', $d->value('//w:tc/w:tcPr/w:tcBorders/w:top/@w:color'));
    }

    public function test_faded_text_in_an_unfaded_box_blends_onto_its_fill(): void
    {
        $d = self::export(Column::make(Text::make('x')->color('#000000')->opacity(0.5))->padding(pt(4))->background('#0000ff'));
        self::assertSame('000080', $d->value('//w:r/w:rPr/w:color/@w:val'));
    }

    public function test_nested_opacity_multiplies(): void
    {
        $d = self::export(Column::make(Column::make(Text::make('x')->color('#000000'))->opacity(0.5))->opacity(0.5));
        // a quarter of the ink: 255 * 0.75
        self::assertSame('bfbfbf', $d->value('//w:r/w:rPr/w:color/@w:val'));
    }

    #[RequiresPhpExtension('gd')]
    public function test_a_picture_in_a_faded_box_is_faded_by_both(): void
    {
        $im = imagecreatetruecolor(8, 8);
        \assert($im !== false);
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        $d = self::export(Column::make(Image::bytes($png)->opacity(0.5))->opacity(0.5));
        $media = array_values(array_filter($d->names(), static fn (string $n): bool => str_starts_with($n, 'word/media/')));
        $out = imagecreatefromstring($d->part($media[0]));
        self::assertNotFalse($out);
        self::assertEqualsWithDelta(95, imagecolorsforindex($out, (int) imagecolorat($out, 4, 4))['alpha'], 1);
        self::assertSame([], self::exportedReports());
    }
}
