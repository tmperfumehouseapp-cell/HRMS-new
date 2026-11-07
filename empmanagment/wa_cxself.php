<?php
/**
 * wa_cxself.php — 🎫 a customer raises a complaint ticket THEMSELVES on WhatsApp (owner's plan).
 *
 *  1. The customer writes "complaint", "ticket", "shikayat", "raise an issue" (a short message) → a list of issues comes back (one tap).
 *  2. They pick the issue → "please describe the problem in one message; you can also send photos or a video".
 *  3. Their next message (text, photo or video) becomes the ticket: cx_raise (Complaints, loaded through boss.php) puts it in the right department,
 *     marks it Serious / Basic, gives it an owner in turn and sends "your ticket C-0112 has been created for …" (complaints_more.php cx3_*).
 *  While this runs (30 minutes at most) the bot and the other automatic messages stay quiet (wa_auto_muted). A customer who already has an open ticket
 *  is told its number instead of getting a second one. Every message goes back from the same number the customer wrote to.
 *  Loaded by wa_more.php; called from wa_source_hook for every customer message. PHP 7.4 safe. Setting wa_cx_self = 0 switches it off.
 */
if (!function_exists('wa_cxself_on')) {

function wa_cxself_on(PDO $pdo): bool { return (string)setting_get($pdo, 'wa_cx_self', '1') === '1'; }
/** The issues the customer can pick: [problem key, title (max 24), description]. */
function wa_cxself_items(): array
{
    return [['notdispatched', 'Parcel not dispatched', 'My order has not left yet'], ['notdelivered', 'Parcel not delivered', 'The parcel did not reach me'], ['missing', 'Item missing', 'Parcel came, a product is missing'], ['tracking', 'Tracking ID needed', 'I need my tracking number / status'],
            ['leak', 'Leak / damaged', 'Bottle leaking, broken or damaged'], ['wrong', 'Wrong item', 'I got a different product'], ['quality', 'Smell / quality', 'Smell or quality is not right'],
            ['notliked', 'Return / exchange', 'I want to return or exchange'], ['refund', 'Refund', 'About a refund'], ['other', 'Other problem', 'Something else']];
}
function wa_cxself_state(PDO $pdo, string $key): ?array { $j = json_decode((string)setting_get($pdo, 'cxsr_' . $key, ''), true); return is_array($j) && (int)($j['at'] ?? 0) > time() - 1800 ? $j : null; }
function wa_cxself_put(PDO $pdo, string $key, ?array $st): void { setting_set($pdo, 'cxsr_' . $key, $st ? json_encode($st) : ''); }
/** The open ticket of this customer (code) or ''. */
function wa_cxself_open(PDO $pdo, string $key): string
{
    try { $q = $pdo->prepare("SELECT id FROM cx_cases WHERE phone = ? AND status IN ('open','reopened') ORDER BY id DESC LIMIT 1"); $q->execute([$key]); $id = (int)$q->fetchColumn(); return $id ? 'C-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT) : ''; } catch (Throwable $e) { return ''; }
}
function wa_cxself_say(PDO $pdo, array $c, string $text): void { if (function_exists('wa_send_text')) { try { wa_send_text($pdo, $c, $text, 'order', 0); } catch (Throwable $e) { } } }
/** Called for every customer message. Returns true when this message belonged to the ticket conversation (nothing else should answer it). */
function wa_cxself_hook(PDO $pdo, string $key, string $text): bool
{
    if (!wa_cxself_on($pdo)) return false; $c = function_exists('wa_contact') ? wa_contact($pdo, $key) : null; if (!$c || (int)($c['optout'] ?? 0) === 1) return false;
    $st = wa_cxself_state($pdo, $key); $t = trim($text); $low = mb_strtolower($t);
    if (!$st) {   // 1. the trigger: a short message that asks for a complaint / ticket
        if (mb_strlen($t) > 60 || !preg_match('/^\W*((i (want|need) to )?(raise |make |file |register |log )?(a |my )?(complaint|complain|ticket|shikayat|shikaayat)\b|(raise|register|log|file) (a |an |my )?(issue|problem)\b|(फरियाद|शिकायत|ફરિયાદ))/iu', $t)) return false;
        $open = wa_cxself_open($pdo, $key); if ($open !== '') { wa_cxself_say($pdo, $c, 'You already have an open ticket ' . $open . ' with us 🙏 Our team is working on it and will update you here. Write your message and it will be added to the same ticket.'); return true; }
        $rows = array_map(fn($x) => ['id' => 'cxp_' . $x[0], 'title' => $x[1], 'description' => $x[2]], wa_cxself_items());
        $sent = false; if (function_exists('wag_flow_send_interactive')) { try { [$sent] = wag_flow_send_interactive($pdo, $c, ['type' => 'list', 'body' => ['text' => "Sorry for the trouble 🙏 Let's raise a ticket for you. What is the problem?"], 'action' => ['button' => 'Choose the issue', 'sections' => [['title' => 'Issues', 'rows' => $rows]]]], "Raise a ticket — choose the issue:\n" . implode("\n", array_map(fn($x) => '• ' . $x[1], wa_cxself_items()))); } catch (Throwable $e) { $sent = false; } }
        if (!$sent) wa_cxself_say($pdo, $c, "Sorry for the trouble 🙏 Let's raise a ticket for you. Reply with the number of your problem:\n" . implode("\n", array_map(fn($i, $x) => ($i + 1) . '. ' . $x[1], array_keys(wa_cxself_items()), wa_cxself_items())));
        wa_cxself_put($pdo, $key, ['step' => 'pick', 'at' => time()]); return true;
    }
    if ($st['step'] === 'pick') {   // 2. the issue (the list tap shows its title; a number works too)
        $pick = ''; foreach (wa_cxself_items() as $i => $x) if ($low === mb_strtolower($x[1]) || $t === (string)($i + 1)) $pick = $x[0];
        if ($pick === '') { if (preg_match('/^(cancel|stop|no|nahi)\b/i', $t)) { wa_cxself_put($pdo, $key, null); wa_cxself_say($pdo, $c, 'Okay 🙏 No ticket was raised. Write to us any time.'); return true; } wa_cxself_say($pdo, $c, 'Please choose the issue from the list (or reply with its number). Reply CANCEL to stop.'); $st['at'] = time(); wa_cxself_put($pdo, $key, $st); return true; }
        wa_cxself_put($pdo, $key, ['step' => 'text', 'problem' => $pick, 'at' => time()]);
        wa_cxself_say($pdo, $c, 'Thank you. Please describe the problem in ONE message' . (in_array($pick, ['leak', 'wrong', 'quality', 'missing'], true) ? ' — and send a photo or the unboxing video if you have it' : '') . ($pick !== 'other' ? ' (your order number helps too)' : '') . '.'); return true;
    }
    if ($st['step'] === 'text') {   // 3. the description → the ticket
        wa_cxself_put($pdo, $key, null); $prob = (string)($st['problem'] ?? 'other'); $order = preg_match('/#?\b(\d{4,6})\b/', $t, $m) ? '#' . $m[1] : '';
        $res = wa_cxself_raise($pdo, $c, $prob, $t, $order);
        if ($res === '') wa_cxself_say($pdo, $c, 'Thank you 🙏 We got your message — our complaint team will contact you here shortly.');
        return true;
    }
    return false;
}
/** Makes the ticket through the Complaints module (boss.php → complaints.php). Returns the ticket code or ''. The "ticket created" message is sent by cx3_on_raise. */
function wa_cxself_raise(PDO $pdo, array $c, string $problem, string $summary, string $order): string
{
    try {
        if (!function_exists('cx_raise')) { $action = 'wa.cxself'; if (is_file(__DIR__ . '/boss.php')) require_once __DIR__ . '/boss.php'; }   // the Complaints functions (they need the Boss Engine helpers)
        if (!function_exists('cx_raise')) return '';
        if (function_exists('cx_ensure')) cx_ensure($pdo); if (function_exists('cx2_ensure')) cx2_ensure($pdo);
        $a = cx_raise($pdo, [], ['emp' => 0, 'admin' => false, 'mgr' => false, 'team' => true, 'desks' => []], ['problem' => $problem, 'source' => 'whatsapp', 'phone' => (string)$c['phone'], 'name' => (string)($c['name'] ?? ''),
            'summary' => mb_substr('Customer (self): ' . $summary, 0, 900), 'order_name' => $order, 'urgency' => 'normal', 'self' => 1]);
        if ($a[0] && !empty($a[3]['code'])) { if (!empty($a[3]['existing'])) wa_cxself_say($pdo, $c, 'Your message was added to your open ticket ' . $a[3]['code'] . ' 🙏 Our team will update you here.'); return (string)$a[3]['code']; }
    } catch (Throwable $e) { error_log('wa_cxself_raise: ' . $e->getMessage()); }
    return '';
}

}   // function_exists guard
