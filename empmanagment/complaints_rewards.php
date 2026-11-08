<?php
/**
 * complaints_rewards.php — 🎁 FREE-PERFUME REWARDS (a ledger in ml) + ⭐ REVIEWS, inside the Complaints desk. PHP 7.4 safe.
 * Loaded at the bottom of complaints.php. Actions arrive as  bd.cx_rw_*  (cx_dispatch → cx2_dispatch → cxrw_dispatch).
 *
 *  rw_reward   one row per gift:  kind  review | birthday | goodwill     ml  20 / 50 …     min_order  ₹ (online use)     code  RV-XXXXXX / BD-… / GW-…
 *              status  wait (a boss must approve) | ok (active) | used | void     expires_at     src_ref  (case / rating / voucher)
 *  The ₹ store credit (cx_credit) is NOT touched: that ledger only holds rupees. The "Store credit" tab shows both.
 *  Rules (settings, Admin/boss): review gift 20 ml, order above ₹1,500, valid 15 days; the team may give up to 20 ml, more waits for the boss.
 *  Nothing here sends a customer message by itself except the "your gift is added" note when a review screenshot is accepted (wa_review.php).
 */
if (!function_exists('cxrw_dispatch')) {

function cxrw_ensure(PDO $pdo): bool
{
    static $ok = null; if ($ok !== null) return $ok;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS rw_reward (
            id INT AUTO_INCREMENT PRIMARY KEY, phone VARCHAR(12) NOT NULL, kind VARCHAR(10) NOT NULL DEFAULT 'goodwill', ml INT NOT NULL DEFAULT 20, min_order INT NOT NULL DEFAULT 0,
            code VARCHAR(14) NOT NULL DEFAULT '', status VARCHAR(6) NOT NULL DEFAULT 'ok', src VARCHAR(10) NOT NULL DEFAULT 'case', src_ref VARCHAR(40) NOT NULL DEFAULT '', order_name VARCHAR(40) NOT NULL DEFAULT '',
            reason VARCHAR(300) NOT NULL DEFAULT '', choice VARCHAR(120) NOT NULL DEFAULT '', expires_at DATETIME NULL, by_emp INT NOT NULL DEFAULT 0, ok_by INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL, used_at DATETIME NULL, used_ref VARCHAR(60) NOT NULL DEFAULT '', used_by INT NOT NULL DEFAULT 0,
            UNIQUE KEY uq_code (code), KEY k_phone (phone, id), KEY k_status (status, expires_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ok = true;
    } catch (Throwable $e) { error_log('cxrw_ensure: ' . $e->getMessage()); $ok = false; }
    return $ok;
}
function cxrw_cfg(PDO $pdo): array
{
    $d = ['review_ml' => 20, 'review_min' => 1500, 'review_days' => 15, 'bday_ml' => 50, 'bday_min' => 1500, 'bday_days' => 15, 'team_max_ml' => 20, 'auto_shot' => 1];
    $j = function_exists('setting_get') ? json_decode((string)setting_get($pdo, 'rw_cfg', ''), true) : null;
    if (is_array($j)) foreach ($d as $k => $v) if (isset($j[$k]) && is_numeric($j[$k])) $d[$k] = max(0, (int)$j[$k]);
    return $d;
}
function cxrw_code(PDO $pdo, string $pre): string
{
    $al = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    for ($t = 0; $t < 20; $t++) { $c = $pre . '-'; for ($i = 0; $i < 6; $i++) $c .= $al[random_int(0, strlen($al) - 1)]; $q = $pdo->prepare("SELECT COUNT(*) FROM rw_reward WHERE code = ?"); $q->execute([$c]); if (!(int)$q->fetchColumn()) return $c; }
    return $pre . '-' . strtoupper(bin2hex(random_bytes(3)));
}
/** Makes one gift. $status 'ok' (active) or 'wait' (a boss must approve). Returns ['id','code','expires'] or ['error']. */
function cxrw_give(PDO $pdo, string $phone, string $kind, int $ml, int $minOrder, int $days, string $src, string $ref, string $order, string $reason, string $status, int $by): array
{
    if (!cxrw_ensure($pdo)) return ['error' => 'Reward storage is not available.'];
    $phone = substr(preg_replace('/\D+/', '', $phone), -10); if (strlen($phone) !== 10) return ['error' => 'A 10-digit phone number is needed.'];
    if ($ml < 5 || $ml > 200) return ['error' => 'Choose between 5 and 200 ml.'];
    $pre = ['review' => 'RV', 'birthday' => 'BD', 'goodwill' => 'GW'][$kind] ?? 'GW'; $code = cxrw_code($pdo, $pre);
    $exp = date('Y-m-d 23:59:59', time() + max(1, $days) * 86400);
    $pdo->prepare("INSERT INTO rw_reward (phone, kind, ml, min_order, code, status, src, src_ref, order_name, reason, expires_at, by_emp, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
        ->execute([$phone, $kind, $ml, max(0, $minOrder), $code, $status === 'wait' ? 'wait' : 'ok', mb_substr($src, 0, 10), mb_substr($ref, 0, 40), mb_substr($order, 0, 40), mb_substr($reason, 0, 300), $exp, $by, date('Y-m-d H:i:s')]);
    return ['id' => (int)$pdo->lastInsertId(), 'code' => $code, 'expires' => $exp];
}
/** The gifts a customer can use right now (active, not expired). */
function cxrw_active(PDO $pdo, string $phone): array
{
    if (!cxrw_ensure($pdo)) return [];
    $q = $pdo->prepare("SELECT * FROM rw_reward WHERE phone = ? AND status = 'ok' AND (expires_at IS NULL OR expires_at >= ?) ORDER BY expires_at"); $q->execute([substr(preg_replace('/\D+/', '', $phone), -10), date('Y-m-d H:i:s')]);
    return $q->fetchAll();
}
function cxrw_view(array $r): array
{
    return ['id' => (int)$r['id'], 'phone' => (string)$r['phone'], 'kind' => (string)$r['kind'], 'ml' => (int)$r['ml'], 'min_order' => (int)$r['min_order'], 'code' => (string)$r['code'], 'status' => (string)$r['status'],
        'src' => (string)$r['src'], 'order' => (string)$r['order_name'], 'reason' => (string)$r['reason'], 'choice' => (string)$r['choice'], 'expires' => $r['expires_at'] ? substr((string)$r['expires_at'], 0, 10) : '',
        'created' => substr((string)$r['created_at'], 0, 16), 'used_at' => $r['used_at'] ? substr((string)$r['used_at'], 0, 16) : '', 'used_ref' => (string)$r['used_ref'],
        'expired' => $r['status'] === 'ok' && $r['expires_at'] && strtotime((string)$r['expires_at']) < time()];
}

function cxrw_dispatch(PDO $pdo, string $action, array $auth, array $role, array $in, bool $isPost, string $meName): void
{
    if (!cxrw_ensure($pdo)) json_response(['error' => 'Reward storage is not available — check database permissions.'], 500);
    foreach (['wa_orders.php', 'wa_review.php'] as $f) { if (!function_exists($f === 'wa_orders.php' ? 'wo_first_name' : 'wr_tables') && is_file(__DIR__ . '/' . $f)) { try { require_once __DIR__ . '/' . $f; } catch (Throwable $e) { error_log('cxrw load ' . $f . ': ' . $e->getMessage()); } } }   // the review tables + the helpers the review gift uses
    if (function_exists('wr_tables')) wr_tables($pdo);
    $me = (int)$role['emp']; $cfg = cxrw_cfg($pdo); $now = date('Y-m-d H:i:s');
    $post = function () use ($isPost) { if (!$isPost) json_response(['error' => 'Method not allowed.'], 405); };
    $needTeam = function () use ($role) { if (!$role['team']) json_response(['error' => 'This is for the Complaint team.'], 403); };
    $needMgr = function () use ($role) { if (!$role['mgr']) json_response(['error' => 'Only the complaints boss, Admin or HR can do this.'], 403); };
    $nameOf = function (string $ph) use ($pdo) { try { $q = $pdo->prepare("SELECT name FROM wa_contacts WHERE phone = ?"); $q->execute([$ph]); return (string)$q->fetchColumn(); } catch (Throwable $e) { return ''; } };
    switch ($action) {

    case 'bd.cx_rw_tab': {   // the free-perfume block of the Store credit tab
        $needTeam();
        $pend = []; $act = []; $hist = [];
        foreach ($pdo->query("SELECT * FROM rw_reward WHERE status = 'wait' ORDER BY id DESC LIMIT 50")->fetchAll() as $r) $pend[] = cxrw_view($r) + ['name' => $nameOf((string)$r['phone'])];
        foreach ($pdo->query("SELECT * FROM rw_reward WHERE status = 'ok' ORDER BY expires_at LIMIT 100")->fetchAll() as $r) $act[] = cxrw_view($r) + ['name' => $nameOf((string)$r['phone'])];
        foreach ($pdo->query("SELECT * FROM rw_reward WHERE status IN ('used','void') ORDER BY id DESC LIMIT 40")->fetchAll() as $r) $hist[] = cxrw_view($r) + ['name' => $nameOf((string)$r['phone'])];
        $m0 = date('Y-m-01 00:00:00'); $k = $pdo->prepare("SELECT COALESCE(SUM(ml),0) FROM rw_reward WHERE status IN ('ok','used') AND created_at >= ?"); $k->execute([$m0]); $given = (int)$k->fetchColumn();
        $k = $pdo->prepare("SELECT COALESCE(SUM(ml),0) FROM rw_reward WHERE status = 'used' AND used_at >= ?"); $k->execute([$m0]); $used = (int)$k->fetchColumn();
        $open = 0; foreach ($act as $a) if (!$a['expired']) $open += $a['ml'];
        json_response(['pending' => $pend, 'active' => $act, 'history' => $hist, 'kpi' => ['given_ml' => $given, 'used_ml' => $used, 'open_ml' => $open, 'waiting' => count($pend)], 'cfg' => $cfg, 'mgr' => $role['mgr']]);
    }
    case 'bd.cx_rw_give': {   // from a ticket: "Give free perfume" (team up to team_max_ml, more waits for the boss)
        $post(); $needTeam(); $caseId = (int)($in['case_id'] ?? 0); $phone = (string)($in['phone'] ?? ''); $order = '';
        if ($caseId > 0) { $q = $pdo->prepare("SELECT phone, order_name FROM cx_cases WHERE id = ?"); $q->execute([$caseId]); $c = $q->fetch(); if (!$c) json_response(['error' => 'Complaint not found.'], 404); $phone = (string)$c['phone']; $order = (string)$c['order_name']; }
        $ml = (int)($in['ml'] ?? 0); $min = max(0, (int)($in['min_order'] ?? $cfg['review_min'])); $days = max(1, min(365, (int)($in['days'] ?? $cfg['review_days']))); $why = trim((string)($in['reason'] ?? ''));
        if ($why === '') json_response(['error' => 'Write why this gift is given.'], 422);
        $status = ($ml <= $cfg['team_max_ml'] || $role['mgr']) ? 'ok' : 'wait';
        $r = cxrw_give($pdo, $phone, 'goodwill', $ml, $min, $days, 'case', (string)$caseId, $order, $why, $status, $me); if (!empty($r['error'])) json_response(['error' => $r['error']], 422);
        if ($caseId > 0 && function_exists('cx_event')) { try { cx_event($pdo, $caseId, 'note', $meName . ' gave ' . $ml . ' ml free perfume (' . $r['code'] . ')' . ($status === 'wait' ? ' — waiting for the boss' : '')); } catch (Throwable $e) { } }
        json_response(['ok' => true, 'message' => $status === 'ok' ? 'Free ' . $ml . ' ml added. Code ' . $r['code'] . '.' : 'Sent to the boss to approve.', 'code' => $r['code'], 'status' => $status]);
    }
    case 'bd.cx_rw_ok': {
        $post(); $needMgr(); $q = $pdo->prepare("SELECT * FROM rw_reward WHERE id = ? AND status = 'wait'"); $q->execute([(int)($in['id'] ?? 0)]); $r = $q->fetch(); if (!$r) json_response(['error' => 'Not waiting any more.'], 404);
        $okk = empty($in['reject']); $pdo->prepare("UPDATE rw_reward SET status = ?, ok_by = ? WHERE id = ?")->execute([$okk ? 'ok' : 'void', $me, (int)$r['id']]);
        json_response(['ok' => true, 'message' => $okk ? 'Approved.' : 'Rejected.']);
    }
    case 'bd.cx_rw_void': {
        $post(); $needMgr(); $pdo->prepare("UPDATE rw_reward SET status = 'void' WHERE id = ? AND status IN ('ok','wait')")->execute([(int)($in['id'] ?? 0)]); json_response(['ok' => true, 'message' => 'Cancelled.']);
    }
    case 'bd.cx_rw_use': {   // the gift was used (typed in by the team until the store billing / website know the gifts)
        $post(); $needTeam(); $q = $pdo->prepare("SELECT * FROM rw_reward WHERE id = ? AND status = 'ok'"); $q->execute([(int)($in['id'] ?? 0)]); $r = $q->fetch(); if (!$r) json_response(['error' => 'This gift is not active.'], 404);
        if ($r['expires_at'] && strtotime((string)$r['expires_at']) < time()) json_response(['error' => 'This gift has expired.'], 422);
        $ref = trim((string)($in['ref'] ?? '')); $choice = trim((string)($in['choice'] ?? '')); if ($ref === '') json_response(['error' => 'Write the order or bill number it was used on.'], 422);
        $pdo->prepare("UPDATE rw_reward SET status = 'used', used_at = ?, used_ref = ?, choice = ?, used_by = ? WHERE id = ?")->execute([$now, mb_substr($ref, 0, 60), mb_substr($choice, 0, 120), $me, (int)$r['id']]);
        json_response(['ok' => true, 'message' => 'Marked as used.']);
    }
    case 'bd.cx_rw_cfg': {
        $needMgr(); if ($isPost) { $n = []; foreach ($cfg as $k => $v) if (isset($in[$k]) && is_numeric($in[$k])) $n[$k] = max(0, min(100000, (int)$in[$k])); else $n[$k] = $v; if (function_exists('setting_set')) setting_set($pdo, 'rw_cfg', json_encode($n)); $cfg = $n; }
        json_response(['ok' => true, 'cfg' => $cfg]);
    }

    case 'bd.cx_rw_reviews': {   // the Reviews tab: everyone who rated 4-5 and what happened next
        $needTeam();
        $rows = []; $q = $pdo->query("SELECT r.*, c.name FROM wa_review_rating r LEFT JOIN wa_contacts c ON c.phone = r.phone ORDER BY r.at DESC LIMIT 150");
        $k = ['rated' => 0, 'five' => 0, 'ticket' => 0, 'shot' => 0, 'paid' => 0, 'waiting' => 0]; $cut = date('Y-m-01 00:00:00');
        foreach ($q->fetchAll() as $r) {
            $inMonth = (string)$r['at'] >= $cut; if ($inMonth) { $k['rated']++; if ($r['action'] === 'judgeme') $k['five']++; if ($r['action'] === 'ticket') $k['ticket']++; if ($r['offer'] === 'paid') $k['paid']++; if (in_array($r['offer'], ['shot', 'shot_wait'], true)) $k['shot']++; }
            if ($r['offer'] === 'check') $k['waiting']++;
            $rows[] = ['parcel_id' => (int)$r['parcel_id'], 'phone' => (string)$r['phone'], 'name' => (string)($r['name'] ?? ''), 'order' => (string)$r['order_name'], 'rating' => (int)$r['rating'], 'at' => substr((string)$r['at'], 0, 16),
                'action' => (string)$r['action'], 'ticket' => (string)$r['ticket'], 'offer' => (string)$r['offer'], 'reminded' => $r['reminded_at'] ? substr((string)$r['reminded_at'], 0, 16) : '', 'shot_at' => $r['shot_at'] ? substr((string)$r['shot_at'], 0, 16) : '',
                'media_id' => (string)($r['shot_media'] ?? ''), 'verdict' => (string)($r['verdict'] ?? ''), 'reward_id' => (int)$r['reward_id']];
        }
        json_response(['rows' => $rows, 'kpi' => $k, 'cfg' => $cfg, 'mgr' => $role['mgr']]);
    }
    case 'bd.cx_rw_review_ok': {   // staff looked at the screenshot: give the gift (or say no)
        $post(); $needTeam(); $q = $pdo->prepare("SELECT * FROM wa_review_rating WHERE parcel_id = ?"); $q->execute([(int)($in['parcel_id'] ?? 0)]); $r = $q->fetch(); if (!$r) json_response(['error' => 'Rating not found.'], 404);
        if ((int)$r['reward_id'] > 0) json_response(['error' => 'The gift was already given for this order.'], 409);
        if (!empty($in['reject'])) { $pdo->prepare("UPDATE wa_review_rating SET offer = 'no', verdict = ? WHERE parcel_id = ?")->execute([mb_substr('Rejected by ' . $meName . ': ' . trim((string)($in['why'] ?? 'not a valid review')), 0, 200), (int)$r['parcel_id']]); json_response(['ok' => true, 'message' => 'Marked as not valid.']); }
        $res = function_exists('wr_reward_for_review') ? wr_reward_for_review($pdo, $r, 'Approved by ' . $meName, $me) : ['error' => 'Review module is not loaded.'];
        if (!empty($res['error'])) json_response(['error' => $res['error']], 422);
        json_response(['ok' => true, 'message' => 'Free ' . $cfg['review_ml'] . ' ml added. Code ' . $res['code'] . '. The customer was told.']);
    }
    case 'bd.cx_rw_review_skip': {
        $post(); $needTeam(); $pdo->prepare("UPDATE wa_review_rating SET offer = 'no' WHERE parcel_id = ? AND reward_id = 0")->execute([(int)($in['parcel_id'] ?? 0)]); json_response(['ok' => true, 'message' => 'Left out.']);
    }
    }
}

}   // function_exists guard
