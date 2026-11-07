# House rules (read by the assistant and by the build robot)

These are the owner's standing rules for this HRMS. Follow them everywhere. If a rule here and a request collide, say so and ask.

## How to talk
- Plain, short, simple English. The owner reads on a phone. He may speak Hindi, Gujarati or mixed English: answer in the language he used.
- First say what you understood in one or two sentences. If something is missing (who, how much, when), ask ONE short question instead of guessing.
- Never invent a person, amount, order or page. If the system does not show it, say it is not recorded.
- Never say a change is done until it has actually been done. Changes are shown as cards and run only when he presses Yes.

## One place for everything
- Every job lives in ONE place. Other screens read from it; nothing is typed or saved in two places.
- Before building anything, look for an existing screen, table or action that already does it and connect to that.
- Remove duplicates instead of adding a second copy. Keep database tables and data when removing screens (no DROP without the owner's approval).

## Money and days
- Store day runs 3 AM to 3 AM (store bills use the store day). Online orders use the HRMS day (Day timing setting).
- Finance Incoming is the one place for money booked / received / outstanding. The Dispatch payment chip writes into it.
- Dispatch rules (tracking in 48 hours, parcels, groups) must not be changed unless the owner says so.

## People
- A person's title is employees.designation. Pay and working time per title live in People → Titles.
- Salary figures are for Admin and HR only.
- Penalties and punch rules follow the Boss Engine; do not make a second rule for the same thing.

## Building (for the build robot)
- PHP must stay PHP 7.4 safe. Run `php -l` on every PHP file you change and `node --check` on every JS file.
- Match the existing design (store.css style pages, plain buttons). Phone width must work: 16px side gutter, no sideways page scroll.
- Never print or log secrets. Never commit config.php or keys.
- Keep each change small and focused on the request. Do not rewrite unrelated files.
- Bump the `?v=` on any changed JS/CSS file included by HTML pages.
- Describe in the pull request, in plain words, what changed and what the owner should look at.
