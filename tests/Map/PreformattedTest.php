<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests\Map;

use Papercut\{Document, Page};
use Papercut\Docx\DocxExporter;
use Papercut\Docx\Tests\Support\{DocxInspector, Styles};
use Papercut\Export\ExportOptions;
use Papercut\Style\{Style, Theme};
use Papercut\Value\Color;
use Papercut\Markup\Markup;
use PHPUnit\Framework\TestCase;

final class PreformattedTest extends TestCase
{
    use Styles;

    private function docx(string $html): DocxInspector
    {
        return self::export(Markup::html($html));
    }

    public function test_docx_pre_preserves_spaces(): void
    {
        $doc = $this->docx("<pre>a  b\n  c</pre>");
        self::assertStringContainsString('<w:t xml:space="preserve">a  b</w:t>', $doc->part('word/document.xml'));
        self::assertStringContainsString('<w:t xml:space="preserve">  c</w:t>', $doc->part('word/document.xml'));
        self::assertSame(1, $doc->count('//w:r/w:br'));
    }

    public function test_docx_pre_tabs_are_w_tab(): void
    {
        $doc = $this->docx("<pre>a\tb</pre>");
        self::assertSame(1, $doc->count('//w:r/w:tab'));
        foreach ($doc->values('//w:t') as $text) {
            self::assertStringNotContainsString("\t", $text);
        }
    }

    public function test_docx_pre_uses_courier_new(): void
    {
        $doc = $this->docx('<pre>a  b</pre><p>plain <code>x</code></p>');
        self::assertSame('Courier New', $doc->value('//w:r[w:t="a  b"]/w:rPr/w:rFonts/@w:ascii'));
        self::assertSame('Courier New', $doc->value('//w:r[w:t="x"]/w:rPr/w:rFonts/@w:ascii'));
        self::assertSame('Inter', $doc->value('//w:r[w:t="plain "]/w:rPr/w:rFonts/@w:ascii'));
    }

    public function test_docx_pre_line_breaks_carry_the_mono_run_properties(): void
    {
        $doc = $this->docx("<pre>a\n\nb</pre>");
        self::assertSame(2, $doc->count('//w:r[w:br]'));
        self::assertSame(2, $doc->count('//w:r[w:br]/w:rPr/w:rFonts[@w:ascii="Courier New"]'));
    }

    public function test_a_theme_overriding_code_reaches_the_inline_code_run(): void
    {
        $theme = Theme::default()->withStyle('code', Style::make(fontFamily: 'monospace', color: Color::parse('#ff0000'), background: Color::parse('#00ff00')));
        $doc = DocxInspector::of(
            Document::make(Page::a4()->body(Markup::html('<p>plain <code>x</code></p>')))
                ->export(new DocxExporter(), new ExportOptions(theme: $theme))->bytes(),
        );

        self::assertSame('FF0000', strtoupper((string) $doc->value('//w:r[w:t="x"]/w:rPr/w:color/@w:val')));
        self::assertSame('00FF00', strtoupper((string) $doc->value('//w:r[w:t="x"]/w:rPr/w:shd/@w:fill')));
        self::assertSame(0, $doc->count('//w:r[w:t="plain "]/w:rPr/w:shd'));
    }

    public function test_a_pre_in_a_justified_container_is_not_justified(): void
    {
        $node = \Papercut\View::make(Markup::html("<pre>a b\nc d</pre><p>plain text</p>"))
            ->apply(Style::make(textAlign: \Papercut\Style\TextAlign::Justify));
        $doc = self::export($node);

        self::assertSame(1, $doc->count('//w:p[w:r[w:t="plain text"]]/w:pPr/w:jc[@w:val="both"]'));
        self::assertSame(0, $doc->count('//w:p[w:r[w:br]]/w:pPr/w:jc[@w:val="both"]'));
    }
}
