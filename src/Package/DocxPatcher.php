<?php

declare(strict_types=1);

namespace Papercut\Docx\Package;

use Papercut\Export\Fidelity;
use Papercut\Text\{FontClass, FontInfo};
use Papercut\Value\ColumnSpec;

/**
 * Fixes php-docx's output where it can't express what Papercut needs: `left`/`right` alignment
 * (Google Docs ignores `start`/`end`), run shading in any colour, the theme's default font, and the document language.
 * It edits the exact markup php-docx writes and throws when that markup changes, so an upgrade fails loudly.
 *
 * @internal Part of Papercut's engine, not its public API. It may change in any release.
 */
final class DocxPatcher
{
    private const string DOC_DEFAULTS = '<w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:sz w:val="22"/><w:szCs w:val="22"/></w:rPr></w:rPrDefault>';

    /** Marks a run's highlight as run shading in a colour of its own; see shading(). */
    private const string SHADING = 'papercut-shd-';

    /** Marks a border colour as also carrying the border's space, in points; see spaced(). */
    private const string SPACE = 'papercut-sp';

    /** The text of a run that patch() turns into a column break (`w:br w:type="column"`); see columnBreak(). */
    public const string COLUMN_BREAK = 'papercut-column-break';

    /** The text of a run that patch() replaces with an equation's OMML; the equation's id follows. See PatchSpec::withMath(). */
    public const string MATH = 'papercut-math-';

    /** A highlight value patch() turns into run shading in $hex (six lower-case hex digits). */
    public static function shading(string $hex): string
    {
        return self::SHADING . $hex;
    }

    /**
     * A highlight value patch() turns into a run border (`w:bdr`, $eighths of a point wide, `single` or `dashed`,
     * in $hex), with run shading in $fill when it is given. Word draws adjacent runs with the same border as one box.
     */
    public static function boxed(?string $fill, int $eighths, string $style, string $hex): string
    {
        return self::SHADING . ($fill ?? 'none') . '-bdr' . $eighths . '-' . $style . '-' . $hex;
    }

    /** A border colour that carries its padding: patch() writes the padding as the border's space. */
    public static function spaced(int $points, string $hex): string
    {
        return self::SPACE . $points . '-' . $hex;
    }

