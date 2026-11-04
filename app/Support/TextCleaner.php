<?php

namespace App\Support;

class TextCleaner
{
    /**
     * Cleans Telegram-formatted supplier text:
     *  - removes custom emoji placeholders like {{ce:6028551194861899805|??}}
     *  - converts <br> / block tags to new lines and strips remaining HTML
     *  - normalises whitespace
     */
    public static function clean(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $text = preg_replace('/\{\{ce:\d+\|[^}]*\}\}/u', '', $text) ?? $text;
        $text = preg_replace('/\{(?:pe|ce):\d+(?:\|[^}]*)?\}/u', '', $text) ?? $text;
        $text = preg_replace('/<\s*br\s*\/?>/i', "\n", $text) ?? $text;
        $text = preg_replace('/<\/(p|div|blockquote|li)>/i', "\n", $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r\n", "\r", '\\n'], "\n", $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
        $text = trim($text, " \n*_");

        return $text === '' ? null : $text;
    }
}
