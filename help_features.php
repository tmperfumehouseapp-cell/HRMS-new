<?php
// help_features.php — the "Feature check" of the Help page.
// READ-ONLY: it reads your page files and the PHP files and says, for every button / link / feature,
// whether it can work (usable), looks dead (not usable), could be removed, or is missing on the server.
// It never clicks anything and never changes anything. Your own "new requirements" are kept in Help's small database.
require_once __DIR__ . '/help_lib.php';

/** Every quoted word.word that any PHP file of the backend mentions (a lenient list of server actions). */
function hlpf_server_actions(): array {
    static $cache = null; if ($cache !== null) return $cache;
    $all = []; $cases = [];
    foreach (glob(__DIR__ . '/*.php') ?: [] as $f) {
        if (filesize($f) > 6000000) continue;
        $src = (string)@file_get_contents($f);
        if (preg_match_all('/[\'"]([a-z][a-z0-9_]*\.[a-z0-9_.]+)[\'"]/i', $src, $m)) foreach ($m[1] as $n) $all[$n] = 1;
        if (preg_match_all('/case\s+[\'"]([a-z][a-z0-9_]*\.[a-z0-9_.]+)[\'"]\s*:/i', $src, $m)) foreach ($m[1] as $n) $cases[$n] = 1;
    }
    return $cache = ['all' => $all, 'cases' => $cases];
}

function hlpf_read(string $path, int $max = 3000000): string { return (is_file($path) && filesize($path) < $max) ? (string)@file_get_contents($path) : ''; }

/** All the script text a page runs: its own inline scripts + every local script it loads (layout.js, api.js ...). */
function hlpf_scripts(string $html, string $root): string {
    $js = '';
    if (preg_match_all('#<script\b[^>]*>(.*?)</script>#is', $html, $m)) $js .= implode("\n", $m[1]);
    if (preg_match_all('#<script\b[^>]*\bsrc=["\']([^"\'?]+)#i', $html, $m)) foreach (array_unique($m[1]) as $src) {
        if (preg_match('#^(https?:)?//#', $src)) continue;
        $js .= "\n" . hlpf_read($root . '/' . ltrim($src, '/'));
    }
    return $js;
}

function hlpf_attr_names(string $tagHtml): array {
    $a = [];
    if (preg_match_all('/([a-zA-Z_:][\w:.\-]*)\s*(?:=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+)))?/', $tagHtml, $m, PREG_SET_ORDER)) {
        foreach ($m as $x) { $k = strtolower($x[1]); if ($k === 'button' || $k === 'a' || $k === 'input') continue; $a[$k] = (($x[2] ?? '') !== '' ? $x[2] : (($x[3] ?? '') !== '' ? $x[3] : ($x[4] ?? ''))); }
    }
    foreach (['id', 'class'] as $nm) if (!isset($a[$nm]) && preg_match('/\b' . $nm . '\s*=\s*"([^"]*)"/', $tagHtml, $nmm)) $a[$nm] = $nmm[1];
    if (preg_match_all('/\b(data-[a-z][a-z0-9-]*)\s*=/i', $tagHtml, $dm)) foreach ($dm[1] as $dn) if (!isset($a[strtolower($dn)])) $a[strtolower($dn)] = '';
    // onclick='fn(${x}, "…", '…')' has quotes inside quotes, which the loop above cannot split: take the function name straight from the text.
    if (!isset($a['onclick']) && preg_match('/\bonclick\s*=\s*[\'"]\s*([A-Za-z_$][\w$.]*\s*\()/', $tagHtml, $om)) $a['onclick'] = $om[1];
    return $a;
}

