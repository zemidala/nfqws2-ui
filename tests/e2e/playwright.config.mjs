// Interface scenarios of nfqws2-ui against real devices.
// Run: python C:/ClaudeKit/tools/pw.py tests/e2e [--project=<device>] [-g "name"] [--headed]
// Devices are listed in targets.local.mjs (not in git: addresses of your network) — copy targets.example.mjs.
// Scenarios that change the config run only on a test device (see each spec); the rest only read.
import { defineConfig } from '@playwright/test';
import fs from 'node:fs';

const local = new URL('./targets.local.mjs', import.meta.url);
if (!fs.existsSync(local)) {
  throw new Error('tests/e2e/targets.local.mjs is missing: copy targets.example.mjs and put your devices in it');
}
export const { TARGETS } = await import(local.href);

export default defineConfig({
  testDir: './specs',
  outputDir: './test-results',
  timeout: 90_000,
  expect: { timeout: 15_000 },
  fullyParallel: false,
  workers: 1,               // one router: test rules and pick jobs are shared
  retries: 0,
  reporter: [['list'], ['html', { outputFolder: './report', open: 'never' }]],
  use: {
    channel: 'msedge',      // the installed Edge: Playwright downloads no browsers
    headless: true,
    locale: 'ru-RU',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    viewport: { width: 1280, height: 900 },
    // to watch: pw.py … --headed, and PW_SLOWMO=500 — a pause (ms) between actions
    launchOptions: { slowMo: Number(process.env.PW_SLOWMO || 0) },
  },
  projects: Object.entries(TARGETS).map(([name, t]) => ({ name, use: { baseURL: t.base, target: name } })),
});
