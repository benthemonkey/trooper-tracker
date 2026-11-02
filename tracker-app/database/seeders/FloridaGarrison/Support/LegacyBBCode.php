<?php

declare(strict_types=1);

namespace Database\Seeders\FloridaGarrison\Support;

/**
 * Converts TT1.0 event comments to the Markdown the event page renders. TT1.0 showed them
 * as BBCode with nl2br(), so every newline was a line break.
 */
final class LegacyBBCode
{
    public static function toMarkdown(?string $text): string
    {
        // Normalize newlines
        $text = str_replace(["\r\n", "\r"], "\n", $text ?? '');

        // Protect code blocks first (so inner tags aren't transformed)
        // [code]...[/code] -> triple backticks (preserve content verbatim)
        $text = preg_replace_callback('/\[code\](.*?)\[\/code\]/is', function ($m)
        {
            $code = trim($m[1], "\n");

            return "\n```"."\n".$code."\n"."```\n";
        }, $text);

        // Quotes
        // [quote]...[/quote] -> blockquote
        // [quote=Name]...[/quote] -> blockquote with header
        $text = preg_replace_callback('/\[quote(?:=([^\]]+))?\](.*?)\[\/quote\]/is', function ($m)
        {
            $who = isset($m[1]) ? trim($m[1]) : null;
            $body = trim($m[2]);
            $quote = '';
            if ($who)
            {
                $quote .= '> **'.$who.':**'."\n";
            }
            foreach (explode("\n", $body) as $line)
            {
                $quote .= '> '.$line."\n";
            }

            return "\n".rtrim($quote)."\n";
        }, $text);

        // Images
        // [img]url[/img] -> ![](url)
        $text = preg_replace('/\[img\]\s*(.*?)\s*\[\/img\]/is', '![]($1)', $text);

        // Links
        // [url]http://example[/url] -> <http://example>
        // [url=http://example]text[/url] -> [text](http://example)
        $text = preg_replace('/\[url\]\s*(.*?)\s*\[\/url\]/is', '<$1>', $text);
        $text = preg_replace('/\[url=(.*?)\](.*?)\[\/url\]/is', '[$2]($1)', $text);

        // Basic inline styles
        $replacements = [
            // [b]bold[/b] -> **bold**
            '/\[b\](.*?)\[\/b\]/is' => '**$1**',
            // [i]italic[/i] -> _italic_
            '/\[i\](.*?)\[\/i\]/is' => '_$1_',
            // [u]underline[/u] -> use <u> for lack of native MD underline
            '/\[u\](.*?)\[\/u\]/is' => '<u>$1</u>',
            // [s]strike[/s] -> ~~strike~~
            '/\[s\](.*?)\[\/s\]/is' => '~~$1~~',
            // [spoiler]...[/spoiler] -> collapsible via HTML details
            '/\[spoiler\](.*?)\[\/spoiler\]/is' => "<details><summary>spoiler</summary>\n$1\n</details>",
        ];
        foreach ($replacements as $pattern => $replace)
        {
            $text = preg_replace($pattern, $replace, $text);
        }

        // Size and color (map to emphasis or inline HTML; Markdown has no native color/size)
        $text = preg_replace('/\[size=(\d+)\](.*?)\[\/size\]/is', '<span style="font-size:$1px">$2</span>', $text);
        $text = preg_replace('/\[color=([#a-zA-Z0-9]+)\](.*?)\[\/color\]/is', '<span style="color:$1">$2</span>', $text);

        // Lists
        // Unordered: [list] [*] item ... [/list]
        $text = preg_replace_callback('/\[list\](.*?)\[\/list\]/is', function ($m)
        {
            $items = preg_split('/\s*\[\*\]\s*/', trim($m[1]));
            $items = array_filter($items, fn ($i) => $i !== '');
            if (!$items)
            {
                return '';
            }

            return "\n".implode("\n", array_map(fn ($i) => '- '.trim($i), $items))."\n";
        }, $text);

        // Ordered: [list=1] or [list=decimal|alpha] -> numbered list
        $text = preg_replace_callback('/\[list=([^\]]+)\](.*?)\[\/list\]/is', function ($m)
        {
            $items = preg_split('/\s*\[\*\]\s*/', trim($m[2]));
            $items = array_filter($items, fn ($i) => $i !== '');
            if (!$items)
            {
                return '';
            }

            // Markdown doesn’t support starting index; keep simple 1..n
            return "\n".implode("\n", array_map(fn ($i) => '1. '.trim($i), $items))."\n";
        }, $text);

        // Tables
        // [table][tr][th]..[/th][/tr][tr][td]..[/td][/tr][/table] -> pipe table
        $text = preg_replace_callback('/\[table(?:=[^\]]*)?\](.*?)\[\/table\]/is', fn ($m) => self::table($m[1]), $text);

        // Linebreaks: [br] -> newline
        $text = preg_replace('/\[br\]/i', "\n", $text);

        // Remove residual BBCode tags we don’t handle (light-touch)
        $text = preg_replace('/\[(\/?)(left|right|center)\]/i', '', $text);

        $text = self::hardLineBreaks($text);

        // Trim excess blank lines
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }

    /**
     * A pipe table needs a header row: the first row is it, whether TT1.0 marked it [th] or not.
     */
    private static function table(string $body): string
    {
        preg_match_all('/\[tr(?:=[^\]]*)?\](.*?)\[\/tr\]/is', $body, $row_matches);

        $rows = [];
        foreach ($row_matches[1] as $row)
        {
            preg_match_all('/\[(td|th)(?:=[^\]]*)?\](.*?)\[\/\1\]/is', $row, $cell_matches);

            // a cell is one line; a pipe would end it
            $rows[] = array_map(
                fn ($cell) => str_replace(['|', "\n"], ['\|', '<br>'], trim($cell)),
                $cell_matches[2]
            );
        }

        $rows = array_values(array_filter($rows));
        if (!$rows)
        {
            return '';
        }

        $width = max(array_map('count', $rows));
        $line = fn (array $cells) => '| '.implode(' | ', array_pad($cells, $width, '')).' |';

        $lines = [$line($rows[0]), $line(array_fill(0, $width, '---'))];
        foreach (array_slice($rows, 1) as $row)
        {
            $lines[] = $line($row);
        }

        // blank lines around it, or Markdown reads it as part of the paragraph above
        return "\n\n".implode("\n", $lines)."\n\n";
    }

    /**
     * End each line of running text with a hard break ("\"), as TT1.0's nl2br() showed it.
     * Lines that are Markdown blocks (lists, quotes, tables, headings, code) keep their own breaks.
     */
    private static function hardLineBreaks(string $text): string
    {
        $lines = explode("\n", $text);
        $is_block = fn (string $line) => preg_match('/^\s*(?:[-*+]\s|\d+[.)]\s|>|\||#{1,6}\s|```)/', $line) === 1;
        $in_code = false;

        foreach ($lines as $i => $line)
        {
            if (str_starts_with(ltrim($line), '```'))
            {
                $in_code = !$in_code;

                continue;
            }

            $next = $lines[$i + 1] ?? '';

            if (!$in_code && trim($line) !== '' && trim($next) !== '' && !$is_block($line) && !$is_block($next))
            {
                $lines[$i] = rtrim($line).'\\';
            }
        }

        return implode("\n", $lines);
    }
}
