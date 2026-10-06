/*! Studio Runtime behaviour tests.
 *
 * Loads the demo page in a real browser, drives every interactive feature and
 * the A/B toggle, and writes PASS/FAIL lines into <pre id="RESULTS">.
 *
 *   node test/run-tests.js
 *
 * Requires Google Chrome (override the path with CHROME_BIN).
 */
'use strict';

var fs = require('fs');
var os = require('os');
var path = require('path');
var execFileSync = require('child_process').execFileSync;

var ROOT = path.join(__dirname, '..');
var CHROME = process.env.CHROME_BIN ||
  '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

if (!fs.existsSync(CHROME)) {
  console.error('Chrome not found at ' + CHROME + ' — set CHROME_BIN.');
  process.exit(1);
}

function loadHarness() {
  return fs.readFileSync(path.join(__dirname, 'harness.html'), 'utf8');
}

var page = fs.readFileSync(path.join(ROOT, 'index.html'), 'utf8');
if (page.indexOf('</body>') === -1) {
  console.error('index.html has no </body> — cannot inject the harness.');
  process.exit(1);
}

var localPage = path.join(ROOT, '_test-run.html');
fs.writeFileSync(localPage, page.replace('</body>', loadHarness() + '</body>'));

console.log('Driving index.html in Chrome…\n');

var dom;
try {
  dom = execFileSync(CHROME, [
    '--headless', '--disable-gpu', '--no-sandbox',
    '--window-size=1280,900',
    /* The driver waits ~1.5s, lets counters finish counting, then runs the
       overlay/widget passes, so the budget must exceed the sum. */
    '--virtual-time-budget=20000',
    /* Without a compositor, headless Chrome delivers no
       requestAnimationFrame callbacks, so every rAF-driven animation (counters,
       marquee, stagger entrance) reads as frozen. These make frames flow. */
    '--run-all-compositor-stages-before-draw',
    '--disable-new-content-rendering-timeout',
    '--disable-threaded-animation',
    '--disable-threaded-scrolling',
    '--disable-checker-imaging',
    '--dump-dom',
    localPage
  ], { encoding: 'utf8', maxBuffer: 32 * 1024 * 1024, stdio: ['ignore', 'pipe', 'ignore'] });
} finally {
  try { fs.unlinkSync(localPage); } catch (e) { /* best effort */ }
}

/* Anchor on the element's opening tag. --dump-dom echoes this runner's own
   source too, so a looser search could match the script instead of the real
   element; the id is unique enough that only the real element matches. */
var m = dom.match(/<pre id="SB_TEST_RESULTS"[^>]*>([\s\S]*?)<\/pre>/);
if (!m) {
  console.error('Harness did not complete — no results element in the dumped DOM.');
  console.error('  dumped DOM length : ' + dom.length);
  console.error('  runtime ready     : ' + (dom.indexOf('data-sb-ready') !== -1));
  process.exit(1);
}
var block = m[1]
  .replace(/&lt;/g, '<').replace(/&gt;/g, '>')
  .replace(/&quot;/g, '"').replace(/&#39;/g, "'")
  .replace(/&amp;/g, '&');

var lines = block.split('\n').map(function (l) { return l.trim(); })
  .filter(Boolean);
var passed = lines.filter(function (l) { return l.indexOf('PASS') === 0; });
var failed = lines.filter(function (l) { return l.indexOf('FAIL') === 0; });
/* INFO lines are frame-timing observations, not assertions — see harness.html. */
var noted = lines.filter(function (l) { return l.indexOf('INFO') === 0; });

/* --verbose prints every line, including the driver's diagnostic notes. */
var verbose = process.argv.indexOf('--verbose') !== -1;
if (verbose) lines.forEach(function (l) { console.log(l); });
else failed.forEach(function (l) { console.log(l); });

noted.forEach(function (l) { console.log(l); });
console.log('');
console.log('PASS: ' + passed.length + '   FAIL: ' + failed.length +
  '   INFO: ' + noted.length + ' (frame-timing observations, not asserted)');

process.exit(failed.length ? 1 : 0);