/** Judge one button / link. $attrs = lowercase attributes, $tpl = it was found inside a JavaScript template (its own text also counts once). */
function hlpf_judge(string $tag, array $attrs, string $label, string $js, string $root, bool $tpl): array {
    $min = $tpl ? 2 : 1;
    $cnt = fn(string $needle) => $needle === '' ? 0 : substr_count($js, $needle);
    if (isset($attrs['onclick']) && trim($attrs['onclick']) !== '') {
        $oc = trim($attrs['onclick']);
        if (preg_match('/^\s*(?:return\s+)?(?:this|event|location|history|window|document|alert|confirm|api|if|void|navigator|BD|B2|WS|CQ|Q|\$)\b/', $oc)) return ['ok', ''];
        if (preg_match('/^\s*(?:return\s+)?([A-Za-z_$][\w$]*)\s*\(/', $oc, $fm)) {
            $fn = preg_quote($fm[1], '/');
            if (preg_match('/function\s+' . $fn . '\b|\b' . $fn . '\s*[:=]\s*(?:async\s*)?(?:function|\(|[A-Za-z_$][\w$]*\s*=>)|window\.' . $fn . '\s*=|\b(?:const|let|var)\s+' . $fn . '\b/', $js)) return ['ok', ''];
            return ['dead', 'Its click calls "' . $fm[1] . '()", which is not defined anywhere on the page'];
        }
        return ['ok', ''];
    }
    if ($tag === 'a') {
        $href = trim((string)($attrs['href'] ?? ''));
        if (strlen($href) > 1 && $href[0] === '#') return ['ok', ''];      // an in-page link / hash route (#setup): the page shows that part itself
        if ($href !== '' && $href[0] !== '#' && stripos($href, 'javascript:') !== 0) {
            if (preg_match('#^(https?:|mailto:|tel:|sms:|whatsapp:|//|/|data:|blob:)#i', $href) || strpos($href, '${') !== false || strpos($href, "'+") !== false || strpos($href, '"+') !== false) return ['ok', ''];
            $file = preg_replace('/[?#].*$/', '', $href);
            if ($file === '') return ['ok', ''];
            if (preg_match('/\.(html?|php)$/i', $file) && !is_file($root . '/' . $file) && !is_file($root . '/empmanagment/' . $file)) return ['dead', 'Links to "' . $file . '" but that file does not exist'];
            return ['ok', ''];
        }
    }
    if ($tag === 'button' || ($tag === 'input' && in_array(strtolower($attrs['type'] ?? ''), ['submit', 'button'], true))) {
        if (strtolower($attrs['type'] ?? ($tag === 'button' ? 'submit' : '')) === 'submit' && !$tpl && $tag === 'button' && !isset($attrs['id']) && !isset($attrs['class'])) return ['ok', ''];
    }
    $generic = ['btn', 'pill', 'sm', 'go', 'pri', 'primary', 'secondary', 'dark', 'danger', 'tag', 'chip', 'on', 'active', 'ok', 'bad', 'warn', 'alt', 'mini', 'bd-btn', 'card', 'row', 'close', 'x'];
    $id = trim((string)($attrs['id'] ?? ''));
    if ($id !== '' && ($cnt($id) >= $min + 0 && ($cnt("'$id'") + $cnt("\"$id\"") + $cnt("`$id`") + $cnt('#' . $id) + $cnt('getElementById') * 0) >= 1)) return ['ok', ''];
    foreach ($attrs as $k => $v) {
        if (strpos($k, 'data-') === 0) {
            $camel = lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', substr($k, 5)))));
            // how code reaches a data attribute: [data-x] selectors, dataset.x, dataset['x'], getAttribute('data-x'). (The markup "data-x=" itself is not counted.)
            $refs = $cnt('[' . $k) + $cnt('dataset.' . $camel) + $cnt("dataset['" . $camel . "']") + $cnt('dataset["' . $camel . '"]') + $cnt("'" . $k . "'") + $cnt('"' . $k . '"');
            if ($refs >= 1) return ['ok', ''];
        }
    }
    foreach (preg_split('/\s+/', trim((string)($attrs['class'] ?? ''))) as $c) {
        if ($c === '') continue;
        // a class the page's code looks up with a selector (querySelector('.go'), closest('.go'), matches('.go')) — even a plain word like "go" is then wired
        if (preg_match('/(querySelector(?:All)?|closest|matches)\(\s*[\'"`][^\'"`]*\.' . preg_quote($c, '/') . '(?![\w-])/', $js)) return ['ok', ''];
        if (preg_match('/classList\.contains\(\s*[\'"`]' . preg_quote($c, '/') . '[\'"`]/', $js)) return ['ok', ''];
        if (in_array($c, $generic, true)) continue;
        if (($cnt('.' . $c) + $cnt("'" . $c . "'") + $cnt('"' . $c . '"') + $cnt('classList.contains(\'' . $c)) >= ($tpl ? 1 : 1)) return ['ok', ''];
    }
    if ($tag === 'a') {
        if ($id === '' && trim((string)($attrs['href'] ?? '#')) === '#' && $label !== '') return ['dead', 'A link that goes nowhere ("#") and nothing on the page reacts to it'];
        return ['check', 'No code found that reacts to this link'];
    }
    if (array_key_exists('disabled', $attrs) && $id === '') return ['dead', 'Always disabled and nothing turns it on'];
    return ['check', 'No code found that reacts to this button (it may be wired in a way this scan cannot see)'];
}

