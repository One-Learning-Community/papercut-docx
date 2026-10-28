<?php

declare(strict_types=1);

namespace Papercut\Docx\Package;

use Dskripchenko\PhpDocx\Style\{Orientation, PageSetup, PaperSize};
use Papercut\Docx\Map\Units;
use Papercut\Page;

/** A Papercut page as Word's section setup, at its exact size. The paper name is kept when one matches. */
final class PageSetupMapper
{
    /** Points per paper size, portrait. */
    private const array SIZES = [
        'A3' => [841.89, 1190.55],
        'A4' => [595.28, 841.89],
        'A5' => [419.53, 595.28],
        'Letter' => [612.0, 792.0],
        'Legal' => [612.0, 1008.0],
    ];

    public static function setup(Page $page): PageSetup
    {
        $m = $page->margins();
        [$short, $long] = [min($page->width(), $page->height()), max($page->width(), $page->height())];
        $paper = PaperSize::A4;
        foreach (self::SIZES as $name => [$w, $h]) {
            if (abs($w - $short) < 1.5 && abs($h - $long) < 1.5) {
                $paper = PaperSize::from($name);
            }
        }

        // Papercut draws the header at the top margin and the body below it; Word draws the header at its own
        // distance from the edge and pushes the body below the header (the gap is spacing on its last paragraph).
        return new PageSetup(
            paperSize: $paper,
            orientation: $page->width() > $page->height() ? Orientation::Landscape : Orientation::Portrait,
            marginTopTwips: Units::tw($m->top->points),
            marginRightTwips: Units::tw($m->right->points),
            marginBottomTwips: Units::tw($m->bottom->points),
            marginLeftTwips: Units::tw($m->left->points),
            headerOffsetTwips: Units::tw($m->top->points),
            footerOffsetTwips: Units::tw($m->bottom->points),
            widthTwips: Units::tw($page->width()),
            heightTwips: Units::tw($page->height()),
        );
    }
}
