<?php

declare(strict_types=1);

namespace Papercut\Docx\Map;

use Dskripchenko\PhpDocx\Element\{BlockElement, Image as DocxImage, ImageFormat};
use Papercut\Backend\Backend;
use Papercut\Docx\Walk\Frame;
use Papercut\Export\{Fidelity, LoadedImage};
use Papercut\{Image, QrCode, Svg};
use Papercut\Style\{ComputedStyle, ImageFit};
use Papercut\Value\{Length, Percent};

use function Papercut\mm;

/**
 * Images, QR codes and SVG as inline pictures at the size the PDF gives them. What the PDF does to a
 * picture (cover cropping, opacity) is drawn into it; see Raster.
 */
final class ImageMapper
{
    /** @var array<string, LoadedImage> one load per source per export */
    private array $loaded = [];

    public function __construct(
        private readonly Fidelity $fidelity,
        private readonly Backend $backend,
        private readonly Raster $raster,
    ) {
    }

    /** @return list<BlockElement> */
    public function image(Image $n, ComputedStyle $s, Frame $frame, string $path): array
    {
        $img = $this->load($n);
        $word = $this->raster->forWord($img->bytes, $img->mime);
        if ($word === null) {
            $this->fidelity->dropped('image type ' . $img->mime, $path);

            return [];
        }
        [$bytes, $format] = $word;
        $iw = max(1.0, $img->widthPx * 0.75);
        $ih = max(1.0, $img->heightPx * 0.75);
        $boxed = ($s->width instanceof Length || $s->width instanceof Percent) && $s->height instanceof Length;
        [$w, $h] = self::size($s, $frame, $iw, $ih);
        $cover = $boxed && $n->fitMode() === ImageFit::Cover;
        if ($boxed && !$cover && $n->fitMode() !== ImageFit::Fill) {
            // Contained within the box.
            $scale = min($w / $iw, $h / $ih);
            [$w, $h] = [$iw * $scale, $ih * $scale];
        }

        $mime = $format === ImageFormat::Jpeg ? 'image/jpeg' : 'image/png';
        $done = $this->raster->finish($bytes, $mime, $w, $h, $cover, $s->opacity);
        if ($done !== null) {
            [$bytes, $format] = $done;
        } else {
            if ($cover) {
                // Without GD, cover is drawn as contain: Word crops nothing to a box here.
                $this->fidelity->approximated('image cover', $path);
                $scale = min($w / $iw, $h / $ih);
                [$w, $h] = [$iw * $scale, $ih * $scale];
            }
            $this->unfinished($s, $path);
        }

        return [new DocxImage($bytes, $format, Units::emu($w), Units::emu($h))];
    }

    /** @return list<BlockElement> */
    public function qr(QrCode $n, ComputedStyle $s, Frame $frame, string $path): array
    {
        // The side as the PDF sizes it: the width, else the height, else 25mm, within the space there is.
        $side = match (true) {
            $s->width instanceof Length || $s->width instanceof Percent => $frame->width,
            $s->height instanceof Length => $s->height->points,
            default => mm(25)->points,
        };
        $side = min($side, $frame->width);
        $inner = min($side - $s->padding->horizontal() - $s->borderWidth->horizontal(), $side - $s->padding->vertical() - $s->borderWidth->vertical());
        if ($inner <= 0.0 || $s->color->isTransparent()) {
            return [];
        }
        $pixels = Raster::pixels($inner);
        $png = $n->design() === null ? null : $this->raster->styledQr($n, $s->color, $pixels);
        if ($png === null) {
            $png = $this->raster->qr($n, $s->color, $pixels);
            if ($png !== null && $n->design() !== null) {
                $this->fidelity->approximated('styled QR code', $path);
            }
        }
        if ($png === null) {
            $this->fidelity->dropped($n->debugName(), $path);

            return [];
        }
        $label = $n->value() === null ? 'QR code' : 'QR code: ' . $n->value();

        return [$this->picture($png, $s, $inner, $inner, $path, $label)];
    }

    /** @return list<BlockElement> */
    public function svg(Svg $n, ComputedStyle $s, Frame $frame, string $path): array
    {
        $doc = $n->document();
        [$w, $h] = self::size($s, $frame, max(1.0, $doc->widthPt), max(1.0, $doc->heightPt));
        $png = $this->raster->svg($doc, Raster::pixels($w), Raster::pixels($h));
        if ($png === null) {
            $this->fidelity->dropped($n->debugName(), $path);

            return [];
        }
        if ($doc->hasText) {
            $this->fidelity->approximated('SVG text in the system\'s fonts', $path);
        }

        return [$this->picture($png, $s, $w, $h, $path, $doc->title === '' ? null : $doc->title)];
    }

    /**
     * The width a picture asks for when nothing constrains it, as the PDF's layouters measure it: an explicit
     * width, else the natural width plus padding and border (a QR code takes its height, else 25mm).
     */
    public function intrinsicWidth(Image|QrCode|Svg $n, ComputedStyle $s): float
    {
        if ($s->width instanceof Length) {
            return $s->width->points;
        }
        $decor = $s->padding->horizontal() + $s->borderWidth->horizontal();

        return match (true) {
            $n instanceof QrCode => $s->height instanceof Length ? $s->height->points : mm(25)->points,
            $n instanceof Svg => $n->document()->widthPt + $decor,
            default => $this->load($n)->widthPx * 0.75 + $decor,
        };
    }

    /** A PNG Papercut drew, with the node's opacity applied. */
    private function picture(string $png, ComputedStyle $s, float $w, float $h, string $path, ?string $alt): DocxImage
    {
        $done = $this->raster->finish($png, 'image/png', $w, $h, false, $s->opacity);
        if ($done === null) {
            $this->unfinished($s, $path);
        }

        return new DocxImage($done[0] ?? $png, ImageFormat::Png, Units::emu($w), Units::emu($h), $alt);
    }

    /** Reports the opacity a picture could not take (no GD). */
    private function unfinished(ComputedStyle $s, string $path): void
    {
        if ($s->opacity < 1.0) {
            $this->fidelity->dropped('opacity', $path);
        }
    }

    /**
     * Drawn size in pt for a picture $iw x $ih pt by nature: an explicit width keeps the aspect ratio, as does
     * an explicit height; with neither, the natural size within the space there is.
     *
     * @return array{float, float}
     */
    private static function size(ComputedStyle $s, Frame $frame, float $iw, float $ih): array
    {
        $explicitW = $s->width instanceof Length || $s->width instanceof Percent;
        $hPoints = $s->height instanceof Length ? $s->height->points : null;

        return match (true) {
            $explicitW && $hPoints !== null => [$frame->width, $hPoints],
            $explicitW => [$frame->width, $frame->width * $ih / $iw],
            $hPoints !== null => [min($frame->width, $hPoints * $iw / $ih), min($frame->width, $hPoints * $iw / $ih) * $ih / $iw],
            default => [min($frame->width, $iw), min($frame->width, $iw) * $ih / $iw],
        };
    }

    private function load(Image $n): LoadedImage
    {
        ['kind' => $kind, 'value' => $value] = $n->source();
        $key = $kind === 'file' ? 'file:' . $value : 'bytes:' . sha1($value);

        return $this->loaded[$key] ??= LoadedImage::load($n, $this->backend);
    }
}
