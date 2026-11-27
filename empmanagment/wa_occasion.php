<?php
/**
 * wa_occasion.php — 📅 THE OCCASION CALENDAR (owner, 27 Nov 2026: "Black Friday was caught late and only half; make a calendar, and ask me for the photos on top").
 *
 *  · A list of the year's occasions with the days their messages go (wocc_defs). The dates of the festivals that move every year are marked 'approx': the owner confirms them.
 *  · 21 days before an occasion its two PHOTOS are asked for in "Needed photos" (wa_needphoto.php): one of the offer, one of a gift pack, each with the message it will carry.
 *  · 7 days before, two text messages of the occasion are made and sent to Meta by the order-pack maker (wa_ordpack.php).
 *  · In the occasion's days its messages (names tmph_oc_<key>_…) are used by the Cold funnel and by After-Order like the other selling messages, and the price offer's heading names the occasion.
 *    After the last day they are no longer picked.
 *  · The prices are the shop's own proven combos (160 ML Rs 1,999 · 5 x 50ml Rs 2,750 · 3 x 50ml Rs 2,140 + a free 20ml). A special price for an occasion is the owner's to give.
 *  Functions only (no actions). PHP 7.4 safe. Off: wa_occ_on = 0.
 */
if (!function_exists('wocc_defs')) {
    /** key => [title, from (first day of sending), to (last day), emoji, what the photo should look like, approx (the date moves every year)]. */
    function wocc_defs(): array
    {
        return [
            'xmas'   => ['t' => 'Christmas',     'from' => '2026-12-18', 'to' => '2026-12-25', 'e' => '🎄', 'deco' => 'Christmas decoration (red and green, small lights, a little tree)', 'approx' => 0],
            'ny'     => ['t' => 'New Year',      'from' => '2026-12-26', 'to' => '2027-01-02', 'e' => '🎊', 'deco' => 'New Year decoration (golden, sparkle, "2027")', 'approx' => 0],
            'uttar'  => ['t' => 'Uttarayan',     'from' => '2027-01-08', 'to' => '2027-01-14', 'e' => '🪁', 'deco' => 'kites and a firki', 'approx' => 0],
            'val'    => ['t' => 'Valentine week', 'from' => '2027-02-07', 'to' => '2027-02-14', 'e' => '❤️', 'deco' => 'red roses and hearts', 'approx' => 0],
            'women'  => ['t' => 'Women\'s Day',  'from' => '2027-03-04', 'to' => '2027-03-08', 'e' => '🌸', 'deco' => 'flowers, soft pink', 'approx' => 0],
            'holi'   => ['t' => 'Holi',          'from' => '2027-03-16', 'to' => '2027-03-22', 'e' => '🎨', 'deco' => 'gulal colours', 'approx' => 1],
            'rakhi'  => ['t' => 'Raksha Bandhan', 'from' => '2027-08-09', 'to' => '2027-08-17', 'e' => '🎀', 'deco' => 'a rakhi and a small gift thali', 'approx' => 1],
            'navrat' => ['t' => 'Navratri',      'from' => '2027-09-27', 'to' => '2027-10-08', 'e' => '💃', 'deco' => 'garba colours, dandiya', 'approx' => 1],
            'diwali' => ['t' => 'Diwali',        'from' => '2027-10-19', 'to' => '2027-10-29', 'e' => '🪔', 'deco' => 'diyas and rangoli', 'approx' => 1],
        ];
    }
    function wocc_on(): bool { try { return !isset($GLOBALS['pdo']) || (string)setting_get($GLOBALS['pdo'], 'wa_occ_on', '1') !== '0'; } catch (Throwable $e) { return true; } }
    /** The occasions whose messages go today: [key => def]. */
    function wocc_active(?int $ts = null): array
    {
        if (!wocc_on()) return []; $d = date('Y-m-d', $ts ?? time()); $o = []; foreach (wocc_defs() as $k => $v) if ($d >= $v['from'] && $d <= $v['to']) $o[$k] = $v; return $o;
    }
    /** The occasions that start within $days days (or are on): [key => def]. */
    function wocc_soon(int $days, ?int $ts = null): array
    {
        if (!wocc_on()) return []; $now = $ts ?? time(); $d = date('Y-m-d', $now); $lim = date('Y-m-d', $now + $days * 86400); $o = []; foreach (wocc_defs() as $k => $v) if ($d <= $v['to'] && $lim >= $v['from']) $o[$k] = $v; return $o;
    }
    /** Is this message name an occasion message that may go today? (not an occasion message at all = true) */
    function wocc_name_ok(string $name, ?int $ts = null): bool
    {
        if (!preg_match('/^tmph_oc_([a-z]+)_/', $name, $m)) return true; return isset(wocc_active($ts)[$m[1]]);
    }
    /** The two TEXT messages of an occasion (marketing, the name is the only blank): name => [body, buttons]. */
    function wocc_text_defs(int $lead = 7): array
    {
        $o = []; foreach (wocc_soon($lead) as $k => $v) { $t = $v['t']; $e = $v['e'];
            $o['tmph_oc_' . $k . '_01'] = ['body' => "Hello {{1}} " . $e . "\n\n*" . $t . " special* 🎁\n\n5 perfumes sirf ₹2,750 me ? 👀 Haan ji ! 5 × 50ml ka combo 🔥\n\nApni pasand ke 5 perfumes chuniye ✨\n\nOrder ke liye tap kijiye 👇", 'buttons' => ['Order Now', 'Send Details']];
            $o['tmph_oc_' . $k . '_02'] = ['body' => "Hi {{1}} " . $e . "\n\n" . $t . " ke liye aapko kaunsa size chahiye ? 🤔\n\nHar size par ek khaas combo ready he, 160 ML sirf ₹1,999 se 🔥\n\nApna size dabaiye 👇", 'buttons' => ['20ML', '50ML', '100ML']]; }
        return $o;
    }
    /** The two PHOTOS of an occasion for "Needed photos": key => [title, brief, use, tpls]. */
    function wocc_photo_needs(int $lead = 21): array
    {
        $o = []; foreach (wocc_soon($lead) as $k => $v) { $t = $v['t']; $e = $v['e']; $by = date('j M', strtotime($v['from']) - 4 * 86400); $use = 'Cold funnel + After-Order + your campaigns, ' . date('j M', strtotime($v['from'])) . ' – ' . date('j M', strtotime($v['to'])) . ($v['approx'] ? ' (date to be confirmed)' : '');
            $o['oc_' . $k . '_a'] = ['title' => $t . ': the 160 ML combo (8 × 20ml)', 'brief' => '8 bottles of 20 ml together with ' . $v['deco'] . '. Wide photo (landscape). Needed by ' . $by . ' so that Meta approves it in time.', 'use' => $use,
                'tpls' => ['tmph_oc_' . $k . '_p1' => ["Hello {{1}} " . $e . "\n\n*" . $t . " special* 🎁\n\n8 perfumes sirf ₹1,999 me ? 👀\n\nHaan ji ! 8 × 20ml (160 ML), aapki pasand ki 8 khushbu 🌸\n\nOrder ke liye tap kijiye 👇", ['BOOK MY COMBO', 'Show more combos']]]];
            $o['oc_' . $k . '_b'] = ['title' => $t . ': gift pack (3 × 50ml + 1 × 20ml)', 'brief' => '3 bottles of 50 ml and ONE 20 ml bottle in a gift box or gift wrap with ' . $v['deco'] . '. Wide photo (landscape). Needed by ' . $by . '.', 'use' => $use,
                'tpls' => ['tmph_oc_' . $k . '_p2' => ["Hi {{1}} 🎁\n\n" . $t . " par kisi ko perfume gift dena he ? " . $e . "\n\n3 × 50ml sirf ₹2,140 aur saath me 1 × 20ml FREE 🔥\n\nKiske liye chahiye ? 👇", ['For me', 'As a gift']]]]; }
        return $o;
    }
    /** The calendar for the Campaigns page: the next occasions with their days and what is waited for. */
    function wocc_calendar(int $n = 6): array
    {
        $o = []; $d = date('Y-m-d'); foreach (wocc_defs() as $k => $v) { if ($v['to'] < $d) continue; $days = (int)floor((strtotime($v['from']) - strtotime($d)) / 86400);
            $o[] = ['key' => $k, 'title' => $v['t'], 'e' => $v['e'], 'from' => $v['from'], 'to' => $v['to'], 'approx' => (int)$v['approx'], 'in_days' => max(0, $days), 'state' => $d >= $v['from'] ? 'on now' : ($days <= 7 ? 'messages are being made' : ($days <= 21 ? 'photos are asked for' : 'later'))]; if (count($o) >= $n) break; }
        return $o;
    }
}
