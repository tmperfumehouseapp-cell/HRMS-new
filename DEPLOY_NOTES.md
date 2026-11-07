# HRMS update — Sales Control Tower · Message Doctor · proof at allocation · Diwali theme · My Sales home

## How to install (2 minutes)
1. Upload everything in this zip to the same folder as your current HRMS on the server, overwrite when asked.
   - `api.php` here is the new backend (it replaces the old api.php). Your `config.php` is NOT in the zip — keep yours.
2. Hard-refresh the browser (Ctrl+Shift+R / clear cache once on phones).
3. Roles page: tick the new **"Control Tower"** permission for any role besides Admin that should open the page (HR etc.).
4. Open **Sales Control Tower** (menu, under Dashboard) -> Settings: check goals, meet-admin triggers, "who can press Resolved" (add Pradeep and others), Diwali theme (Auto between 20 Oct and 15 Nov, or On / Off).
No database steps: every new table / column is created automatically the first time it is needed.

## What is new
- **My Sales home** (default landing for sales people): cheering hero, "ting ting" nudges, target + 65% code goal, flash-offer card, price chart, Allocate pop-up, and the new mission feed (ticket ladder, DG+LG+WH mix, proof, community, learning idea, outcome rating vs the top 3, "meet admin").
- **Proof at allocation** (Employee role): the first screenshot (any file type) must be attached before an order can be allocated; call recording ladder (1st-2nd accepted, 3rd alert, 4th blocked, fluent people waived); repeated screenshots handled gently, then "meet admin". Pop-up every ~2.5 h for queries/orders without proof (limited "Later").
- **Sales Control Tower** (Admin / HR / named people): goals vs reality for the 10AM-10AM day, alerts with Resolved buttons, meet-admin list, people table; runs itself every 5 min (no cron needed) and sends red alerts as push to admin.
- **Message Doctor**: reads all chat screenshots / calls, lists messages, offers, flash offers and skill sections that are NOT working and those that work very well; "I fixed it" -> keeps watching, tells whether it improved; if nothing is changed for 3 days Admin gets an alert.
- **Benchmarks & Ideas**: top-3 average per measure, outcome rating, daily learning ideas, adoption tracking, "rigid" notification.
- **Diwali theme** on every page (garland, diya, sparkles, gold accents); respects "reduce motion"; switch in Control Tower -> Settings.

## Good to know
- AI call-recording review still needs the Gemini key (Admin AI setup); recordings are saved and counted either way.
- Shopify order colour data (DG/LG/WH) fills as orders are re-synced / opened; older orders show as "other" until then.
- Rollback: re-upload your previous api.php and pages.

## Files in this zip
api.php, control_tower.html (new), my_sales.html, order_allocation.html, commission.html, orders.html, index.html and the other pages,
assets/js/{layout,convo,sales_home,diwali,flash_offer,ml_slab,ml_slab_rules,api,...}.js, assets/css/{style,diwali}.css
