// A stand-in for the two Stripe Checkout calls the e2e sandbox makes (developer tool; never shipped or deployed).
//
//   node e2e/fake-stripe.mjs [port]     (Playwright starts it; the sandbox's .env points STRIPE_API_BASE here)
//
// POST /v1/checkout/sessions answers a session whose `url` is the success_url itself, as if the customer paid on Stripe
// at once; GET /v1/checkout/sessions/:id answers that session, paid, with the amount of its line items. Only loopback
// addresses are honoured by StripePaymentAPI::apiBase(), so nothing here can be reached from a real install.
import http from 'node:http';

const port = Number(process.argv[2] || process.env.FAKE_STRIPE_PORT || 12111);
const sessions = new Map();
let seq = 0;

function readBody(req) {
  return new Promise((resolve) => {
    let raw = '';
    req.on('data', (c) => { raw += c; });
    req.on('end', () => resolve(new URLSearchParams(raw)));
  });
}

function send(res, status, body) {
  res.writeHead(status, { 'Content-Type': 'application/json' });
  res.end(JSON.stringify(body));
}

http.createServer(async (req, res) => {
  const url = new URL(req.url, `http://127.0.0.1:${port}`);
  if (req.method === 'GET' && url.pathname === '/health') return send(res, 200, { ok: true });
  if (req.method === 'POST' && url.pathname === '/v1/checkout/sessions') {
    const p = await readBody(req);
    let amount = 0;
    for (let i = 0; p.has(`line_items[${i}][quantity]`); i++) {
      amount += Number(p.get(`line_items[${i}][quantity]`)) * Number(p.get(`line_items[${i}][price_data][unit_amount]`));
    }
    const id = `cs_test_fake_${++seq}_${Date.now()}`;
    const metadata = {};
    for (const [k, v] of p) {
      const m = /^metadata\[(.+)\]$/.exec(k);
      if (m) metadata[m[1]] = v;
    }
    const session = {
      id,
      object: 'checkout.session',
      amount_total: amount,
      currency: p.get('line_items[0][price_data][currency]') || 'eur',
      payment_status: 'paid',
      payment_intent: `pi_test_fake_${seq}`,
      customer_email: p.get('customer_email') || null,
      customer_details: { email: p.get('customer_email') || null },
      metadata,
      url: String(p.get('success_url') || '').replace('{CHECKOUT_SESSION_ID}', id),
    };
    sessions.set(id, session);
    return send(res, 200, session);
  }
  const get = /^\/v1\/checkout\/sessions\/([A-Za-z0-9_]+)$/.exec(url.pathname);
  if (req.method === 'GET' && get) {
    const s = sessions.get(get[1]);
    return s ? send(res, 200, s) : send(res, 404, { error: { message: 'No such checkout session' } });
  }
  return send(res, 404, { error: { message: 'Not handled by the fake' } });
}).listen(port, '127.0.0.1', () => {
  console.log(`fake stripe on http://127.0.0.1:${port}`);
});
