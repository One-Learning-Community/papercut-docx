<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/One-Learning-Community/quoin/main/art/quoin-logo-dark.svg">
  <img alt="Quoin" src="https://raw.githubusercontent.com/One-Learning-Community/quoin/main/art/quoin-logo.svg" width="177">
</picture>

# quoin/docx

Word (.docx) export for [Quoin](../../README.md) documents: the same document you render as a PDF, as a file
people can open and edit in Word, LibreOffice or Google Docs.

The Word file is written by [`dskripchenko/php-docx`](https://github.com/dskripchenko/php-docx), by Denis Skripchenko. The exporter maps the layout onto
Word's paragraphs and tables; php-docx writes the file. Thank you.

**Documentation:** [Word export](https://one-learning-community.github.io/quoin/guide/word-export.html)

> Word, LibreOffice and Google Docs lay out the file themselves, so spacing and page breaks can differ slightly from
> the PDF.

## Install

```
composer require quoin/docx
```

It needs PHP 8.4 with `ext-zip`, and `quoin/core`.

## Use

```php
use Quoin\Docx\DocxExporter;

$document->export(new DocxExporter())->save('invoice.docx');
```

With `quoin/laravel` installed too, `Quoin::view('invoice', $data)->docx()->download('invoice.docx')`, and
`?format=docx` on a preview route.

## What readers see

Word, LibreOffice and Google Docs lay out the pages themselves, so page breaks can land a line or two away from
the PDF's. Columns flow as paragraphs, rows become borderless tables at the widths the PDF uses, and a box with
padding, a border or a background becomes a one-cell table.

- Each run of pages with the same size, margins, header, footer and layers becomes a Word section, so pages
  keep their own setup and headers.
- An image, QR code or SVG in a background (before anything else in it), or alone in an overlay place, becomes a
  floating picture at the place the PDF draws it, on every page. Word draws it with the header, so an overlay sits
  behind the body's text.
- Fonts are named, not embedded: the reader's machine supplies them.
- Pictures Word can't take as they are are drawn at the size the PDF gives them: WebP and AVIF images, image
  `cover` and opacity, and plain QR codes need `ext-gd`; styled QR codes need `ext-imagick` and
  `bacon/bacon-qr-code`; SVG needs librsvg's `rsvg-convert` (set `DocxOptions(rsvgConvert:)`, or
  `QUOIN_RSVG_CONVERT` in Laravel) or an `ext-imagick` that reads SVG. Without them, these are left out and
  reported.
- Opacity is drawn by blending each faded color onto what lies beneath it; text backgrounds keep their exact color.
- Corner radius on boxes, other content in background and overlay layers, a border or background on a layer
  picture, PDF page templates
  and embedded PDF pages are left out and reported (strict mode throws on them).

Approximations and omissions are reported through `ExportOptions::onWarning` with the node's path, as with HTML
export. How the layout itself is written (rows as tables, boxes as one-cell tables, colors with transparency blended
onto white, fonts by name) applies to every document and isn't reported each time.
