<?php
/**
 * Copy this file to config.php and fill in real values.
 * config.php is gitignored — never commit real keys.
 */
define('MAIL_HOST', 'smtp.hostinger.com');
define('MAIL_PORT', 465);
define('MAIL_USER', 'your-mailbox@example.com');
define('MAIL_PASS', 'your-mailbox-password');
define('MAIL_FROM_NAME', 'HR Team');
define('APP_URL', 'https://your-domain.example');

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'your_db_name');
define('DB_USER', 'your_db_user');
define('DB_PASS', 'your_db_password');
define('APP_SECRET', 'generate-a-long-random-string');
define('API_KEY', 'optional-dev-api-key');

// define('ANTHROPIC_API_KEY', 'sk-ant-...');
// define('GEMINI_API_KEY', '...');
// define('WA_TOKEN', '...');
// define('WA_PHONE_ID', '...');
// define('WA_WABA_ID', '...');
// define('WA_APP_SECRET', '...');
// define('WA_VERIFY_TOKEN', '...');

// define('SHOPIFY_DOMAIN', 'your-store.myshopify.com');
// define('SHOPIFY_CLIENT_ID', 'your-client-id');
// define('SHOPIFY_CLIENT_SECRET', 'your-shopify-client-secret');

// Optional: keep the database connection open between requests (saves 10+ connects per page). Only turn this on when
// (number of PHP workers) is LOWER than the database's max connections - on a VPS: pm.max_children (PHP-FPM) < max_connections (MySQL).
// On shared hosting leave it off: every idle kept connection counts against the small per-user limit and makes refusals worse.
// define('DB_PERSISTENT', true);

// Request a change (Admin -> Changes): lets the Admin ask for new features that Claude Code builds and he approves.
//   define('GITHUB_TOKEN', 'github_pat_...');   // fine-grained token for THIS repository only: Contents, Issues, Pull requests = read & write
//   define('GITHUB_REPO',  'owner/name');       // optional, default jaimin69-tm/HRMS
// The assistant's strongest model and step budget (defaults: claude-opus-5-5, 8 steps; '' = use TMPH_MODEL):
//   define('TMPH_ASSISTANT_MODEL', 'claude-opus-5-5');
//   define('TMPH_MAX_STEPS', 8);

// Nightly review of the bots (Brain -> Suggestions). Make up a long random password (20+ characters) and put the SAME text
// into the GitHub secret LEARN_LOOP_KEY. Without this line the review is switched off.
//   define('LEARN_LOOP_KEY', 'long-random-text-here');