    public static function patch(string $bytes, PatchSpec $spec, ?Fidelity $fidelity = null): string
    {
        $parts = self::read($bytes);
        foreach ($parts as $name => $xml) {
            if (preg_match('~^word/(document|styles|header\d+|footer\d+)\.xml$~', $name) === 1) {
                $xml = str_replace(['<w:jc w:val="start"/>', '<w:jc w:val="end"/>'], ['<w:jc w:val="left"/>', '<w:jc w:val="right"/>'], $xml);
                $xml = (string) preg_replace_callback(
                    '~<w:highlight w:val="' . self::SHADING . '(none|[0-9a-f]{6})-bdr(\\d+)-(single|dashed)-([0-9a-f]{6})"/>~',
                    static fn (array $m): string => '<w:bdr w:val="' . $m[3] . '" w:sz="' . $m[2] . '" w:space="0" w:color="' . $m[4] . '"/>'
                        . ($m[1] === 'none' ? '' : '<w:shd w:val="clear" w:color="auto" w:fill="' . $m[1] . '"/>'),
                    $xml,
                );
                $xml = (string) preg_replace('~<w:highlight w:val="' . self::SHADING . '([0-9a-f]{6})"/>~', '<w:shd w:val="clear" w:color="auto" w:fill="$1"/>', $xml);
                $xml = (string) preg_replace('~w:space="0" w:color="' . self::SPACE . '(\d+)-([0-9A-Fa-f]{6})"~', 'w:space="$1" w:color="$2"', $xml);
                // The rewrites depend on php-docx's attribute order (w:space before w:color) and fail loudly if it changes.
                // Only attribute values are checked: body text may contain the marker words.
                if (preg_match('~w:val="' . self::SHADING . '~', $xml) === 1) {
                    throw new \LogicException('php-docx output changed: run highlight in ' . $name . ' not found');
                }
                if (preg_match('~w:color="' . self::SPACE . '~', $xml) === 1) {
                    throw new \LogicException('php-docx output changed: border space in ' . $name . ' not found');
                }
                $parts[$name] = $xml;
            }
        }

        if ($spec->columns !== []) {
            $parts = self::columns($parts, $spec->columns);
        }
        $parts = self::columnBreaks($parts, $spec->columnBreaks);
        $parts = self::maths($parts, $spec->maths, $spec->inlineMaths);

        $font = self::attr($spec->fontFamily);
        $lang = $spec->lang === null ? '' : '<w:lang w:val="' . self::attr($spec->lang) . '"/>';
        $parts['word/styles.xml'] = self::replaceOnce(
            '~' . preg_quote(self::DOC_DEFAULTS, '~') . '~',
            '<w:rPrDefault><w:rPr><w:rFonts w:ascii="' . $font . '" w:hAnsi="' . $font . '" w:cs="' . $font . '"/>'
                . '<w:sz w:val="' . $spec->fontSizeHalfPoints . '"/><w:szCs w:val="' . $spec->fontSizeHalfPoints . '"/>' . $lang . '</w:rPr></w:rPrDefault>',
            $parts['word/styles.xml'] ?? '',
            'default run properties in word/styles.xml',
        );

        if ($spec->mirrorMargins) {
            $parts = self::mirrorMargins($parts);
        }

        if ($spec->numbering !== null && !$spec->numbering->isEmpty()) {
            $parts = self::numbering($parts, $spec->numbering);
        }

        if ($spec->tocs !== []) {
            $parts = self::tocs($parts, $spec->tocs, $fidelity);
        }

        if ($spec->fonts !== []) {
            $parts = self::fontTable($parts, $spec->fonts);
        }

        return DeterministicZip::write($parts, $spec->time);
    }

    /**
     * The `w:cols` element for a flow's section: equal columns, or each column's width and the space after it when the
     * widths or the gaps differ. A rule is `w:sep`. Twips are rounded from points.
     */
    public static function colsXml(ColumnSpec $spec, float $widthPt): string
    {
        $sep = $spec->rule === null ? '' : ' w:sep="1"';
        $tw = static fn (float $pt): int => (int) round($pt * 20);
        if ($spec->shares === null && count(array_unique($spec->gaps)) <= 1) {
            return '<w:cols w:num="' . $spec->count . '" w:space="' . $tw($spec->gaps[0] ?? ColumnSpec::DEFAULT_GAP) . '"' . $sep . '/>';
        }
        $cols = '';
        foreach ($spec->columns($widthPt) as $i => $column) {
            $cols .= '<w:col w:w="' . $tw($column['width']) . '"' . ($i < $spec->count - 1 ? ' w:space="' . $tw($spec->gaps[$i]) . '"' : '') . '/>';
        }

        return '<w:cols w:num="' . $spec->count . '" w:equalWidth="0"' . $sep . '>' . $cols . '</w:cols>';
    }

    /**
     * Puts each section's `w:cols` right after its `w:pgMar` (schema order: pgMar, ..., cols, ..., titlePg). Sections are
     * counted in document order: the ones that end in a paragraph's properties, then the body's own.
     *
     * @param array<string, string> $parts
     * @param array<int, string> $columns
     * @return array<string, string>
     */
    private static function columns(array $parts, array $columns): array
    {
        $index = -1;
        $parts['word/document.xml'] = (string) preg_replace_callback(
            '~<w:sectPr>.*?</w:sectPr>~s',
            static function (array $m) use (&$index, $columns): string {
                $index++;
                if (!isset($columns[$index])) {
                    return $m[0];
                }
                $out = preg_replace('~(<w:pgMar\b[^>]*/>)~', '$1' . str_replace(['\\', '$'], ['\\\\', '\\$'], $columns[$index]), $m[0], 1, $count);
                if ($out === null || $count !== 1) {
                    throw new \LogicException('php-docx output changed: section ' . $index . ' properties not found');
                }

                return $out;
            },
            $parts['word/document.xml'] ?? '',
        );
        foreach (array_keys($columns) as $wanted) {
            if ($wanted > $index) {
                throw new \LogicException('php-docx output changed: section ' . $wanted . ' properties not found');
            }
        }

        return $parts;
    }

