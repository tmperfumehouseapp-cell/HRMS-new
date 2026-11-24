import { loadEnv, getConfig } from './config.js';
import { openDb } from './db.js';
import { dailyReport, toCsv, toText, todayIn, shiftDay } from './report.js';
import { syncSpend, retryPending, getWabaDataset } from './meta.js';

loadEnv();
const cfg = getConfig();
const db = openDb(cfg.dbPath);
const [cmd, ...rest] = process.argv.slice(2);
const flag = (n, d) => { const i = rest.indexOf(`--${n}`); return i < 0 ? d : (rest[i + 1]?.startsWith('--') || rest[i + 1] === undefined ? true : rest[i + 1]); };

if (cmd === 'report') {
  // default: yesterday (full day, IST). --date YYYY-MM-DD = last day, --days N, --csv, --sync (pull spend first)
  const to = flag('date', shiftDay(todayIn(cfg.tzOffsetMin), -1));
  const days = Number(flag('days', 1));
  if (flag('sync', false)) await syncSpend(db, cfg, days + 1);
  const rep = dailyReport(db, { from: shiftDay(to, -(days - 1)), to }, cfg.tzOffsetMin, cfg.adNameMatch || undefined);
  process.stdout.write(flag('csv', false) ? toCsv(rep) : toText(rep));
} else if (cmd === 'sync-spend') {
  console.log(`synced ${await syncSpend(db, cfg, Number(flag('days', 3)))} ad-day rows`);
} else if (cmd === 'tmph-sync') {
  const { syncTmph } = await import('./tmph.js');
  console.log(JSON.stringify(await syncTmph(db, cfg)));
} else if (cmd === 'dataset') {
  console.log('dataset id:', await getWabaDataset(cfg));
} else if (cmd === 'retry-capi') {
  console.log(JSON.stringify(await retryPending(db, cfg)));
} else {
  console.log('usage: node src/cli.js report [--date YYYY-MM-DD] [--days N] [--csv] [--sync] | sync-spend [--days N] | retry-capi | dataset');
  process.exit(1);
}
