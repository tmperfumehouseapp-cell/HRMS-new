<?php
// includes/nav.php — ONE shared top menu for every POS page (same pills as the HRMS Store tabs: white pills, black active).
// Usage (inside a page): require_once __DIR__ . '/includes/nav.php';  then call  pos_nav('inventory');
//   $before  = optional extra HTML placed before the pills (e.g. a date picker)
//   $refresh = optional JS for the Refresh pill (default: reload the page)
//   $full    = true -> also list Pending / Audit / Ledger / Barcodes / Users (Command Center uses this)
// On phones the pills collapse into a "Menu" button that opens a tidy grid; on desktop it is the normal pill row.
// Loads the NEWEST stylesheet it can find (assets/ui.css or ui.css) with its file time in the URL, so an upload
// always shows immediately — no stale browser copy, and it also works if the file was put in the wrong folder.
function pos_css_link(): string {
    $cands = ['assets/ui.css' => __DIR__ . '/../assets/ui.css', 'ui.css' => __DIR__ . '/../ui.css'];
    $best = ''; $bt = -1;
    foreach ($cands as $url => $path) { $t = @filemtime($path); if ($t !== false && $t > $bt) { $bt = $t; $best = $url; } }
    return $best === '' ? '' : '<link rel="stylesheet" href="' . $best . '?m=' . $bt . '">';
}

function pos_nav(string $active = '', string $before = '', string $refresh = 'location.reload();return false;', bool $full = false): void {
    static $script = false;
    $u = function_exists('current_user') ? (current_user() ?: []) : [];
    $admin = (($u['role'] ?? '') === 'admin');
    // the old POS is gone: only the Store orders (restock) pages are left
    $items = [['restock', 'restock.php', 'Plan & memos'], ['dispatch', 'restock_dispatch.php', 'Dispatch']];
    $label = 'Menu';
    foreach ($items as [$key, $href, $text]) { if ($key === $active) { $label = $text; break; } }
    if (!$script) echo pos_css_link();
    echo '<button class="tm-menu-btn" type="button" aria-label="Open menu" aria-expanded="false" onclick="tmMenu()"><span class="bars"></span>' . htmlspecialchars($label) . '</button>';
    echo '<nav class="tm-nav" id="tmNav">' . $before;
    echo '<a href="#" onclick="' . htmlspecialchars($refresh, ENT_QUOTES) . '">&#8635; Refresh</a>';
    foreach ($items as [$key, $href, $label]) {
        echo '<a href="' . $href . '"' . ($key === $active ? ' class="active"' : '') . '>' . $label . '</a>';
    }
    echo '<a href="logout.php">Logout</a></nav>';
    if (!$script) {
        $script = true;
        echo '<script>try{if(window.top!==window.self)document.documentElement.classList.add("in-hrms");}catch(e){document.documentElement.classList.add("in-hrms");}'
           . 'function tmMenu(f){var o=(typeof f==="boolean")?f:!document.body.classList.contains("tm-menu-open");document.body.classList.toggle("tm-menu-open",o);var b=document.querySelector(".tm-menu-btn");if(b)b.setAttribute("aria-expanded",o?"true":"false");}'
           . 'document.addEventListener("click",function(e){if(document.body.classList.contains("tm-menu-open")&&!e.target.closest(".tm-nav,.tm-menu-btn"))tmMenu(false);});'
           . 'document.addEventListener("keydown",function(e){if(e.key==="Escape")tmMenu(false);});</script>';
    }
}