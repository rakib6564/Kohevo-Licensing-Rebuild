// DOM / e2e harness for the Studio builder (developer-only; never shipped).
//
//   bash e2e/sandbox.sh                 # once: build + provision the local sandbox
//   SBX_DIR=<…>/site npm run test:e2e   # starts the PHP server and runs the suites
//
// Environment: SBX_DIR (sandbox site dir, enables the auto-started server),
// SBX_PORT (default 8100), SBX_URL (use an already running server instead).
import { defineConfig, devices } from '@playwright/test';

const port = Number(process.env.SBX_PORT || 8100);
const baseURL = process.env.SBX_URL || `http://localhost:${port}`;
const dir = process.env.SBX_DIR;

export default defineConfig({
  testDir: 'e2e',
  testMatch: '**/*.spec.mjs',
  globalSetup: './e2e/global-setup.mjs',
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: [['list']],
  outputDir: 'test-results',
  use: { baseURL, storageState: 'e2e/.auth/state.json', trace: 'retain-on-failure', screenshot: 'only-on-failure' },
  webServer: dir && !process.env.SBX_URL ? {
    command: `php -S localhost:${port} -t "${dir}" "${dir}/dev-server.php"`,
    url: `${baseURL}/admin/login.php`,
    reuseExistingServer: true,
    stdout: 'ignore',
    stderr: 'ignore',
    timeout: 30_000,
  } : undefined,
  projects: [
    { name: 'desktop', use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 800 } } },
    { name: 'tablet', use: { ...devices['Desktop Chrome'], viewport: { width: 820, height: 1000 } } },
    { name: 'mobile', use: { ...devices['Desktop Chrome'], viewport: { width: 390, height: 844 }, hasTouch: true } },
  ],
});
