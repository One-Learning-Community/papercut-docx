<?php

declare(strict_types=1);

namespace Papercut\Docx\Tests\Map;

use Papercut\Decorator\CodeBlock;
use Papercut\Docx\Tests\Support\Styles;
use PHPUnit\Framework\TestCase;

final class CodeBlockDocxTest extends TestCase
{
    use Styles;

    private const string P = '/w:document/w:body/w:p';

    private static function block(): CodeBlock
    {
        return CodeBlock::make("<?php\n// note\n\$a = 1;\n\t\$b  =  2;")->language('php');
    }

    public function test_code_paragraphs_are_shaded(): void
    {
        $d = self::export(self::block());
        self::assertContains('F6F8FA', array_map('strtoupper', $d->values(self::P . '/w:pPr/w:shd/@w:fill')));
    }

    public function test_comment_run_is_coloured_and_italic(): void
    {
        $d = self::export(self::block());
        self::assertNotSame([], $d->values(self::P . '/w:r[translate(w:rPr/w:color/@w:val, "ABCDEF", "abcdef")="8b949e" and w:rPr/w:i]/w:t'));
    }

    public function test_label_is_its_own_right_aligned_paragraph(): void
    {
        $d = self::export(self::block());
        self::assertNotSame([], $d->values(self::P . '[w:pPr/w:jc/@w:val="right"][w:r/w:t="PHP"]/w:r/w:t'));
    }

    public function test_spaces_and_tabs_survive(): void
    {
        $d = self::export(self::block());
        self::assertNotSame([], $d->values('//w:t[@xml:space="preserve"]'));
        self::assertNotSame([], $d->values('//w:tab'));
    }

    public function test_no_table_for_the_default_look(): void
    {
        $d = self::export(self::block());
        self::assertSame([], $d->values('/w:document/w:body/w:tbl'));
    }

    public function test_border_space_carries_the_padding(): void
    {
        $d = self::export(self::block());
        self::assertSame(['8'], array_unique($d->values(self::P . '/w:pPr/w:pBdr/w:left/@w:space')));
        self::assertSame(['6'], array_unique($d->values(self::P . '/w:pPr/w:pBdr/w:top/@w:space')));
        self::assertSame([], $d->values('//w:pBdr/*[contains(@w:color, "papercut")]'));
    }

    public function test_adjacent_blocks_are_separated_so_word_draws_two_boxes(): void
    {
        $d = self::export(\Papercut\Column::make(self::block(), self::block()));
        // the first paragraph of the second block follows a paragraph without borders, which follows a bordered one
        $second = $d->values('/w:document/w:body/w:p[w:pPr/w:pBdr][preceding-sibling::*[1][self::w:p and not(w:pPr/w:pBdr)]][preceding-sibling::*[2][self::w:p/w:pPr/w:pBdr]]/w:pPr/w:pBdr/w:top/@w:val');
        self::assertCount(1, $second);
    }

    public function test_keep_together_keeps_every_paragraph_on_one_page(): void
    {
        $d = self::export(self::block()->keepTogether());
        $paras = $d->values(self::P . '[w:pPr/w:pBdr]/w:pPr/w:keepLines');
        $all = $d->values(self::P . '[w:pPr/w:pBdr]');
        self::assertGreaterThan(1, count($all));
        self::assertCount(count($all), $paras, 'keep lines together on every paragraph');
        // keep with next on all but the last
        self::assertCount(count($all) - 1, $d->values(self::P . '[w:pPr/w:pBdr]/w:pPr/w:keepNext'));
        self::assertSame([], $d->values(self::P . '[w:pPr/w:pBdr][last()]/w:pPr/w:keepNext'));
    }

    public function test_without_keep_together_nothing_is_kept(): void
    {
        $d = self::export(self::block());
        self::assertSame([], $d->values('//w:keepLines'));
        // only the label stays with its code
        self::assertCount(1, $d->values('//w:keepNext'));
    }

    public function test_keep_together_on_the_strip_look_keeps_the_bar_row_with_the_code_row(): void
    {
        $d = self::export(self::block()->variant('strip')->keepTogether());
        self::assertCount(2, $d->values('/w:document/w:body/w:tbl/w:tr/w:trPr/w:cantSplit'));
        self::assertCount(1, $d->values('/w:document/w:body/w:tbl/w:tr[1]//w:p/w:pPr/w:keepNext'));
        self::assertSame([], $d->values('/w:document/w:body/w:tbl/w:tr[2]//w:p/w:pPr/w:keepNext'));
    }

    public function test_keep_together_on_the_caption_look_keeps_the_label_and_cannot_split_the_box(): void
    {
        $d = self::export(self::block()->variant('caption')->keepTogether());
        self::assertCount(1, $d->values('/w:document/w:body/w:p/w:pPr/w:keepNext'));
        self::assertNotSame([], $d->values('/w:document/w:body/w:tbl/w:tr/w:trPr/w:cantSplit'));
    }

    public function test_text_containing_the_patch_markers_exports(): void
    {
        $d = self::export(\Papercut\Text::make('See papercut-spec and papercut-shd-abcdef and papercut-sp8-aabbcc for details'));
        self::assertStringContainsString('papercut-spec', implode('', $d->values('//w:t')));
    }

    public function test_a_code_block_first_in_a_list_item_gets_its_own_marker_paragraph(): void
    {
        $d = self::export(\Papercut\ListNode::ordered(\Papercut\ListItem::make(self::block())));
        // The marker paragraph is the only numbered one and has no border or shading.
        self::assertCount(1, $d->values(self::P . '/w:pPr/w:numPr'));
        self::assertNotSame([], $d->values(self::P . '[w:pPr/w:numPr][not(w:pPr/w:pBdr)][not(w:pPr/w:shd)]'));
        self::assertSame([], $d->values(self::P . '[w:pPr/w:numPr][w:pPr/w:pBdr]'));
        self::assertNotSame([], $d->values(self::P . '[w:pPr/w:pBdr][not(w:pPr/w:numPr)]'));
    }
}
