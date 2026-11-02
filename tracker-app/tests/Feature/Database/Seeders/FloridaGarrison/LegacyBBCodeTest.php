<?php

declare(strict_types=1);

namespace Tests\Feature\Database\Seeders\FloridaGarrison;

use Database\Seeders\FloridaGarrison\Support\LegacyBBCode;
use Illuminate\Support\Str;
use Tests\TestCase;

class LegacyBBCodeTest extends TestCase
{
    public function test_to_markdown_converts_inline_bbcode(): void
    {
        $result = LegacyBBCode::toMarkdown('[b]Bold[/b] [i]it[/i] [url=https://example.com]site[/url] [color=red]red[/color]');

        $this->assertSame('**Bold** _it_ [site](https://example.com) <span style="color:red">red</span>', $result);
    }

    public function test_to_markdown_handles_empty_comments(): void
    {
        $this->assertSame('', LegacyBBCode::toMarkdown(null));
        $this->assertSame('', LegacyBBCode::toMarkdown(''));
    }

    public function test_to_markdown_keeps_each_line_as_tt1_showed_it(): void
    {
        $result = LegacyBBCode::toMarkdown("Parking is in lot B.\r\nChange in room 104.\n\nBring water.");

        $this->assertSame("Parking is in lot B.\\\nChange in room 104.\n\nBring water.", $result);
        $this->assertSame(
            "<p>Parking is in lot B.<br />\nChange in room 104.</p>\n<p>Bring water.</p>\n",
            Str::markdown($result)
        );
    }

    public function test_to_markdown_leaves_lists_quotes_and_code_unbroken(): void
    {
        $result = LegacyBBCode::toMarkdown("Bring:\n[list][*]Water[*]Snacks[/list]\n[quote]Line one\nLine two[/quote]\n[code]a\nb[/code]");

        $this->assertSame("Bring:\n\n- Water\n- Snacks\n\n> Line one\n> Line two\n\n```\na\nb\n```", $result);
    }

    public function test_to_markdown_converts_a_table_with_a_header_row(): void
    {
        $result = LegacyBBCode::toMarkdown(
            "Schedule:\n[table]\n[tr][th]Time[/th][th]What[/th][/tr]\n[tr][td]9:00 AM[/td][td][b]Arrive[/b][/td][/tr]\n[tr][td]10:00 AM[/td][td]Troop[/td][/tr]\n[/table]\nSee you there."
        );

        $this->assertSame(
            "Schedule:\n\n| Time | What |\n| --- | --- |\n| 9:00 AM | **Arrive** |\n| 10:00 AM | Troop |\n\nSee you there.",
            $result
        );

        $html = Str::markdown($result);
        $this->assertStringContainsString('<th>Time</th>', $html);
        $this->assertStringContainsString('<td><strong>Arrive</strong></td>', $html);
    }

    public function test_to_markdown_uses_the_first_row_as_the_header_without_th_cells(): void
    {
        $result = LegacyBBCode::toMarkdown('[TABLE=collapse][TR][TD]Venue[/TD][TD]Library[/TD][/TR][TR][TD]Parking[/TD][TD]Lot B[/TD][/TR][/TABLE]');

        $this->assertSame("| Venue | Library |\n| --- | --- |\n| Parking | Lot B |", $result);
    }

    public function test_to_markdown_keeps_table_cells_on_one_line(): void
    {
        $result = LegacyBBCode::toMarkdown("[table][tr][td]A | B[/td][td]one\ntwo[/td][td]x[/td][/tr][tr][td]short row[/td][/tr][/table]");

        $this->assertSame("| A \\| B | one<br>two | x |\n| --- | --- | --- |\n| short row |  |  |", $result);
    }
}
