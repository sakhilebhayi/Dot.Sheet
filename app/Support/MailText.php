<?php

namespace App\Support;

/**
 * Text a person typed (a workbook, team or rule name, a comment, a cell value), made safe to put in a
 * Markdown email. Mail templates escape HTML but still read Markdown, so "[Pay here](https://...)" in a
 * workbook name would arrive as a working link from this app's own address. This turns every Markdown
 * mark into plain text, folds the text onto one line, and keeps mail programs from turning a bare
 * "example.com" into a link (a zero-width space after each dot between letters).
 */
final class MailText
{
    public static function inline(mixed $text, int $limit = 300): string
    {
        $text = is_scalar($text) || $text === null ? (string) $text : (string) json_encode($text);
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        $text = mb_strimwidth($text, 0, $limit, '...');

        // Every ASCII punctuation mark CommonMark can treat as syntax, escaped with a backslash. < > & are left
        // alone: the mail template escapes them as HTML first, so they cannot open a tag or an autolink.
        $text = (string) preg_replace('/([\\\\`*_{}\[\]()#+\-.!|~=:"\'])/', '\\\\$1', $text);

        // A dot between letters (a domain), then a zero-width space: mail programs do not auto-link it.
        return (string) preg_replace('/(?<=\p{L})\\\\\.(?=\p{L})/u', "\\.\u{200B}", $text);
    }
}