    /**
     * Turns the $count runs Flow::columnBreak() wrote into column break runs. Only that run's exact markup counts, so
     * body text that happens to hold the sentinel is left alone; nothing is touched when $count is 0.
     *
     * @param array<string, string> $parts
     * @return array<string, string>
     */
    private static function columnBreaks(array $parts, int $count): array
    {
        if ($count === 0) {
            return $parts;
        }
        $run = '<w:r><w:rPr><w:sz w:val="2"/><w:szCs w:val="2"/></w:rPr><w:t xml:space="preserve">' . self::COLUMN_BREAK . '</w:t></w:r>';
        $xml = $parts['word/document.xml'] ?? '';
        $found = substr_count($xml, $run);
        if ($found < $count) {
            throw new \LogicException('php-docx output changed: column break run not found');
        }
        $parts['word/document.xml'] = str_replace($run, '<w:r><w:br w:type="column"/></w:r>', $xml);

        return $parts;
    }

    /**
     * Replaces each equation's marker run (`<w:r>` holding only `papercut-math-<id>`) with its OMML, and declares the
     * math namespace on the root of every part that got one. A declared equation whose marker is gone, or a marker
     * with no equation, throws: the equation would silently vanish or leak into the document as text.
     *
     * @param array<string, string> $parts
     * @param array<int, string> $maths
     * @param array<int, true> $inline ids of the equations that sit among runs; a block equation's run must be its paragraph's only child
     * @return array<string, string>
     */
    private static function maths(array $parts, array $maths, array $inline): array
    {
        if ($maths === []) {
            return $parts;
        }
        $seen = [];
        $ns = 'xmlns:m="http://schemas.openxmlformats.org/officeDocument/2006/math"';
        foreach ($parts as $name => $xml) {
            if (preg_match('~^word/(document|header\d+|footer\d+)\.xml$~', $name) !== 1) {
                continue;
            }
            $replaced = 0;
            $xml = (string) preg_replace_callback(
                '~(</w:pPr>|<w:p>)?<w:r>(?:<w:rPr>(?:(?!</w:rPr>).)*</w:rPr>)?<w:t(?: xml:space="preserve")?>' . self::MATH . '(\d+)</w:t></w:r>(</w:p>)?~s',
                static function (array $m) use ($maths, $inline, &$seen, &$replaced): string {
                    $id = (int) $m[2];
                    if (!isset($maths[$id])) {
                        throw new \LogicException('php-docx output changed: equation marker ' . $id . ' has no equation');
                    }
                    if (!isset($inline[$id]) && ($m[1] === '' || ($m[3] ?? '') === '')) {
                        throw new \LogicException('Equation ' . $id . ' is a block but shares its paragraph with other content');
                    }
                    $seen[$id] = true;
                    $replaced++;

                    return $m[1] . $maths[$id] . ($m[3] ?? '');
                },
                $xml,
            );
            if ($replaced > 0) {
                if (str_contains($xml, $ns)) {
                    $root = null;
                } else {
                    $xml = (string) preg_replace('~<(w:(?:document|hdr|ftr))\b~', '<$1 ' . $ns, $xml, 1, $root);
                }
                if ($root === 0) {
                    throw new \LogicException('php-docx output changed: root element of ' . $name . ' not found');
                }
                $parts[$name] = $xml;
            }
            if (preg_match('~<w:t(?: xml:space="preserve")?>[^<]*' . self::MATH . '\d+~', $xml) === 1) {
                throw new \LogicException('Marker-like text was left in ' . $name . ': either an equation marker was mangled (php-docx output changed) or the document text contains "' . self::MATH . 'N"');
            }
        }
        foreach ($maths as $id => $_) {
            if (!isset($seen[$id])) {
                throw new \LogicException('php-docx output changed: equation ' . $id . ' marker not found');
            }
        }

        return $parts;
    }

