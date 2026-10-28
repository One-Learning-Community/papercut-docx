<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests\Support;

use Papercut\Export\{ExportOptions, Fidelity, FidelityReport};
use Papercut\{Component, Document, Node, Page};
use Papercut\Docx\DocxExporter;
use Papercut\Style\{ComputedStyle, StyleResolver, Theme};
use Papercut\Tree\{Region, Visit};

/** Small helpers for mapper tests: a node's computed style, a visit, a recording Fidelity. */
trait Styles
{
    private static function styleOf(Node $node): ComputedStyle
    {
        $theme = Theme::default();

        return (new StyleResolver($theme))->resolve($node, ComputedStyle::root($theme));
    }

    private static function visit(string $path = 'page > body > text[0]', int $index = 0): Visit
    {
        return new Visit($path, $index, null, false, Region::Body);
    }

    private static function fidelity(bool $strict = false): Fidelity
    {
        return new Fidelity('docx', new ExportOptions(strict: $strict));
    }

    /** @return list<string> "level feature @ path" per report */
    private static function reports(Fidelity $f): array
    {
        return array_map(static fn (FidelityReport $r): string => $r->level->name . ' ' . $r->feature . ' @ ' . $r->path, $f->reports());
    }

    /** @var list<FidelityReport> reports from the last export() */
    private static array $exported = [];

    /** Exports a node (as an A4 body), a page or a document; reports land in self::$exported. */
    private static function export(Node|Component|Page|Document $what, bool $strict = false): DocxInspector
    {
        $doc = match (true) {
            $what instanceof Document => $what,
            $what instanceof Page => Document::make($what),
            default => Document::make(Page::a4()->body($what)),
        };
        self::$exported = [];
        $options = new ExportOptions(
            onWarning: static function (string $m, ?FidelityReport $r = null): void {
                if ($r !== null) {
                    self::$exported[] = $r;
                }
            },
            strict: $strict,
        );

        return DocxInspector::of($doc->export(new DocxExporter(), $options)->bytes());
    }

    /** @return list<string> "level feature @ path" for the last export() */
    private static function exportedReports(): array
    {
        return array_map(static fn (FidelityReport $r): string => $r->level->name . ' ' . $r->feature . ' @ ' . $r->path, self::$exported);
    }
}
