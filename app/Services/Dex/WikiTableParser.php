<?php

namespace App\Services\Dex;

/**
 * Reads MediaWiki tables ({| ... |}) into rows keyed by their (normalized) column headers,
 * so each wiki's year lists can be read the same way whatever their column order or styling.
 */
class WikiTableParser
{
    /**
     * @return list<array<string, array{text: string, lines: list<string>, templates: list<array{name: string, args: list<string>}>, file: ?string}>>
     */
    public function rows(string $wikitext): array
    {
        $rows = [];

        preg_match_all('/^\{\|.*?^\|\}/ms', $wikitext, $tables);

        foreach ($tables[0] as $table) {
            $headers = [];
            $cells = null;

            // Not /\R/: without UTF-8 mode it also matches byte 0x85, which sits inside letters like
            // Cyrillic "х" in file names and cut them in half.
            foreach (preg_split('/\r\n|\r|\n/', $table) as $line) {
                if (str_starts_with($line, '{|') || str_starts_with($line, '|}') || str_starts_with($line, '|+')) {
                    continue;
                }

                if (str_starts_with($line, '|-')) {
                    if ($cells !== null) {
                        $rows[] = $this->keyed($headers, $cells);
                    }
                    $cells = [];

                    continue;
                }

                if (str_starts_with($line, '!')) {
                    foreach (preg_split('/!!/', substr($line, 1)) as $header) {
                        $headers[] = $this->headerKey($header);
                    }

                    continue;
                }

                if (str_starts_with($line, '|')) {
                    $cells ??= [];
                    $cells[] = substr($line, 1);
                } elseif ($cells) {
                    // Continuation of a multi-line cell.
                    $cells[array_key_last($cells)] .= "\n".$line;
                }
            }

            if ($cells) {
                $rows[] = $this->keyed($headers, $cells);
            }
        }

        return array_values(array_filter($rows));
    }

    /**
     * Plain text of a cell plus what's useful inside the markup: templates (flags) and the image file.
     *
     * @return array{text: string, lines: list<string>, templates: list<array{name: string, args: list<string>}>, file: ?string}
     */
    public function clean(string $raw): array
    {
        $templates = [];
        $file = null;

        $text = preg_replace('/<!--.*?-->|<ref[^>]*\/>|<ref[^>]*>.*?<\/ref>/is', '', $raw);
        $text = $this->withoutAttributes($text);

        if (preg_match('/\[\[(?:File|Image):([^|\]]+)/i', $text, $match)) {
            $file = trim(str_replace('_', ' ', $match[1]));
        }

        $text = preg_replace('/\[\[(?:File|Image):[^\]]*\]\]/i', '', $text);
        $text = preg_replace_callback('/\{\{([^{}]*)\}\}/', function (array $match) use (&$templates) {
            $parts = array_map('trim', explode('|', $match[1]));
            $templates[] = ['name' => strtoupper(array_shift($parts)), 'args' => $parts];

            return '';
        }, $text);
        $text = preg_replace('/\[\[[^|\]]*\|([^\]]*)\]\]/', '$1', $text);
        $text = preg_replace('/\[\[([^\]]*)\]\]/', '$1', $text);
        $text = preg_replace('/\[https?:\/\/\S+\s*([^\]]*)\]/', '$1', $text);
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
        // Not strip_tags(): wiki markup has broken tags like <font color=#0C0"> that swallow the text after them.
        $text = preg_replace('/<\/?[a-z][^>]*>/i', '', $text);
        $text = html_entity_decode(str_replace(["'''", "''"], '', $text), ENT_QUOTES | ENT_HTML5);

        $lines = array_values(array_filter(
            array_map(fn (string $line) => trim(preg_replace('/\s+/u', ' ', $line)), explode("\n", $text)),
            fn (string $line) => $line !== '',
        ));

        return ['text' => $lines[0] ?? '', 'lines' => $lines, 'templates' => $templates, 'file' => $file];
    }

    /**
     * "Col. #" → "col#", "Model Name" → "modelname", "Sub-series" → "subseries".
     */
    private function headerKey(string $raw): string
    {
        $text = $this->clean($raw)['text'];

        return preg_replace('/[^a-z0-9#]/', '', strtolower($text));
    }

    /**
     * @param  list<string>  $headers
     * @param  list<string>  $cells
     * @return array<string, array{text: string, lines: list<string>, templates: list<array{name: string, args: list<string>}>, file: ?string}>
     */
    private function keyed(array $headers, array $cells): array
    {
        $row = [];

        foreach ($cells as $i => $cell) {
            if (isset($headers[$i]) && $headers[$i] !== '') {
                $row[$headers[$i]] = $this->clean($cell);
            }
        }

        return $row;
    }

    /**
     * Drops a leading `bgcolor="…" |` / `style=… |` from a cell (the first `|` outside links and templates).
     */
    private function withoutAttributes(string $cell): string
    {
        $depth = 0;
        $length = strlen($cell);

        for ($i = 0; $i < $length; $i++) {
            $pair = substr($cell, $i, 2);

            if ($pair === '[[' || $pair === '{{') {
                $depth++;
                $i++;
            } elseif (($pair === ']]' || $pair === '}}') && $depth > 0) {
                $depth--;
                $i++;
            } elseif ($cell[$i] === '|' && $depth === 0) {
                $prefix = substr($cell, 0, $i);

                return str_contains($prefix, '=') ? substr($cell, $i + 1) : $cell;
            }
        }

        return $cell;
    }
}
