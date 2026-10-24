<?php declare(strict_types=1);

namespace TaxEbay\Core\Shipping;

/**
 * The text lines printed on a Deutsche Post / DHL label PDF — enough to read the recipient and match the label to a
 * parcel. Reads what that PDF uses: Flate streams, Type0 fonts with a ToUnicode map, TJ/Tj strings. Not a general
 * PDF reader; anything else yields fewer lines, never an exception.
 */
final class LabelText
{
    /** @return list<string> */
    public static function of(string $pdf): array
    {
        $objects = [];

        if (preg_match_all('/(?:^|\s)(\d+)\s+0\s+obj\b(.*?)\bendobj/s', $pdf, $found, PREG_SET_ORDER) > 0) {
            foreach ($found as [, $id, $body]) {
                $objects[(int) $id] = $body;
            }
        }

        $maps = [];

        foreach ($objects as $body) {
            if (preg_match('/\/Font\s*(?:<<(.*?)>>|(\d+)\s+0\s+R)/s', $body, $fonts) !== 1) {
                continue;
            }

            preg_match_all('/\/([\w.+-]+)\s+(\d+)\s+0\s+R/', ($fonts[2] ?? '') !== '' ? ($objects[(int) $fonts[2]] ?? '') : $fonts[1], $refs, PREG_SET_ORDER);

            foreach ($refs as [, $name, $fontId]) {
                $font = $objects[(int) $fontId] ?? '';

                if (preg_match('/\/ToUnicode\s+(\d+)\s+0\s+R/', $font, $unicode) === 1) {
                    $maps[$name] = self::cmap(self::stream($objects[(int) $unicode[1]] ?? ''));
                }
            }
        }

        $lines = [];

        foreach ($objects as $body) {
            $content = self::stream($body);

            if (str_contains($content, 'BT')) {
                array_push($lines, ...self::text($content, $maps));
            }
        }

        return array_values(array_filter(array_map('trim', $lines), static fn (string $line): bool => $line !== ''));
    }

    private static function stream(string $object): string
    {
        if (preg_match('/stream\r?\n(.*?)\r?\nendstream/s', $object, $raw) !== 1) {
            return '';
        }

        if (! str_contains($object, '/FlateDecode')) {
            return $raw[1];
        }

        $plain = @gzuncompress($raw[1]);

        return $plain === false ? '' : $plain;
    }

    /** @return array<int, string> glyph code → text */
    private static function cmap(string $cmap): array
    {
        $map = [];
        preg_match_all('/beginbfchar(.*?)endbfchar/s', $cmap, $chars);

        foreach ($chars[1] as $block) {
            preg_match_all('/<([0-9a-fA-F]+)>\s*<([0-9a-fA-F]+)>/', $block, $pairs, PREG_SET_ORDER);

            foreach ($pairs as [, $code, $unicode]) {
                $map[(int) hexdec($code)] = self::utf16($unicode);
            }
        }

        preg_match_all('/beginbfrange(.*?)endbfrange/s', $cmap, $ranges);

        foreach ($ranges[1] as $block) {
            preg_match_all('/<([0-9a-fA-F]+)>\s*<([0-9a-fA-F]+)>\s*<([0-9a-fA-F]+)>/', $block, $triples, PREG_SET_ORDER);

            foreach ($triples as [, $from, $to, $start]) {
                for ($code = (int) hexdec($from), $unicode = (int) hexdec($start); $code <= (int) hexdec($to) && $code - (int) hexdec($from) < 65536; $code++, $unicode++) {
                    $map[$code] = mb_chr($unicode, 'UTF-8') ?: '';
                }
            }
        }

        return $map;
    }

    private static function utf16(string $hex): string
    {
        $text = @mb_convert_encoding((string) hex2bin(strlen($hex) % 2 === 0 ? $hex : '0' . $hex), 'UTF-8', 'UTF-16BE');

        return $text === "\u{FFFF}" ? '' : (string) $text;
    }

    /**
     * @param array<string, array<int, string>> $maps
     * @return list<string>
     */
    private static function text(string $content, array $maps): array
    {
        $lines = [''];
        $font = [];
        preg_match_all('/\/([\w.+-]+)\s+[\d.]+\s+Tf|\[(.*?)\]\s*TJ|<([0-9a-fA-F]*)>\s*Tj|\((.*?)(?<!\\\\)\)\s*Tj|\b(Td|TD|Tm|T\*|ET)\b/s', $content, $tokens, PREG_SET_ORDER);

        foreach ($tokens as $token) {
            if (($token[1] ?? '') !== '') {
                $font = $maps[$token[1]] ?? [];
            } elseif (($token[5] ?? '') !== '') {
                $lines[] = '';
            } else {
                $strings = ($token[2] ?? '') !== '' ? $token[2] : (($token[3] ?? '') !== '' ? '<' . $token[3] . '>' : '(' . ($token[4] ?? '') . ')');
                preg_match_all('/<([0-9a-fA-F]*)>|\((.*?)(?<!\\\\)\)/s', $strings, $parts, PREG_SET_ORDER);

                foreach ($parts as $part) {
                    $lines[count($lines) - 1] .= ($part[1] ?? '') !== '' ? self::glyphs($part[1], $font) : stripslashes($part[2] ?? '');
                }
            }
        }

        return $lines;
    }

    /** @param array<int, string> $font */
    private static function glyphs(string $hex, array $font): string
    {
        $text = '';

        foreach (str_split($hex, 4) as $code) {
            $text .= $font[(int) hexdec($code)] ?? '';
        }

        return $text;
    }
}
