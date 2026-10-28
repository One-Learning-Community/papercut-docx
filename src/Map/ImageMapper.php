<?php

declare(strict_types=1);

namespace Papercut\Docx\Map;

use Dskripchenko\PhpDocx\Element\{BlockElement, Image as DocxImage, ImageFormat};
use Papercut\Backend\Backend;
use Papercut\Docx\Walk\Frame;
use Papercut\Export\{Fidelity, LoadedImage};
use Papercut\Image;
use Papercut\Style\{ComputedStyle, ImageFit};
use Papercut\Value\{Length, Percent};

/** Images as inline pictures at the size the PDF gives them. */
final class ImageMapper
{
    /** @var array<string, LoadedImage> one load per source per export */
    private array $loaded = [];

    public function __construct(
        private readonly Fidelity $fidelity,
        private readonly Backend $backend,
    ) {
    }

    /** @return list<BlockElement> */
    public function image(Image $n, ComputedStyle $s, Frame $frame, string $path): array
    {
        $img = $this->load($n);
        $format = match ($img->mime) {
            'image/png' => ImageFormat::Png,
            'image/jpeg' => ImageFormat::Jpeg,
            'image/gif' => ImageFormat::Gif,
            'image/bmp' => ImageFormat::Bmp,
            default => null,
        };
        if ($format === null) {
            $this->fidelity->dropped('image type ' . $img->mime, $path);

            return [];
        }
        $iw = max(1.0, $img->widthPx * 0.75);
        $ih = max(1.0, $img->heightPx * 0.75);
        $explicitW = $s->width instanceof Length || $s->width instanceof Percent;
        $explicitH = $s->height instanceof Length;
        $hPoints = $s->height instanceof Length ? $s->height->points : 0.0;
        [$w, $h] = match (true) {
            $explicitW && $explicitH => [$frame->width, $hPoints],
            $explicitW => [$frame->width, $frame->width * $ih / $iw],
            $explicitH => [min($frame->width, $hPoints * $iw / $ih), min($frame->width, $hPoints * $iw / $ih) * $ih / $iw],
            default => [min($frame->width, $iw), min($frame->width, $iw) * $ih / $iw],
        };
        if ($n->fitMode() === ImageFit::Cover) {
            $this->fidelity->approximated('image cover', $path);
        }
        if ($explicitW && $explicitH && $n->fitMode() !== ImageFit::Fill) {
            // Contain within the box; cover is drawn as contain (Word has no cropping to a box here).
            $scale = min($w / $iw, $h / $ih);
            [$w, $h] = [$iw * $scale, $ih * $scale];
        }

        return [new DocxImage($img->bytes, $format, Units::emu($w), Units::emu($h))];
    }

    private function load(Image $n): LoadedImage
    {
        ['kind' => $kind, 'value' => $value] = $n->source();
        $key = $kind === 'file' ? 'file:' . $value : 'bytes:' . sha1($value);

        return $this->loaded[$key] ??= LoadedImage::load($n, $this->backend);
    }
}