    /**
     * Replaces each table of contents' marker paragraph with the field's paragraphs, the way Word writes a TOC field:
     * `begin`, the instruction and `separate` in the first entry, `end` in the last, and each entry a link to its
     * heading's bookmark with the page number as a cached PAGEREF field. The marker's spacing moves to the field.
     * php-docx writes single-paragraph fields only, and this one spans paragraphs.
     *
     * @param array<string, string> $parts
     * A marker php-docx lost leaves that table of contents out and is reported as dropped.
     *
     * @param list<TocField> $tocs
     * @return array<string, string>
     */
    private static function tocs(array $parts, array $tocs, ?Fidelity $fidelity): array
    {
        $xml = $parts['word/document.xml'] ?? '';
        foreach ($tocs as $i => $toc) {
            $marker = TocField::MARKER . ($i + 1);
            $xml = (string) preg_replace_callback(
                '~<w:p>(?:(?!<w:p[ >/]).)*?' . preg_quote($marker, '~') . '</w:t></w:r></w:p>~s',
                static fn (array $m): string => self::tocParagraphs($toc, $m[0]),
                $xml,
                -1,
                $count,
            );
            if ($count !== 1) {
                $fidelity?->dropped('table of contents', 'toc[' . ($i + 1) . ']');
            }
        }
        $parts['word/document.xml'] = $xml;

        return $parts;
    }

    private static function tocParagraphs(TocField $toc, string $marker): string
    {
        $before = preg_match('~<w:spacing\b[^>]*\bw:before="(\d+)"~', $marker, $b) === 1 ? (int) $b[1] : 0;
        $after = preg_match('~<w:spacing\b[^>]*\bw:after="(\d+)"~', $marker, $a) === 1 ? (int) $a[1] : 0;
        $leader = $toc->leaders ? 'dot' : 'none';
        $last = count($toc->entries) - 1;
        $out = '';
        foreach ($toc->entries as $i => $e) {
            $r = $e->runProperties;
            $field = static fn (string $inner): string => '<w:r>' . $r . $inner . '</w:r>';
            $pageref = $field('<w:fldChar w:fldCharType="begin"/>')
                . $field('<w:instrText xml:space="preserve"> PAGEREF ' . self::attr($e->bookmark) . ' \\h </w:instrText>')
                . $field('<w:fldChar w:fldCharType="separate"/>')
                . ($e->page === null ? '' : $field('<w:t>' . $e->page . '</w:t>'))
                . $field('<w:fldChar w:fldCharType="end"/>');
            $out .= '<w:p><w:pPr><w:tabs><w:tab w:val="right" w:leader="' . $leader . '" w:pos="' . $toc->tabTwips . '"/></w:tabs>'
                . '<w:spacing w:before="' . ($e->beforeTwips + ($i === 0 ? $before : 0)) . '" w:after="' . ($e->afterTwips + ($i === $last ? $after : 0))
                . '" w:line="' . $e->lineTwips . '" w:lineRule="auto"/><w:ind w:left="' . $e->indentTwips . '"/></w:pPr>'
                . ($i === 0
                    ? '<w:r><w:fldChar w:fldCharType="begin"/></w:r>'
                        . '<w:r><w:instrText xml:space="preserve"> TOC \\o "' . $toc->from . '-' . $toc->to . '" \\h \\z \\u </w:instrText></w:r>'
                        . '<w:r><w:fldChar w:fldCharType="separate"/></w:r>'
                    : '')
                . '<w:hyperlink w:anchor="' . self::attr($e->bookmark) . '" w:history="1">'
                . $field('<w:t xml:space="preserve">' . self::attr($e->text) . '</w:t>')
                . $field('<w:tab/>')
                . $pageref
                . '</w:hyperlink>'
                . ($i === $last ? '<w:r><w:fldChar w:fldCharType="end"/></w:r>' : '')
                . '</w:p>';
        }

        return $out;
    }

