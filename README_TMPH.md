# TMPH – personal AI assistant (Google Gemini, free tier)

TMPH is a chat panel (button at bottom-right) inside the HRMS. The browser never sees the Gemini key:
it talks to `api.php?action=ai.chat`, and PHP talks to Google.

## 1. Get a free key
1. Open Google AI Studio (aistudio.google.com) and sign in with a Google account.
2. Click "Get API key" -> create a key. Copy it.

## 2. Put the key on the server (NOT in any .js/.html file)
Open `config.php` on Hostinger (File Manager) and add:

    define('GEMINI_API_KEY', 'paste-your-key-here');

(Or set an environment variable called GEMINI_API_KEY if your host supports it.)
Do not use a `.env` file on Hostinger: files in the web folder can be downloaded by anyone.
`.gitignore` is included so `config.php` / `.env` never go to Git.

## 3. Who can use it (optional, in config.php)
By default only role Admin can see TMPH. Add people:

    define('TMPH_ALLOWED_USERS', ['rahul', 'priya']);   // usernames (or user ids)
    define('TMPH_ALLOWED_ROLES', ['Admin']);            // whole roles

Other optional settings (defaults shown):

    define('TMPH_MODEL', 'gemini-3.8-flash');
    define('TMPH_USER_DAILY', 20);     // messages per person per day
    define('TMPH_TOTAL_DAILY', 200);   // messages per day for everyone (protects the free quota)
    define('TMPH_IP_PER_MIN', 20);     // messages per minute per IP
    define('TMPH_MAX_CHARS', 2000);    // longest message
    define('TMPH_STREAM', true);       // set false if replies arrive all at once / host buffers streams

People not allowed never see the button. Changes in config.php can take a few seconds to apply (PHP cache).

## 4. Upload (3 files)
- `api_alloc.php`  -> upload to the server as `api.php`
- `assets/js/tmph.js`  (new file)
- `assets/js/layout.js`  (changed: loads TMPH on every logged-in page; phone Back button closes the panel)

The `tmph_log` table (used for the limits) is created automatically on first use.
Hard-refresh the browser once (Ctrl+F5).

## 5. Run locally
Same as the rest of the HRMS: PHP + MySQL, put the key in the local `config.php`, open any page, log in as an allowed user.

## 6. Live HRMS data (sales, attendance, employees, retail)
TMPH can answer "today's total sell", "who is absent today", "find employee Amit", "retail shop sales this month".
- It reads the data through the SAME API actions the HRMS pages use, with the logged-in person's own login, so it can never show
  more than that person can already open. A role also needs the matching module ticked on the Roles page
  (Shopify Orders, Attendance, Employees, Retail Shops). Without it, TMPH is a normal chat for that person.
- It is read-only. It returns totals and employee names / departments / designations. It never returns salaries, phone numbers,
  addresses or customer names.
- IMPORTANT - privacy: the numbers and names TMPH reads are sent to Google inside the request. On the FREE Gemini tier Google may
  use them to improve its products. To stop that, enable billing (paid tier) for the project that owns the key in Google AI Studio.
- One question still counts as one message on the daily allowance, even when TMPH reads several things.
- Turn it off any time: `define('TMPH_TOOLS', false);` in config.php.
- If the data never arrives (the server cannot call its own address), add the full address of api.php:
  `define('TMPH_SELF_URL', 'https://fourpirates.space/empmanagment/api.php');`
- Backup models are used automatically when the main one is busy: `define('TMPH_FALLBACK_MODELS', [...]);`

## Good to know
- Free Gemini limits are small and can change; when reached, users see "Free limit reached, please try again later."
- On the free tier Google may use the chats to improve its products. Do not type private HR data (salaries, Aadhaar, etc.).
- Without section 6 (or for people whose role has no access) TMPH is a general assistant.
- Stopping a reply still counts as one message.
- Chat history is saved only in that person's browser (localStorage); "Clear chat" deletes it.
- Not tested against the real Gemini service (no key/network in my test environment); tested with a fake Gemini server and a real browser.s