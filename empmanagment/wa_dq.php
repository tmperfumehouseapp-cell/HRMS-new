<?php
/**
 * wa_dq.php — 📅 The daily plan: ALL campaigns are one queue (owner, 11 Nov 2026).
 *
 *  - One shared cap for every campaign together: wa_dq_hourly (default 10,000) campaign messages in any rolling hour. When it is used the campaigns wait for the next hour by themselves.
 *    ⚡ Send fast campaigns are the owner's explicit "now" and are not held by it (their sends still count in the hour).
 *  - Within a campaign the people who wrote to us most recently go first (Meta holds messages to people who do not engage).
 *  - A person is not messaged twice: the sender already skips whoever got a campaign message in the last wa_camp_gap_days (default 3), who just bought, or ignored the last 2 campaigns.
 *    A new campaign simply joins the queue (the sender looks at every running campaign every round).
 *  - Meta's own 24-hour room (with our margin) stays the ceiling; the number rules (same number for people who wrote to it, the rest by room, Medium = High) are unchanged.
 * Settings: wa_dq_on 1 · wa_dq_hourly 10000 · wa_dq_daily 75000 (campaigns per business day: Meta's room is also used by recovery retries, order updates and follow-ups, so campaigns leave about 15,000 of it free).   Route (in wa_camp.php): wa.dq_set POST {on, hourly}
 */
if (!function_exists('wa_dq_cfg')) {

function wa_dq_cfg(PDO $pdo): array
{
    $g = function (string $k, string $d) use ($pdo) { try { $v = setting_get($pdo, $k, $d); return ($v === null || $v === '') ? $d : (string)$v; } catch (Throwable $e) { return $d; } };
    return ['on' => $g('wa_dq_on', '1') !== '0', 'hourly' => max(500, min(60000, (int)$g('wa_dq_hourly', '10000'))), 'daily' => max(1000, min(1000000, (int)$g('wa_dq_daily', '75000')))];
}
/** This hour's room: ['on', 'cap', 'used', 'left']. Remembered for 15 s (every campaign of a round asks). */
function wa_dq_left(PDO $pdo): array
{
    static $memo = null, $at = 0; if ($memo !== null && microtime(true) - $at < 15) return $memo;
    $cfg = wa_dq_cfg($pdo); $used = 0; $dayUsed = 0;
    if ($cfg['on']) {
        try { $q = $pdo->prepare("SELECT COUNT(*) FROM wa_messages WHERE dir = 'out' AND who = 'campaign' AND mtype = 'template' AND ts >= ?"); $q->execute([date('Y-m-d H:i:s', time() - 3600)]); $used = (int)$q->fetchColumn(); } catch (Throwable $e) { }
        try { $from = function_exists('wa_bizday_start') ? wa_bizday_start($pdo) : date('Y-m-d 10:00:00'); $q = $pdo->prepare("SELECT COUNT(*) FROM wa_messages WHERE dir = 'out' AND who = 'campaign' AND mtype = 'template' AND ts >= ?"); $q->execute([$from]); $dayUsed = (int)$q->fetchColumn(); } catch (Throwable $e) { }
    }
    $hourLeft = max(0, $cfg['hourly'] - $used); $dayLeft = max(0, $cfg['daily'] - $dayUsed);
    $memo = ['on' => $cfg['on'], 'cap' => $cfg['hourly'], 'used' => $used, 'daily' => $cfg['daily'], 'day_used' => $dayUsed, 'day_left' => $dayLeft, 'left' => min($hourLeft, $dayLeft), 'why' => $dayLeft <= 0 ? 'day' : ($hourLeft <= 0 ? 'hour' : '')]; $at = microtime(true); return $memo;
}
/** Campaign messages by hour of the day: pushed (accepted by Meta), delivered / read — for the current business day and for the last 7 days together (which hours suit best). Remembered for 60 s. */
function wa_dq_hours(PDO $pdo): array
{
    try { $hit = json_decode((string)setting_get($pdo, 'wa_dq_hours', ''), true); if (is_array($hit) && (int)($hit['t'] ?? 0) > time() - 60) return $hit; } catch (Throwable $e) { }
    $from = function_exists('wa_bizday_start') ? wa_bizday_start($pdo) : date('Y-m-d 10:00:00'); $to = date('Y-m-d H:i:s', strtotime($from) + 86400);
    $o = ['t' => time(), 'from' => $from, 'to' => $to, 'start_hour' => (int)date('G', strtotime($from)), 'today' => [], 'week' => []];
    $run = function (string $a, string $b) use ($pdo) { $r = []; try { $q = $pdo->prepare("SELECT HOUR(ts) AS h, COUNT(*) AS n, SUM(status IN ('delivered', 'read')) AS d, SUM(status = 'read') AS rd FROM wa_messages WHERE dir = 'out' AND who = 'campaign' AND mtype = 'template' AND ts >= ? AND ts < ? GROUP BY HOUR(ts)"); $q->execute([$a, $b]); foreach ($q->fetchAll() as $x) $r[(int)$x['h']] = [(int)$x['n'], (int)$x['d'], (int)$x['rd']]; } catch (Throwable $e) { } return $r; };
    $o['today'] = $run($from, $to); $o['week'] = $run(date('Y-m-d H:i:s', time() - 7 * 86400), date('Y-m-d H:i:s', time() + 60));
    try { setting_set($pdo, 'wa_dq_hours', json_encode($o)); } catch (Throwable $e) { }
    return $o;
}
/** The plan card: cap, used, people waiting in the one queue, Meta room. Remembered for 30 s. */
function wa_dq_status(PDO $pdo): array
{
    $cfg = wa_dq_cfg($pdo);
    try { $hit = json_decode((string)setting_get($pdo, 'wa_dq_stat', ''), true); if (is_array($hit) && (int)($hit['t'] ?? 0) > time() - 30 && ($hit['cap'] ?? 0) === $cfg['hourly'] && ($hit['daily'] ?? 0) === $cfg['daily'] && ($hit['on'] ?? null) === $cfg['on']) return $hit; } catch (Throwable $e) { }
    $l = wa_dq_left($pdo); $o = ['t' => time(), 'on' => $cfg['on'], 'cap' => $cfg['hourly'], 'used' => $l['used'], 'left' => $l['left'], 'daily' => $l['daily'], 'day_used' => $l['day_used'], 'why' => $l['why'], 'people' => 0, 'campaigns' => 0, 'gap_days' => max(0, (int)setting_get($pdo, 'wa_camp_gap_days', '3'))];
    try { $r = $pdo->query("SELECT COUNT(DISTINCT i.phone) AS p, COUNT(DISTINCT i.camp_id) AS c FROM wa_camp_items i JOIN wa_campaigns c ON c.id = i.camp_id WHERE c.status = 'running' AND i.status IN ('queued', 'retry')")->fetch(); $o['people'] = (int)$r['p']; $o['campaigns'] = (int)$r['c']; } catch (Throwable $e) { }
    try { setting_set($pdo, 'wa_dq_stat', json_encode($o)); } catch (Throwable $e) { }
    return $o;
}

}
