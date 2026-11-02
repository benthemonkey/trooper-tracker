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
        // [b]bold[/b] -> **bold**, [i]italic[/i] -> *italic*, [s]strike[/s] -> ~~strike~~
        $text = self::dropNestedRepeats($text, ['b', 'i', 's']);
        foreach (['b' => '**', 'i' => '*', 's' => '~~'] as $tag => $marker)
        {
            $text = preg_replace_callback(
                '/\['.$tag.'\](.*?)\[\/'.$tag.'\]/is',
                fn ($m) => self::emphasis($m[1], $marker),
                $text
            );
        }

        $replacements = [
            // [u]underline[/u] -> use <u> for lack of native MD underline
            '/\[u\](.*?)\[\/u\]/is' => '<u>$1</u>',
            // [spoiler]...[/spoiler] -> collapsible via HTML details
            '/\[spoiler\](.*?)\[\/spoiler\]/is' => "<details><summary>spoiler</summary>\n$1\n</details>",
        ];
        foreach ($replacements as $pattern => $replace)
        {
            $text = preg_replace($pattern, $replace, $text);
        }

        // Size and color (map to emphasis or inline HTML; Markdown has no native color/size)
        // TT1.0 read a bare number as pixels; a size it couldn't use ("HUGE") leaves the text as is
        $text = preg_replace_callback('/\[size=([^\]]*)\](.*?)\[\/size\]/is', function ($m)
        {
            return preg_match('/^\s*(\d+)\s*(px|pt)?\s*$/i', $m[1], $size)
                ? '<span style="font-size:'.$size[1].(strtolower($size[2] ?? '') ?: 'px').'">'.$m[2].'</span>'
                : $m[2];
        }, $text);
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

        $text = self::stripStrayTags($text);
        $text = self::hardLineBreaks($text);

        // Trim excess blank lines
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }

    /**
     * Bold inside bold is just bold: drop a tag nested in its own kind, which would otherwise
     * become marker runs like "*****" that Markdown can't pair up.
     *
     * @param  list<string>  $tags
     */
    private static function dropNestedRepeats(string $text, array $tags): string
    {
        $depth = array_fill_keys($tags, 0);

        return preg_replace_callback('/\[(\/?)('.implode('|', $tags).')\]/i', function ($m) use (&$depth)
        {
            $tag = strtolower($m[2]);

            if ($m[1] === '')
            {
                return ++$depth[$tag] === 1 ? $m[0] : '';
            }

            // an unmatched closing tag stays; stripStrayTags drops it later
            if ($depth[$tag] === 0)
            {
                return $m[0];
            }

            return --$depth[$tag] === 0 ? $m[0] : '';
        }, $text);
    }

    /**
     * Markdown only emphasizes text the markers hug, so "[b] Note: [/b]" becomes " **Note:** ".
     */
    private static function emphasis(string $inner, string $marker): string
    {
        if (trim($inner) === '')
        {
            return $inner;
        }

        preg_match('/^(\s*)(.*?)(\s*)$/s', $inner, $parts);

        return $parts[1].$marker.$parts[2].$marker.$parts[3];
    }

    /**
     * An unmatched tag (a "[/color]" with no opening) showed raw in TT1.0 too; drop it, but not inside code.
     */
    private static function stripStrayTags(string $text): string
    {
        $parts = preg_split('/(^```\n.*?\n```$)/ms', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        foreach ($parts as $i => $part)
        {
            // odd parts are the code blocks
            if ($i % 2 === 0)
            {
                $parts[$i] = preg_replace('/\[\/?(?:b|i|u|s|color|size|font|url|quote|list|table|tr|td|th|spoiler)(?:=[^\]]*)?\]/i', '', $part);
            }
        }

        return implode('', $parts);
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
