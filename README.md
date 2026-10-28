<picture>
  <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/One-Learning-Community/papercut/main/art/papercut-logo-dark.svg">
  <img alt="Papercut" src="https://raw.githubusercontent.com/One-Learning-Community/papercut/main/art/papercut-logo.svg" width="240">
</picture>

# papercut/docx

Word (.docx) export for [Papercut](../../README.md) documents: the same document you render as a PDF, as a file
people can open and edit in Word, LibreOffice or Google Docs.

> **Status: experimental, pre-release. Not yet published to Packagist.** Word, LibreOffice and Google Docs lay out
> the file themselves, so spacing and page breaks can differ slightly from the PDF, and the output may change between
> minor versions while it settles.

## Install

Once published:

```
composer require papercut/docx
```

It needs PHP 8.4 with `ext-zip`, and `papercut/core`.

## Use

```php
use Papercut\Docx\DocxExporter;

$document->export(new DocxExporter())->save('invoice.docx');
```

With `papercut/laravel` installed too, `Papercut::view('invoice', $data)->docx()->download('invoice.docx')`, and
`?format=docx` on a preview route.

## What readers see

Word, LibreOffice and Google Docs lay out the pages themselves, so page breaks can land a line or two away from
the PDF's. Columns flow as paragraphs, rows become borderless tables at the widths the PDF uses, and a box with
padding, a border or a background becomes a one-cell table.

- One page setup for the whole document, taken from the first page; other pages' sizes, headers and footers are
  reported as approximated.
- Fonts are named, not embedded: the reader's machine supplies them.
- Pictures Word can't take as they are are drawn at the size the PDF gives them: WebP and AVIF images, image
  `cover` and opacity, and plain QR codes need `ext-gd`; styled QR codes need `ext-imagick` and
  `bacon/bacon-qr-code`; SVG needs an `ext-imagick` that reads SVG. Without them, these are left out and reported.
- Opacity is drawn by blending each faded colour onto what lies beneath it; text backgrounds keep their exact colour.
- Corner radius on boxes, background and overlay layers, PDF page templates
  and embedded PDF pages are left out and reported (strict mode throws on them).

Approximations and omissions are reported through `ExportOptions::onWarning` with the node's path, as with HTML
export. How the layout itself is written (rows as tables, boxes as one-cell tables, colours with transparency blended
onto white, fonts by name) applies to every document and isn't reported each time.