function hlpf_scan(): array {
    @set_time_limit(150);
    $root = hlp_root(); $t0 = microtime(true);
    $menu = []; foreach (hlp_pages() as $p) $menu[$p['file']] = $p;
    $srv = hlpf_server_actions(); $defined = $srv['all'];
    $files = array_values(array_filter(scandir($root) ?: [], fn($f) => preg_match('/\.html?$/i', $f)));
    sort($files);

    // everything that could link to a page: all pages, all site scripts, the PHP files
    $linkSrc = '';
    foreach ($files as $f) $linkSrc .= "\n" . hlpf_read($root . '/' . $f);
    foreach (glob($root . '/assets/js/*.js') ?: [] as $f) $linkSrc .= "\n" . hlpf_read($f);
    $linkSrc .= "\n" . hlpf_read($root . '/workspaces.js') . "\n" . hlpf_read($root . '/sw.js') . "\n" . hlpf_read($root . '/manifest.json');
    foreach (glob(__DIR__ . '/*.php') ?: [] as $f) { if (filesize($f) < 6000000) $linkSrc .= "\n" . hlpf_read($f, 6000000); }

    $pages = []; $usedAll = []; $tot = ['pages' => 0, 'buttons' => 0, 'usable' => 0, 'dead' => 0, 'check' => 0];
    $removable = []; $missing = [];
    foreach ($files as $file) {
        $html = hlpf_read($root . '/' . $file); if ($html === '') continue;
        $js = hlpf_scripts($html, $root);
        $items = []; $seen = [];
        // 1. buttons and links written in the page itself
        $dom = new DOMDocument(); libxml_use_internal_errors(true);
        @$dom->loadHTML('<?xml encoding="UTF-8">' . $html); libxml_clear_errors();
        $xp = new DOMXPath($dom);
        foreach ($xp->query('//button | //a | //input[@type="button" or @type="submit"] | //*[@onclick and not(self::button) and not(self::a) and not(self::input)]') as $n) {
            $attrs = []; foreach ($n->attributes as $at) $attrs[strtolower($at->nodeName)] = $at->nodeValue;
            $tag = strtolower($n->nodeName);
            $label = trim(preg_replace('/\s+/', ' ', $n->textContent)); if ($label === '') $label = trim((string)($attrs['aria-label'] ?? $attrs['title'] ?? $attrs['value'] ?? ($attrs['id'] ?? '')));
            $label = mb_substr($label, 0, 48);
            if ($tag === 'a' && !isset($attrs['onclick']) && ($attrs['class'] ?? '') !== '' && preg_match('/\bnav|tab\b/', $attrs['class']) && isset($attrs['href'])) { /* normal links are judged below */ }
            [$st, $why] = hlpf_judge($tag, $attrs, $label, $js, $root, false);
            $items[] = ['tag' => $tag, 'label' => $label, 'st' => $st, 'why' => $why, 'tpl' => 0];
            $key = $tag . '|' . $label . '|' . ($attrs['onclick'] ?? '') . '|' . ($attrs['href'] ?? '');
            $seen[$key] = ($seen[$key] ?? 0) + 1;
        }
        // 2. buttons built by the page's JavaScript (templates)
        if (preg_match_all('#<script\b[^>]*>(.*?)</script>#is', $html, $sm)) {
            foreach ($sm[1] as $block) {
                if (preg_match_all('#<(button|a)\b((?:[^>"\']|"[^"]{0,400}"|\'[^\']{0,400}\')*)>([^<]{0,60})#i', $block, $tm, PREG_SET_ORDER)) {      // a > inside a quoted attribute (class="${n > 0 ? …}") does not end the tag
                    foreach (array_slice($tm, 0, 80) as $t) {
                        $attrs = hlpf_attr_names($t[2]); $label = mb_substr(trim(preg_replace('/\s+|\$\{[^}]*\}/', ' ', $t[3])), 0, 48);
                        if (preg_match('/[{}"]|\'\s*[+:?]|\$\{/', $label)) $label = '(label built by the page)';      // the text of the button is made by code: keep the button, name it like this
                        if (strtolower($t[1]) === 'a' && strpos($t[2], '=') === false) continue;           // "<a …" inside plain text with no attribute at all is prose, not a link
                        if ($label === '') $label = $attrs['aria-label'] ?? ($attrs['title'] ?? ($attrs['id'] ?? ''));
                        [$st, $why] = hlpf_judge(strtolower($t[1]), $attrs, $label, $js, $root, true);
                        $items[] = ['tag' => strtolower($t[1]), 'label' => $label, 'st' => $st, 'why' => $why, 'tpl' => 1];
                    }
                }
            }
        }
        // server actions this page calls
        $used = [];
        if (preg_match_all('/(?:api\.(?:get|post|upload|request|buildUrl)|\bcall|\bB2\.(?:get|post)|\bget|\bpost|\bbuildUrl)\(\s*[\'"`]([a-z][a-z0-9_]*\.[a-z0-9_.]+)[\'"`]/i', $js, $um)) foreach ($um[1] as $a) $used[$a] = 1;
        foreach (array_keys($used) as $a) { $usedAll[$a] = 1; }
        $miss = array_values(array_filter(array_keys($used), fn($a) => !isset($defined[$a]) && substr($a, -1) !== '_' && substr($a, -1) !== '.'));   // 'wa.camp_' is the start of a name built in code, not a missing action
        sort($miss);
        // summary for the page
        $dead = array_values(array_filter($items, fn($i) => $i['st'] === 'dead'));
        $chk = array_values(array_filter($items, fn($i) => $i['st'] === 'check'));
        $okn = count($items) - count($dead) - count($chk);
        $dups = []; foreach ($seen as $k => $n) if ($n >= 2) { $p = explode('|', $k); if ($p[1] !== '' && !preg_match('/^(\d+|×|✕|x|\+|-|←|→)$/u', $p[1]) && $n >= 3) $dups[] = $p[1] . ' (x' . $n . ')'; }
        $inMenu = isset($menu[$file]);
        $refs = substr_count($linkSrc, $file) - substr_count($html, $file);   // every mention anywhere, minus the page naming itself
        $isTest = (bool)preg_match('/(^|_)(test|snippet|diag|3d_ui|old|backup|copy|tmp)(_|\.|$)/i', $file);
        $why = '';
        if (!$inMenu && $refs <= 0 && !in_array($file, ['index.html', 'reset.html', 'Maintenance.html', 'hub.html', 'help.html'], true)) $why = 'Not in the menu and no other page, script or PHP file links to it';
        elseif ($isTest && !$inMenu) $why = 'Looks like a test / helper page (' . $file . ') and is not in the menu';
        if ($why !== '') $removable[] = ['file' => $file, 'label' => $menu[$file]['label'] ?? $file, 'why' => $why];
        if ($why === '') foreach ($miss as $a) $missing[] = ['file' => $file, 'action' => $a];
        $unusedPage = $why !== '';                                   // nobody can open it: its buttons are not features anyone uses (it is listed under "Removable")
        $state = $unusedPage ? 'unused' : (($dead || $miss) ? 'bad' : ($chk ? 'weak' : 'good'));
        $pages[] = ['file' => $file, 'label' => $menu[$file]['label'] ?? $file, 'in_menu' => $inMenu, 'buttons' => count($items), 'ok' => $okn,
            'dead' => array_slice($dead, 0, 25), 'check' => array_slice($chk, 0, 25), 'dups' => array_slice($dups, 0, 8), 'missing_api' => $miss, 'state' => $state];
        $tot['pages']++; $tot['buttons'] += count($items); $tot['usable'] += $okn;
        if (!$unusedPage) { $tot['dead'] += count($dead); $tot['check'] += count($chk); }
    }
    // server actions no page ever calls (candidates to remove) — cron / webhook / internal ones are left out
    $unused = [];
    foreach (array_keys($srv['cases']) as $a) {
        if (isset($usedAll[$a]) || preg_match('/(cron|tick|hook|webhook|callback|probe|internal|cache|diag|test)/i', $a)) continue;
        if (substr_count($linkSrc, "'" . $a . "'") + substr_count($linkSrc, '"' . $a . '"') > 1) continue;   // used by some script that builds the call in another way
        $unused[] = $a;
    }
    sort($unused);
    usort($pages, fn($a, $b) => ['bad' => 0, 'weak' => 1, 'unused' => 2, 'good' => 3][$a['state']] <=> ['bad' => 0, 'weak' => 1, 'unused' => 2, 'good' => 3][$b['state']] ?: strcmp($a['file'], $b['file']));
    return ['at' => time(), 'secs' => round(microtime(true) - $t0, 1), 'totals' => $tot + ['removable' => count($removable), 'missing_api' => count($missing), 'unused_api' => count($unused)],
        'pages' => $pages, 'removable' => $removable, 'missing_api' => array_slice($missing, 0, 200), 'unused_api' => array_slice($unused, 0, 150)];
}

function hlpf_get(bool $force): array {
    $c = hlp_kv_get('features_scan', null);
    if (!$force && is_array($c) && !empty($c['at']) && time() - (int)$c['at'] < 3600) return $c;
    $r = hlpf_scan(); hlp_kv_set('features_scan', $r); return $r;
}

/* ───── your own "new requirement" list ───── */
function hlpf_reqs(): array { $r = hlp_kv_get('requirements', []); return is_array($r) ? $r : []; }
function hlpf_req_save(array $r): void { hlp_kv_set('requirements', array_values(array_slice($r, -200))); }
