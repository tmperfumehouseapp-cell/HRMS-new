<?php
/**
 * changes.php - "Request a change": the owner says what he wants (by voice or text), Claude Code builds it, he approves, it deploys.
 *
 *   owner / assistant  ->  chg.create  ->  a GitHub Issue with the label "claude-build"
 *   GitHub Action (.github/workflows/claude-build.yml) reads the issue, builds the change on its own branch, checks it, opens a Pull Request
 *   Admin opens Admin -> Changes, sees the Pull Request, taps "Approve & deploy"  ->  chg.approve  ->  merges it  ->  the deploy workflow uploads it
 *
 * GitHub is the record (issues and pull requests); nothing is stored here. Only the Admin can use it.
 * Needs two things (see the Changes page, it tells you what is missing):
 *   1) in config.php:  define('GITHUB_TOKEN', '...');   (a fine-grained token for THIS repository only: Contents, Issues, Pull requests = read & write)
 *      and optionally  define('GITHUB_REPO', 'owner/name');
 *   2) in GitHub -> Settings -> Secrets and variables -> Actions: a secret ANTHROPIC_API_KEY
 *
 * Actions: chg.status  chg.create  chg.list  chg.approve  chg.close
 * PHP 7.4 safe. Loaded by api.php for chg. actions.
 */
if (!isset($pdo) || !function_exists('json_response')) { return; }

function chg_repo() { $r = trim((string)tmph_cfg('GITHUB_REPO', 'jaimin69-tm/HRMS')); return preg_match('#^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+$#', $r) ? $r : ''; }
function chg_token() { return trim((string)tmph_cfg('GITHUB_TOKEN', '')); }
function chg_ready() { return chg_token() !== '' && chg_repo() !== ''; }

/** one GitHub API call. Returns [http status, decoded json or null, curl error]. */
function chg_gh($method, $path, $body = null) {
    $ch = curl_init('https://api.github.com' . $path);
    $h = ['Accept: application/vnd.github+json', 'Authorization: Bearer ' . chg_token(), 'User-Agent: HRMS-changes', 'X-GitHub-Api-Version: 2022-11-28'];
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 25, CURLOPT_CONNECTTIMEOUT => 8];
    if ($body !== null) { $o[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE); $h[] = 'Content-Type: application/json'; }
    $o[CURLOPT_HTTPHEADER] = $h; curl_setopt_array($ch, $o);
    $out = curl_exec($ch); $err = curl_error($ch); $st = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    return [$st, $out === false ? null : json_decode($out, true), $err];
}

/** create the request as an Issue (label claude-build starts the robot). Returns [ok, message, issue number]. */
function chg_create($title, $details, $by) {
    if (!chg_ready()) return [false, 'Changes are not connected yet: add GITHUB_TOKEN to config.php (see Admin → Changes).', 0];
    $title = mb_substr(trim((string)$title), 0, 120); $details = trim((string)$details);
    if (mb_strlen($title) < 4) return [false, 'Give the change a short title.', 0];
    $r = chg_repo();
    chg_gh('POST', "/repos/$r/labels", ['name' => 'claude-build', 'color' => '6d5dfc', 'description' => 'Built by Claude Code after the owner asked in HRMS']);   // 422 = it exists already: fine
    $body = $details . "\n\n---\nAsked by **" . mb_substr((string)$by, 0, 60) . "** in the HRMS (Admin → Changes).\nFollow `empmanagment/house_rules.md`. When you are done, open a pull request that says `Closes #<this issue>` and explains in plain words what changed and what to look at.";
    list($st, $j, $err) = chg_gh('POST', "/repos/$r/issues", ['title' => $title, 'body' => $body, 'labels' => ['claude-build']]);
    if ($st !== 201 || !is_array($j)) return [false, 'GitHub said no (' . ($st ?: $err) . '). ' . (is_array($j) && !empty($j['message']) ? (string)$j['message'] : ''), 0];
    return [true, 'Sent to the builder as #' . (int)$j['number'] . '. It appears in Admin → Changes; when the pull request is ready you approve it there.', (int)$j['number']];
}

