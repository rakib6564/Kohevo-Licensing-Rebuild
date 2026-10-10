// usage: node sbs.cjs ref.png new.png y0 h out.png  (side by side, each scaled to 50%)
const { PNG } = require('playwright-core/lib/utilsBundle');
const fs = require('fs');
const [a, b, y0, h, out] = process.argv.slice(2);
const A = PNG.sync.read(fs.readFileSync(a)), B = PNG.sync.read(fs.readFileSync(b));
const Y = +y0, H = +h, W = A.width;
const half = (img, y) => { // box downscale 2x of rows y..y+H
  const S = +(process.env.S || 2); const w = Math.floor(W / S), hh = Math.floor(H / S); const o = Buffer.alloc(w * hh * 4, 255);
  for (let j = 0; j < hh; j++) for (let i = 0; i < w; i++) for (let c = 0; c < 4; c++) {
    let s = 0, n = 0;
    for (let dy = 0; dy < S; dy++) for (let dx = 0; dx < S; dx++) { const yy = y + j * S + dy, xx = i * S + dx; if (yy < img.height && xx < img.width) { s += img.data[(yy * img.width + xx) * 4 + c]; n++; } }
    o[(j * w + i) * 4 + c] = n ? s / n : 255;
  }
  return { w, h: hh, data: o };
};
const l = half(A, Y), r = half(B, Y);
const O = new PNG({ width: l.w * 2 + 6, height: l.h }); O.data.fill(120);
for (let j = 0; j < l.h; j++) { l.data.copy(O.data, j * O.width * 4, j * l.w * 4, (j + 1) * l.w * 4); r.data.copy(O.data, (j * O.width + l.w + 6) * 4, j * r.w * 4, (j + 1) * r.w * 4); }
fs.writeFileSync(out, PNG.sync.write(O));
