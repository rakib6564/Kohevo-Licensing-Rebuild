// Config for the widget audit's browser step (developer-only). Uses the e2e harness' sandbox, login and page.
//   SBX_URL=http://localhost:8201 AUDIT_OUT=/tmp/fh-audit npx playwright test -c audit/playwright.config.mjs
import { defineConfig, devices } from '@playwright/test';

const baseURL = process.env.SBX_URL || 'http://localhost:8100';
export default defineConfig({
  testDir: '.',
  testMatch: '**/*.spec.mjs',
  globalSetup: '../e2e/global-setup.mjs',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 600_000,
  reporter: [['list']],
  outputDir: '../test-results-audit',
  use: { baseURL, storageState: 'e2e/.auth/state.json' },
  projects: [{ name: 'desktop', use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } } }],
});
