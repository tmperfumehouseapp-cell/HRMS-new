<?php
/** wa_space.php — SPACING of message texts (owner 24 Nov 2026: "keep a little spacing between the lines, it looks better"): one blank line between the thoughts of a template body.
 *  Only whitespace changes; the words, the {{variables}} and their order never change. PHP 7.4 safe. */
if (!function_exists('wa_space_body')) {
    function wa_space_body(string $body): string
    {
        $b = trim(str_replace("\r\n", "\n", $body)); if ($b === '') return $b;
        $b = preg_replace('/[ \t]+\n/u', "\n", $b); $b = preg_replace('/\n{3,}/u', "\n\n", $b);
        if (strpos($b, "\n\n") !== false) return $b;                                  // already spaced
        if (strpos($b, "\n") !== false) return preg_replace('/\n/u', "\n\n", $b);    // line by line: a blank line between the lines
        // one running paragraph: a new paragraph after every sentence end (. ? ! … or an emoji that closes a sentence), at most 4 paragraphs
        $parts = preg_split('/(?<=[.?!…])\s+(?=[^\s])|(?<=[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}])\s+(?=[A-Za-z{])/u', $b); if (!is_array($parts) || count($parts) < 2) return $b;
        while (count($parts) > 4) { $parts[count($parts) - 2] .= ' ' . $parts[count($parts) - 1]; array_pop($parts); }
        return implode("\n\n", array_map('trim', $parts));
    }
}
