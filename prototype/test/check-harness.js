/* Structural check for harness.html: verifies the script block is balanced and
 * syntactically valid before it is ever injected into the page. */
'use strict';
var fs = require('fs');
var path = require('path');

var f = path.join(__dirname, 'harness.html');
var html = fs.readFileSync(f, 'utf8');

var open = html.indexOf('<script>');
var close = html.lastIndexOf('<' + '/script>');

console.log('bytes            :', html.length);
console.log('open tag index   :', open);
console.log('close tag index  :', close);

if (open === -1 || close === -1 || close < open) {
  console.error('FAIL: harness is missing its <script> wrapper');
  process.exit(1);
}

var js = html.slice(open + 8, close);

var depth = 0;
var line = 1;
var worst = 0;
for (var i = 0; i < js.length; i++) {
  var ch = js[i];
  if (ch === '\n') line++;
  if (ch === '{') { depth++; if (depth > worst) worst = depth; }
  if (ch === '}') depth--;
  if (depth < 0) {
    console.error('FAIL: unbalanced } at line ' + line);
    process.exit(1);
  }
}
console.log('final brace depth:', depth, '(0 = balanced)');
console.log('max nesting      :', worst);

try {
  new Function(js);
  console.log('syntax           : OK');
} catch (e) {
  console.error('FAIL: ' + e.message);
  process.exit(1);
}