    /**
     * Adds word/fontTable.xml with each written font's PANOSE number, family class and pitch, which Word uses to pick
     * a stand-in when the font is not installed. A table php-docx already wrote keeps its entries; ours are appended.
     *
     * @param array<string, string> $parts
     * @param array<string, ?FontInfo> $fonts
     * @return array<string, string>
     */
    private static function fontTable(array $parts, array $fonts): array
    {
        $existing = $parts['word/fontTable.xml'] ?? null;
        $have = [];
        if ($existing !== null && preg_match_all('~<w:font\b[^>]*?\bw:name\s*=\s*(?:"([^"]*)"|\'([^\']*)\')~', $existing, $found, PREG_SET_ORDER) !== false) {
            foreach ($found as $f) {
                $have[html_entity_decode(($f[1] ?? '') !== '' ? $f[1] : ($f[2] ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8')] = true;
            }
        }
        $entries = '';
        foreach ($fonts as $name => $info) {
            if (isset($have[$name])) {
                continue;
            }
            $entries .= '<w:font w:name="' . self::attr($name) . '">'
                . ($info?->panose === null ? '' : '<w:panose1 w:val="' . $info->panose . '"/>')
                . '<w:charset w:val="00"/>'
                . '<w:family w:val="' . ($info === null ? FontClass::Other : $info->class)->value . '"/>'
                . '<w:pitch w:val="' . ($info !== null && $info->fixedPitch ? 'fixed' : 'variable') . '"/>'
                . '</w:font>';
        }
        if ($existing !== null) {
            $parts['word/fontTable.xml'] = preg_match('~<w:fonts\b[^>]*?/>~', $existing) === 1
                ? (string) preg_replace_callback('~<w:fonts\b([^>]*?)\s*/>~', static fn (array $m): string => '<w:fonts' . $m[1] . '>' . $entries . '</w:fonts>', $existing, 1)
                : self::replaceOnce('~</w:fonts>~', $entries . '</w:fonts>', $existing, '</w:fonts> in word/fontTable.xml');

            return $parts;
        }

        return self::addPart(
            $parts,
            'word/fontTable.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<w:fonts xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">' . $entries . '</w:fonts>',
            'http://schemas.openxmlformats.org/officeDocument/2006/relationships/fontTable',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.fontTable+xml',
        );
    }

    /**
     * @param array<string, string> $parts
     * @return array<string, string>
     */
    private static function mirrorMargins(array $parts): array
    {
        if (isset($parts['word/settings.xml'])) {
            if (preg_match('~<w:mirrorMargins\b~', $parts['word/settings.xml']) === 1) {
                return $parts;
            }
            $parts['word/settings.xml'] = self::insertMirrorMargins($parts['word/settings.xml']);

            return $parts;
        }

        return self::addPart(
            $parts,
            'word/settings.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<w:settings xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:mirrorMargins/></w:settings>',
            'http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml',
        );
    }

    /** The settings that must come before w:mirrorMargins in CT_Settings (ECMA-376 17.15.1.78). */
    private const array BEFORE_MIRROR = [
        'writeProtection', 'view', 'zoom', 'removePersonalInformation', 'removeDateAndTime', 'doNotDisplayPageBoundaries',
        'displayBackgroundShape', 'printPostScriptOverText', 'printFractionalCharacterWidth', 'printFormsData',
        'embedTrueTypeFonts', 'embedSystemFonts', 'saveSubsetFonts', 'saveFormsData',
    ];

    /** Puts w:mirrorMargins after the last setting that precedes it in schema order, else first in w:settings. */
    private static function insertMirrorMargins(string $xml): string
    {
        $at = null;
        if (preg_match_all('~<w:(?:' . implode('|', self::BEFORE_MIRROR) . ')\b[^>]*?/>~', $xml, $m, PREG_OFFSET_CAPTURE) > 0) {
            [$last, $offset] = $m[0][count($m[0]) - 1];
            $at = $offset + strlen($last);
        } elseif (preg_match('~<w:settings\b[^>]*?(/?)>~', $xml, $open, PREG_OFFSET_CAPTURE) === 1) {
            if ($open[1][0] === '/') {
                // <w:settings .../> with no content: open it.
                return substr($xml, 0, $open[0][1] + strlen($open[0][0]) - 2) . '><w:mirrorMargins/></w:settings>' . substr($xml, $open[0][1] + strlen($open[0][0]));
            }
            $at = $open[0][1] + strlen($open[0][0]);
        }
        if ($at === null) {
            throw new \LogicException('php-docx output changed: w:settings not found in word/settings.xml');
        }

        return substr($xml, 0, $at) . '<w:mirrorMargins/>' . substr($xml, $at);
    }

    /**
     * Turns the paragraph styles ListMapper reserved into `w:numPr` (and the heading style back), and adds numbering.xml.
     *
     * @param array<string, string> $parts
     * @return array<string, string>
     */
    private static function numbering(array $parts, Numbering $numbering): array
    {
        $marker = '~<w:p><w:pPr><w:pStyle w:val="' . Numbering::STYLE_PREFIX . '(\d+)-(\d+)(?:-h(\d+))?"/>((?:<w:(?:keepNext|keepLines|pageBreakBefore|widowControl)(?: [^>]*)?/>)*)(.*?)</w:pPr>(.*?)</w:p>~s';
        foreach ($parts as $name => $xml) {
            if (preg_match('~^word/(document|header\d+|footer\d+)\.xml$~', $name) !== 1) {
                continue;
            }
            $xml = (string) preg_replace_callback(
                $marker,
                static function (array $m) use ($numbering): string {
                    if ((int) $m[1] < 1 || (int) $m[1] > $numbering->count()) {
                        throw new \LogicException('php-docx output changed: list paragraph refers to unregistered numId ' . $m[1]);
                    }

                    return '<w:p><w:pPr>' . ($m[3] === '' ? '' : '<w:pStyle w:val="Heading' . $m[3] . '"/>') . $m[4]
                        . '<w:numPr><w:ilvl w:val="' . $m[2] . '"/><w:numId w:val="' . $m[1] . '"/></w:numPr>'
                        . self::withLabelFormat($m[5], $m[6]) . '</w:pPr>' . $m[6] . '</w:p>';
                },
                $xml,
            );
            if (str_contains($xml, 'w:val="' . Numbering::STYLE_PREFIX)) {
                throw new \LogicException('php-docx output changed: list paragraph style in ' . $name . ' not found');
            }
            $parts[$name] = $xml;
        }

        return self::addPart(
            $parts,
            'word/numbering.xml',
            $numbering->xml(),
            'http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml',
        );
    }

    /** The label-affecting run properties, in the order CT_RPr writes them. */
    private const array LABEL_PROPS = ['rFonts', 'b', 'bCs', 'i', 'iCs', 'color', 'sz', 'szCs'];

    /**
     * The rest of a numbered paragraph's `w:pPr` with the first run's font, weight, slant, colour and size on the paragraph mark,
     * which is what Word draws the list label with. Goes after everything but `w:sectPr`; merges with a mark `w:rPr` already there.
     */
    private static function withLabelFormat(string $pPr, string $content): string
    {
        $found = [];
        if (preg_match('~<w:r(?: [^>]*)?>(?:<w:rPr>(.*?)</w:rPr>)?~s', $content, $run) === 1 && ($run[1] ?? '') !== '') {
            if (preg_match_all('~<w:(' . implode('|', self::LABEL_PROPS) . ')(?: [^>]*)?/>~', $run[1], $props, PREG_SET_ORDER) === false) {
                throw new \LogicException('php-docx output changed: run properties unreadable');
            }
            foreach ($props as $p) {
                $found[$p[1]] = $p[0];
            }
        }
        if ($found === []) {
            return $pPr;
        }
        $sect = '';
        if (preg_match('~<w:sectPr[ >].*$~s', $pPr, $s) === 1) {
            $sect = $s[0];
            $pPr = substr($pPr, 0, -strlen($sect));
        }
        if (preg_match('~<w:rPr>(.*?)</w:rPr>~s', $pPr, $mark) === 1) {
            if (preg_match_all('~<w:(' . implode('|', self::LABEL_PROPS) . ')(?: [^>]*)?/>~', $mark[1], $own, PREG_SET_ORDER) === false) {
                throw new \LogicException('php-docx output changed: paragraph mark properties unreadable');
            }
            foreach ($own as $p) {
                $found[$p[1]] = $p[0];
            }
            $pPr = str_replace($mark[0], '', $pPr);
        }
        $rPr = '';
        foreach (self::LABEL_PROPS as $prop) {
            $rPr .= $found[$prop] ?? '';
        }

        return $pPr . '<w:rPr>' . $rPr . '</w:rPr>' . $sect;
    }

    /**
     * Adds a part under word/ that php-docx did not write: the file, its relationship in document.xml.rels (a new rId)
     * and its content-type override.
     *
     * @param array<string, string> $parts
     * @return array<string, string>
     */
    private static function addPart(array $parts, string $name, string $xml, string $relType, string $contentType): array
    {
        if (isset($parts[$name])) {
            throw new \LogicException('php-docx output changed: ' . $name . ' already exists');
        }
        $rels = $parts['word/_rels/document.xml.rels'] ?? '';
        preg_match_all('~\bId="rId(\d+)"~', $rels, $m);
        $id = 'rId' . ($m[1] === [] ? 1 : max(array_map('intval', $m[1])) + 1);
        $parts['word/_rels/document.xml.rels'] = self::replaceOnce(
            '~</Relationships>~',
            '<Relationship Id="' . $id . '" Type="' . $relType . '" Target="' . self::attr(substr($name, strlen('word/'))) . '"/></Relationships>',
            $rels,
            '</Relationships> in word/_rels/document.xml.rels',
        );
        $parts['[Content_Types].xml'] = self::replaceOnce(
            '~</Types>~',
            '<Override PartName="/' . self::attr($name) . '" ContentType="' . $contentType . '"/></Types>',
            $parts['[Content_Types].xml'] ?? '',
            '</Types> in [Content_Types].xml',
        );
        $parts[$name] = $xml;

        return $parts;
    }

    private static function replaceOnce(string $pattern, string $replacement, string $subject, string $what): string
    {
        $out = preg_replace($pattern, str_replace(['\\', '$'], ['\\\\', '\\$'], $replacement), $subject, -1, $count);
        if ($out === null || $count !== 1) {
            throw new \LogicException('php-docx output changed: ' . $what . ' not found');
        }

        return $out;
    }

    private static function attr(string $v): string
    {
        return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** @return array<string, string> entries in archive order */
    private static function read(string $bytes): array
    {
        $file = tempnam(sys_get_temp_dir(), 'papercut-docx');
        if ($file === false) {
            throw new \RuntimeException('Could not create a temporary file');
        }
        try {
            file_put_contents($file, $bytes);
            $zip = new \ZipArchive();
            if ($zip->open($file, \ZipArchive::RDONLY) !== true) {
                throw new \RuntimeException('php-docx wrote an unreadable archive');
            }
            $parts = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                $data = $zip->getFromIndex($i);
                if ($name === false || $data === false) {
                    throw new \RuntimeException('php-docx wrote an unreadable archive entry');
                }
                $parts[$name] = $data;
            }
            $zip->close();

            return $parts;
        } finally {
            @unlink($file);
        }
    }
}
