<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests\Support;

/** Opens a .docx in memory and answers XPath questions about its parts. */
final class DocxInspector
{
    public const string W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    public const string R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /** @param array<string, string> $parts */
    private function __construct(private readonly array $parts)
    {
    }

    public static function of(string $bytes): self
    {
        $file = tempnam(sys_get_temp_dir(), 'docx');
        \assert(is_string($file));
        file_put_contents($file, $bytes);
        $zip = new \ZipArchive();
        if ($zip->open($file) !== true) {
            unlink($file);
            throw new \RuntimeException('Not a ZIP file');
        }
        $parts = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $parts[$name] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        unlink($file);

        return new self($parts);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->parts);
    }

    public function has(string $name): bool
    {
        return isset($this->parts[$name]);
    }

    public function part(string $name): string
    {
        return $this->parts[$name] ?? throw new \OutOfBoundsException("No part $name");
    }

    public function xpath(string $part = 'word/document.xml'): \DOMXPath
    {
        $dom = new \DOMDocument();
        if (!$dom->loadXML($this->part($part))) {
            throw new \RuntimeException("$part is not well-formed");
        }
        $xp = new \DOMXPath($dom);
        $xp->registerNamespace('w', self::W);
        $xp->registerNamespace('r', self::R);
        $xp->registerNamespace('wp', 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing');
        $xp->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
        $xp->registerNamespace('dc', 'http://purl.org/dc/elements/1.1/');
        $xp->registerNamespace('dcterms', 'http://purl.org/dc/terms/');
        $xp->registerNamespace('cp', 'http://schemas.openxmlformats.org/package/2006/metadata/core-properties');

        return $xp;
    }

    /** One attribute or text value, or null when the query finds nothing. */
    public function value(string $query, string $part = 'word/document.xml'): ?string
    {
        $nodes = $this->xpath($part)->query($query);
        $node = $nodes === false ? null : $nodes->item(0);

        return $node?->nodeValue;
    }

    /** @return list<string> */
    public function values(string $query, string $part = 'word/document.xml'): array
    {
        $out = [];
        foreach ($this->xpath($part)->query($query) ?: [] as $node) {
            $out[] = (string) $node->nodeValue;
        }

        return $out;
    }

    public function count(string $query, string $part = 'word/document.xml'): int
    {
        $nodes = $this->xpath($part)->query($query);

        return $nodes === false ? 0 : $nodes->length;
    }

    /** Every w:t of the body in document order, with a space after each paragraph. */
    public function bodyText(string $part = 'word/document.xml'): string
    {
        $out = '';
        foreach ($this->xpath($part)->query('//w:t | //w:p | //w:tab') ?: [] as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $out .= match ($node->localName) {
                't' => $node->textContent,
                'tab' => "\t",
                default => ' ',
            };
        }

        return $out;
    }
}
