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
    try {
        $q = $pdo->prepare("SELECT m.number_id, m.phone FROM wa_messages m WHERE m.dir = 'out' AND m.mtype = 'template' AND m.status <> 'failed' AND m.ts >= ?
            AND NOT EXISTS (SELECT 1 FROM wa_messages i WHERE i.phone = m.phone AND i.dir = 'in' AND i.ts <= m.ts AND i.ts >= DATE_SUB(m.ts, INTERVAL 24 HOUR))");
        $q->execute([$since]);
        foreach ($q->fetchAll() as $r) { $by[(int)$r['number_id']][(string)$r['phone']] = 1; $all[(string)$r['phone']] = 1; }
    } catch (Throwable $e) { }
    return ['by' => array_map('count', $by), 'all' => count($all)];
}

}
