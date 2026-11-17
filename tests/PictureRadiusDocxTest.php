<?php

declare(strict_types=1);

namespace Quoin\Docx\Tests;

use Quoin\{Column, Document, Image, Page, QrCode, Row, Svg, Table, Text};
use Quoin\Docx\{DocxExporter, DocxOptions};
use PHPUnit\Framework\TestCase;

use function Quoin\pt;

/** Word draws pictures square: a radius on one is reported, with or without a border. */
final class PictureRadiusDocxTest extends TestCase
{
    /** @return array<string, array{\Closure}> */
    public static function pictures(): array
    {
        $im = imagecreatetruecolor(40, 20);
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><rect width="10" height="10"/></svg>';

        return [
            'image' => [static fn () => Image::bytes($png)->width(pt(60))->radius(pt(6))],
            'image with border' => [static fn () => Image::bytes($png)->width(pt(60))->radius(pt(6))->border(pt(1), 'red-500')],
            'image in column' => [static fn () => Column::make(Image::bytes($png)->width(pt(60))->radius(pt(6)))],
            'svg' => [static fn () => Svg::fromString($svg)->width(pt(60))->radius(pt(6))],
            'row child' => [static fn () => Row::make(Image::bytes($png)->width(pt(60))->radius(pt(6)), Text::p('x'))],
            'row child qr' => [static fn () => Row::make(QrCode::make('x')->width(pt(60))->radius(pt(6)), Text::p('x'))],
            'table cell' => [static fn () => Table::make()->row(Image::bytes($png)->width(pt(60))->radius(pt(6)), 'x')],
            'qr' => [static fn () => QrCode::make('x')->width(pt(60))->radius(pt(6))],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pictures')]
    public function test_a_picture_radius_is_reported_once(\Closure $make): void
    {
        $warnings = [];
        Document::make(Page::a4()->body($make()))->export(new DocxExporter(), new DocxOptions(onWarning: static function (string $m) use (&$warnings): void {
            $warnings[] = $m;
        }));
        self::assertCount(1, array_filter($warnings, static fn (string $m): bool => str_contains($m, 'dropped radius')), implode("\n", $warnings));
    }
}
