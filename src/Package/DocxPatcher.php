<?php

declare(strict_types=1);

namespace Papercut\Docx\Package;

/**
 * Fixes php-docx 1.17's output where it can't express what Papercut needs: `left`/`right` alignment
 * (Google Docs ignores `start`/`end`), run shading in any colour, any page size, the theme's default font, and the
 * document language.
 * It edits the exact markup php-docx writes and throws when that markup changes, so an upgrade fails loudly.
 */
final class DocxPatcher
{
    private const string DOC_DEFAULTS = '<w:rPrDefault><w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:sz w:val="22"/><w:szCs w:val="22"/></w:rPr></w:rPrDefault>';

    /** Marks a run's highlight as run shading in a colour of its own; see shading(). */
    private const string SHADING = 'papercut-shd-';

    /** A highlight value patch() turns into run shading in $hex (six lower-case hex digits). */
    public static function shading(string $hex): string
    {
        return self::SHADING . $hex;
    }

    public static function patch(string $bytes, PatchSpec $spec): string
    {
        $parts = self::read($bytes);
        foreach ($parts as $name => $xml) {
            if (preg_match('~^word/(document|styles|header\d+|footer\d+)\.xml$~', $name) === 1) {
                $xml = str_replace(['<w:jc w:val="start"/>', '<w:jc w:val="end"/>'], ['<w:jc w:val="left"/>', '<w:jc w:val="right"/>'], $xml);
                $xml = (string) preg_replace('~<w:highlight w:val="' . self::SHADING . '([0-9a-f]{6})"/>~', '<w:shd w:val="clear" w:color="auto" w:fill="$1"/>', $xml);
                if (str_contains($xml, self::SHADING)) {
                    throw new \LogicException('php-docx output changed: run highlight in ' . $name . ' not found');
                }
                $parts[$name] = $xml;
            }
        }

        $orient = $spec->pageWidthTwips > $spec->pageHeightTwips ? 'landscape' : 'portrait';
        $parts['word/document.xml'] = self::replaceOnce(
            '~<w:pgSz w:w="\d+" w:h="\d+" w:orient="(?:portrait|landscape)"/>~',
            sprintf('<w:pgSz w:w="%d" w:h="%d" w:orient="%s"/>', $spec->pageWidthTwips, $spec->pageHeightTwips, $orient),
            $parts['word/document.xml'] ?? '',
            'page size in word/document.xml',
        );

        $font = self::attr($spec->fontFamily);
        $lang = $spec->lang === null ? '' : '<w:lang w:val="' . self::attr($spec->lang) . '"/>';
        $parts['word/styles.xml'] = self::replaceOnce(
            '~' . preg_quote(self::DOC_DEFAULTS, '~') . '~',
            '<w:rPrDefault><w:rPr><w:rFonts w:ascii="' . $font . '" w:hAnsi="' . $font . '" w:cs="' . $font . '"/>'
                . '<w:sz w:val="' . $spec->fontSizeHalfPoints . '"/><w:szCs w:val="' . $spec->fontSizeHalfPoints . '"/>' . $lang . '</w:rPr></w:rPrDefault>',
            $parts['word/styles.xml'] ?? '',
            'default run properties in word/styles.xml',
        );

        return DeterministicZip::write($parts, $spec->time);
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
