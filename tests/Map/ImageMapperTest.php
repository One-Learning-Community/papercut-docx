<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests\Map;

use Papercut\Image;
use Papercut\Docx\Tests\Support\Styles;
use Papercut\Style\ImageFit;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

use function Papercut\{mm, pt};

#[RequiresPhpExtension('gd')]
final class ImageMapperTest extends TestCase
{
    use Styles;

    /**
     * @param positive-int $w
     * @param positive-int $h
     */
    private static function picture(string $type, int $w = 80, int $h = 40): string
    {
        $im = imagecreatetruecolor($w, $h);
        \assert($im !== false);
        imagefill($im, 0, 0, (int) imagecolorallocate($im, 30, 120, 200));
        ob_start();
        match ($type) {
            'png' => imagepng($im),
            'jpeg' => imagejpeg($im),
            'webp' => imagewebp($im),
            default => throw new \LogicException($type),
        };

        return (string) ob_get_clean();
    }

    public function test_png_and_jpeg_at_natural_size(): void
    {
        foreach (['png', 'jpeg'] as $type) {
            $d = self::export(Image::bytes(self::picture($type)));
            self::assertSame((string) (60 * 12700), $d->value('//wp:inline/wp:extent/@cx'), $type);
            self::assertSame((string) (30 * 12700), $d->value('//wp:inline/wp:extent/@cy'), $type);
        }
    }

    public function test_explicit_width_keeps_the_aspect_ratio(): void
    {
        $d = self::export(Image::bytes(self::picture('png'))->width(mm(50)));
        $cx = (int) $d->value('//wp:inline/wp:extent/@cx');
        $cy = (int) $d->value('//wp:inline/wp:extent/@cy');
        self::assertEqualsWithDelta(mm(50)->points * 12700, $cx, 1);
        self::assertEqualsWithDelta($cx / 2, $cy, 1);
    }

    public function test_cover_is_approximated(): void
    {
        self::export(Image::bytes(self::picture('png'))->width(pt(100))->height(pt(100))->fit(ImageFit::Cover));
        self::assertSame(['Approximated image cover @ page > body > image[0]'], self::exportedReports());
    }

    public function test_formats_word_cannot_take_are_dropped(): void
    {
        $d = self::export(Image::bytes(self::picture('webp')));
        self::assertSame(0, $d->count('//wp:inline'));
        self::assertSame(['Dropped image type image/webp @ page > body > image[0]'], self::exportedReports());
    }
}