/** every request with its pull request and a plain state: building / review / deployed / closed */
function chg_list() {
    $r = chg_repo(); $out = [];
    list($st, $issues) = chg_gh('GET', "/repos/$r/issues?labels=claude-build&state=all&per_page=25&sort=created&direction=desc");
    if ($st !== 200 || !is_array($issues)) return [false, 'GitHub said no (' . $st . ').'];
    list($st2, $prs) = chg_gh('GET', "/repos/$r/pulls?state=all&per_page=60&sort=created&direction=desc");
    $prs = ($st2 === 200 && is_array($prs)) ? $prs : [];
    foreach ($issues as $i) {
        if (isset($i['pull_request'])) continue;
        $n = (int)$i['number']; $pr = null;
        foreach ($prs as $p) { if (preg_match('/(?:close[sd]?|fix(?:e[sd])?|resolve[sd]?)\s+#' . $n . '\b/i', (string)($p['body'] ?? '')) || preg_match('/(^|[-\/])' . $n . '($|[-\/])/', (string)($p['head']['ref'] ?? ''))) { $pr = $p; break; } }
        $state = 'building';
        if ($pr) { if (!empty($pr['merged_at'])) $state = 'deployed'; elseif (($pr['state'] ?? '') === 'closed') $state = 'closed'; else $state = 'review'; }
        elseif (($i['state'] ?? '') === 'closed') $state = 'closed';
        $row = ['issue' => $n, 'title' => (string)$i['title'], 'created' => (string)$i['created_at'], 'state' => $state, 'issue_url' => (string)$i['html_url'], 'pr' => null];
        if ($pr) {
            $row['pr'] = ['number' => (int)$pr['number'], 'url' => (string)$pr['html_url'], 'title' => (string)$pr['title'], 'body' => mb_substr((string)($pr['body'] ?? ''), 0, 1500)];
            if ($state === 'review') { list($s3, $d) = chg_gh('GET', "/repos/$r/pulls/" . (int)$pr['number']); if ($s3 === 200 && is_array($d)) { $row['pr']['files'] = (int)($d['changed_files'] ?? 0); $row['pr']['mergeable'] = $d['mergeable'] ?? null; $row['pr']['mergeable_state'] = (string)($d['mergeable_state'] ?? ''); } }
        }
        $out[] = $row;
    }
    return [true, $out];
}

$__chgAct = (string)($action ?? '');
if (strpos($__chgAct, 'chg.') !== 0) return;
$auth = require_auth();
if ((string)($auth['role'] ?? '') !== 'Admin') json_response(['error' => 'Changes are for the Admin only.'], 403);
$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$in = $isPost ? json_input() : [];
$by = (string)($auth['username'] ?? 'admin');

switch ($__chgAct) {
    case 'chg.status':
        json_response(['ok' => true, 'token' => chg_token() !== '', 'repo' => chg_repo(), 'ready' => chg_ready()]);
    case 'chg.create': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        list($ok, $msg, $n) = chg_create($in['title'] ?? '', $in['details'] ?? '', $by);
        if (!$ok) json_response(['error' => $msg], 422);
        json_response(['ok' => true, 'message' => $msg, 'issue' => $n]);
    }
    case 'chg.list': {
        if (!chg_ready()) json_response(['ok' => true, 'ready' => false, 'items' => []]);
        list($ok, $items) = chg_list();
        if (!$ok) json_response(['error' => $items], 502);
        json_response(['ok' => true, 'ready' => true, 'items' => $items]);
    }
    case 'chg.approve': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        if (!chg_ready()) json_response(['error' => 'Changes are not connected yet.'], 422);
        $pr = (int)($in['pr'] ?? 0);
        list($ok, $items) = chg_list(); if (!$ok) json_response(['error' => $items], 502);
        $mine = false; foreach ($items as $it) { if ($it['pr'] && $it['pr']['number'] === $pr && $it['state'] === 'review') $mine = true; }
        if (!$mine) json_response(['error' => 'That is not a change waiting for your approval.'], 422);   // only pull requests made for a claude-build request can be approved here
        list($st, $j) = chg_gh('PUT', '/repos/' . chg_repo() . "/pulls/$pr/merge", ['merge_method' => 'merge']);
        if ($st !== 200) json_response(['error' => 'Could not merge: ' . (is_array($j) && !empty($j['message']) ? (string)$j['message'] : 'HTTP ' . $st)], 422);
        if (function_exists('bd_log')) { try { bd_log('change', (string)$pr, 'approved and merged by ' . $by, $by); } catch (Throwable $e) {} }
        json_response(['ok' => true, 'message' => 'Approved. It is going live now (the upload takes a few minutes). Press Ctrl+F5 after.']);
    }
    case 'chg.close': {
        if (!$isPost) json_response(['error' => 'Method not allowed.'], 405);
        if (!chg_ready()) json_response(['error' => 'Changes are not connected yet.'], 422);
        $n = (int)($in['issue'] ?? 0); $pr = (int)($in['pr'] ?? 0);
        list($ok, $items) = chg_list(); if (!$ok) json_response(['error' => $items], 502);
        $okIssue = false; foreach ($items as $it) { if ($it['issue'] === $n) $okIssue = true; }
        if (!$okIssue) json_response(['error' => 'That is not one of your change requests.'], 422);
        if ($pr) chg_gh('PATCH', '/repos/' . chg_repo() . "/pulls/$pr", ['state' => 'closed']);
        chg_gh('PATCH', '/repos/' . chg_repo() . "/issues/$n", ['state' => 'closed']);
        json_response(['ok' => true, 'message' => 'Closed.']);
    }
}
json_response(['error' => 'Unknown action.'], 404);
