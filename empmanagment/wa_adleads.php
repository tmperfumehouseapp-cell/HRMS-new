<?php
/**
 * wa_adleads.php — WhatsApp → Queries & reports → 📣 Ad leads (action wa.g_adleads, Admin / HR; loaded by wa_growth.php).
 * One day, one line per customer who came from a Meta click-to-WhatsApp ad: the time, the WhatsApp number they wrote to,
 * the ad, new or returning, and the FIRST message they wrote that day — so nobody has to open hundreds of chats to see the leads.
 *
 * Where it reads: wa_queries (a query the HRMS opened with src = 'query', i.e. the message carried an ad referral or the customer first came from an ad),
 * wa_contacts (name, ad) and wa_messages (first incoming message of the day).
 * ⚡ Light on the server: wa_queries is read through its opened_at index for one day only, and the first messages come in a few grouped reads
 * through the (phone, id) index. It never scans all chats, writes nothing, and runs only when the page is opened or refreshed.
 */
function wag_adleads(PDO $pdo, string $day, int $numId, int $cap = 1500): array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || strtotime($day) === false) $day = date('Y-m-d');
    $from = $day . ' 00:00:00'; $to = date('Y-m-d', strtotime($day . ' 12:00:00') + 86400) . ' 00:00:00';
    $st = $pdo->prepare("SELECT q.phone, q.opened_at, q.again, c.name, c.ad_id, c.ad_head, c.ref_text FROM wa_queries q JOIN wa_contacts c ON c.phone = q.phone
        WHERE q.opened_at >= ? AND q.opened_at < ? AND q.src = 'query' ORDER BY q.opened_at DESC, q.id DESC LIMIT " . ($cap + 1));
    $st->execute([$from, $to]); $qs = $st->fetchAll(PDO::FETCH_ASSOC); $more = count($qs) > $cap; if ($more) array_pop($qs);
    $first = []; $phones = array_values(array_unique(array_map(fn($r) => (string)$r['phone'], $qs)));
    foreach (array_chunk($phones, 300) as $ch) {   // the first message each of them wrote that day
        $in = implode(',', array_fill(0, count($ch), '?'));
        $m = $pdo->prepare("SELECT m.phone, m.number_id, m.mtype, m.body, m.ts FROM wa_messages m JOIN (SELECT MIN(id) AS mid FROM wa_messages WHERE phone IN ($in) AND dir = 'in' AND ts >= ? AND ts < ? GROUP BY phone) x ON x.mid = m.id");
        $m->execute(array_merge($ch, [$from, $to])); foreach ($m->fetchAll(PDO::FETCH_ASSOC) as $r) $first[(string)$r['phone']] = $r;
    }
    $nums = []; foreach ($pdo->query("SELECT id, label, display FROM wa_numbers ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $n) $nums[(int)$n['id']] = ['id' => (int)$n['id'], 'label' => trim((string)$n['label']) !== '' ? (string)$n['label'] : (string)$n['display'], 'n' => 0];
    $rows = []; $seen = []; $new = 0; $firstQ = []; foreach ($qs as $r) $firstQ[(string)$r['phone']] = (int)$r['again'];   // newest first, so what stays is the FIRST query of the day: new that day = new
    foreach ($qs as $r) {
        $ph = (string)$r['phone']; if (isset($seen[$ph])) continue; $seen[$ph] = 1;   // one line per customer (the newest query of the day)
        $f = $first[$ph] ?? null; $nid = $f ? (int)$f['number_id'] : 0; if (isset($nums[$nid])) $nums[$nid]['n']++;   // the chips count every number, whatever number is picked
        if ($numId > 0 && $nid !== $numId) continue;
        $txt = $f ? trim((string)$f['body']) : ''; if ($f && $txt === '' && (string)$f['mtype'] !== 'text') $txt = '[' . (string)$f['mtype'] . ']';
        $isNew = ($firstQ[$ph] ?? 1) === 0; if ($isNew) $new++;
        $rows[] = ['phone' => $ph, 'name' => (string)$r['name'], 'at' => (string)($f['ts'] ?? $r['opened_at']), 'number' => isset($nums[$nid]) ? $nums[$nid]['label'] : '', 'new' => $isNew,
            'ad' => mb_substr((string)((string)$r['ad_head'] !== '' ? $r['ad_head'] : $r['ref_text']), 0, 90), 'ad_id' => (string)$r['ad_id'], 'first' => mb_substr($txt, 0, 300)];
    }
    return ['date' => $day, 'number' => $numId, 'rows' => $rows, 'total' => count($rows), 'new' => $new, 'all' => count($seen), 'numbers' => array_values($nums), 'more' => $more];
}
