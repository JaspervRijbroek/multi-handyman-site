<?php

declare(strict_types=1);

namespace App\Email;

use Symfony\Component\Mime\HtmlToTextConverter\HtmlToTextConverterInterface;

/**
 * Makes the plain-text part of our HTML emails (config/packages/twig.yaml): readable
 * lines instead of the bare strip_tags() Symfony does by default, with link targets kept.
 */
final class HtmlToText implements HtmlToTextConverterInterface
{
    public function convert(string $html, string $charset): string
    {
        // Also decorations marked aria-hidden, like the icons in callouts
        $html = preg_replace(['{<(head|style)\b.*?</\1>}is', '{<(td|span)\b[^>]*aria-hidden="true"[^>]*>.*?</\1>}is'], '', $html);
        // Links and buttons: "Pay now: https://..." (just the address when that is the text; logos are left out)
        $html = preg_replace_callback('{<a\b[^>]*\bhref="([^"]+)"[^>]*>(.*?)</a>}is', static function (array $m): string {
            $url = html_entity_decode($m[1], \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
            $text = trim(strip_tags($m[2]));

            if ('' === $text) {
                return '';
            }

            $text = html_entity_decode($text, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
            if (str_contains($url, $text)) {
                // An email address or phone number on its own is clear enough
                return preg_match('{^(mailto|tel):}', $url) ? $text : $url;
            }

            return $text.': '.$url;
        }, $html);
        $html = preg_replace(['{<br\s*/?>}i', '{</(p|h1|h2|tr|div|strong)>}i', '{</td>}i'], ["\n", "\n\n", '  '], $html);

        $text = html_entity_decode(strip_tags($html), \ENT_QUOTES | \ENT_HTML5, $charset);
        $lines = array_map(static fn (string $line) => trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $line)), explode("\n", $text));

        return trim(preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines)))."\n";
    }
}
