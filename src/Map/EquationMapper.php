<?php

declare(strict_types=1);

namespace Papercut\Docx\Map;

use Dskripchenko\PhpDocx\Element\{Paragraph, Run};
use Dskripchenko\PhpDocx\Style\{ParagraphStyle, RunStyle};
use Papercut\Docx\Package\DocxPatcher;
use Papercut\Equation;
use Papercut\Math\Render\{OmmlWriter, RunFormat};
use Papercut\Style\{ComputedStyle, TextAlign};

/**
 * A block equation as a native Word equation (OMML). The paragraph holds a marker run that DocxPatcher swaps for the
 * `m:oMathPara`; the OMML itself is kept by id for PatchSpec::withMath().
 *
 * @internal Part of Papercut's engine, not its public API. It may change in any release.
 */
final class EquationMapper
{
    /** @var array<int, string> */
    private array $maths = [];

    /** @return array<int, string> OMML by equation id */
    public function maths(): array
    {
        return $this->maths;
    }

    /** Whether the block is an equation's paragraph (see paragraph()), which must hold nothing but its equation. */
    public static function isMarker(\Dskripchenko\PhpDocx\Element\BlockElement $block): bool
    {
        $run = $block instanceof Paragraph ? ($block->children[0] ?? null) : null;

        return $run instanceof Run && str_starts_with($run->text, DocxPatcher::MATH);
    }

    /** @var array<int, true> ids of the equations that sit among runs */
    private array $inline = [];

    /** @return array<int, true> ids of the inline equations in maths() */
    public function inlineIds(): array
    {
        return $this->inline;
    }

    /** An inline equation: a marker run that DocxPatcher swaps for an `m:oMath` between the paragraph's runs. */
    public function run(\Papercut\Math\Node\Node $math, ComputedStyle $s, ?string $shadingHex = null): Run
    {
        $id = count($this->maths) + 1;
        $this->maths[$id] = OmmlWriter::oMath($math, new RunFormat(Units::hex($s->color), (int) round($s->fontSize * 2), $shadingHex));
        $this->inline[$id] = true;

        return new Run(DocxPatcher::MATH . $id);
    }

    public function paragraph(Equation $n, ComputedStyle $s): Paragraph
    {
        $id = count($this->maths) + 1;
        $jc = match ($s->textAlign) {
            TextAlign::Center => 'center',
            TextAlign::Right => 'right',
            default => 'left',
        };
        $format = new RunFormat(Units::hex($s->color), (int) round($s->fontSize * 2));
        $this->maths[$id] = OmmlWriter::oMathPara($n->math(), $jc, $format);

        // Automatic line height: a fraction or radical is taller than a line of text, and an exact height would clip it.
        return new Paragraph([new Run(DocxPatcher::MATH . $id)], new ParagraphStyle(lineSpacingTwips: 240, lineSpacingRule: 'auto'));
    }
}
