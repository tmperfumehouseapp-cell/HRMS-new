<?php
/** wa_lim.php — how many people count against Meta's 24-hour messaging limit (used by the campaign engine and the WhatsApp console). PHP 7.4 safe. */
if (!function_exists('wa_lim_used')) {

/**
 * The people that count against Meta's limit since $since, as Meta counts them: unique customers who got a template OUTSIDE their
 * 24-hour chat window. A template that failed never reached them, and a template sent while the customer had written in the last
 * 24 hours is inside the window — neither is counted (counting them blocked campaigns with "no room" long before Meta's limit).
 * Returns ['by' => [numberId => people], 'all' => people].
 */
function wa_lim_used(PDO $pdo, string $since): array
{
    $by = []; $all = [];
    // ⚡ screens only (the Campaigns list sets the flag): the 24-hour count is a heavy scan of wa_messages, so it is remembered for 30 s. The sender never sets the flag and always counts fresh.
    $useCache = !empty($GLOBALS['__wa_lim_cache_ok']);
    // ⚡ the 24-hour count is a heavy scan: remembered for 30 s on screens and for 15 s for the sender, and only ONE copy runs at a time (the others take the last stored count) — seven copies at once were freezing the database
    $hit = null; try { $hit = json_decode((string)setting_get($pdo, 'wa_lim_cache', ''), true); if (!is_array($hit) || !is_array($hit['by'] ?? null)) $hit = null; } catch (Throwable $e) { $hit = null; }
    $ttl = $useCache ? 30 : 15; if ($hit && (int)($hit['t'] ?? 0) > time() - $ttl) return ['by' => array_map('intval', $hit['by']), 'all' => (int)$hit['all']];
    try { $lk = (int)setting_get($pdo, 'wa_lim_lock', '0'); if ($hit && $lk > time() - 45) return ['by' => array_map('intval', $hit['by']), 'all' => (int)$hit['all']]; setting_set($pdo, 'wa_lim_lock', (string)time()); } catch (Throwable $e) { }
    $useCache = true;
    try {
        $q = $pdo->prepare("SELECT m.number_id, m.phone FROM wa_messages m WHERE m.dir = 'out' AND m.mtype = 'template' AND m.status <> 'failed' AND m.ts >= ?
            AND NOT EXISTS (SELECT 1 FROM wa_messages i WHERE i.phone = m.phone AND i.dir = 'in' AND i.ts <= m.ts AND i.ts >= DATE_SUB(m.ts, INTERVAL 24 HOUR))");
        $q->execute([$since]);
        foreach ($q->fetchAll() as $r) { $by[(int)$r['number_id']][(string)$r['phone']] = 1; $all[(string)$r['phone']] = 1; }
    } catch (Throwable $e) { }
    $res = ['by' => array_map('count', $by), 'all' => count($all)];
    if ($useCache) { try { setting_set($pdo, 'wa_lim_cache', json_encode(['t' => time()] + $res)); } catch (Throwable $e) { } }
    return $res;
}

}
