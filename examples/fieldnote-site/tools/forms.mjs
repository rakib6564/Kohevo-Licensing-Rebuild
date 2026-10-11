// The two forms the site embeds, created in the Forms plugin through its own admin page (so validation, the spam guard, the
// inbox and the notification email all belong to Forms). Found by slug when they exist, so re-running changes nothing.
//   FN_NOTIFY_EMAIL=you@example.com  → where each enquiry is emailed (the inbox keeps every one regardless)
export const FORMS = {
  contact: {
    slug: 'fieldnote-contact', title: 'Send us a message', submit_label: 'Send message',
    description: '',
    success_message: 'Thank you. Your message is with us, and you will hear back within one working day.',
    dsl: ['text|name|Your name|required|Ada Lovelace', 'email|email|Email|required|you@company.com', 'text|company|Company or project||What are we working on?', 'text|budget|Rough budget||For example €5,000 to €10,000', 'textarea|message|How can we help?|required|Goals, timing, anything that helps.'].join('\n'),
  },
  enquiry: {
    slug: 'fieldnote-enquiry', title: 'Your details', submit_label: 'Send enquiry',
    description: '',
    success_message: 'Thank you. We have your enquiry and will reply within one working day.',
    dsl: ['text|name|Your name|required|Ada Lovelace', 'email|email|Email|required|you@company.com', 'text|company|Company or project||What are we working on?', 'textarea|message|How can we help?|required|A few lines about the project, timing and budget is perfect.'].join('\n'),
  },
};

// The look the forms take inside the site (Forms' own designer settings): the accent, field style and corner shape.
const LOOK = { fs_accent_on: '1', fs_accent: '#e4572e', fs_accent_hover: '#c2431e', fs_field_style: 'outline', fs_shape: 'rounded', fs_density: 'comfortable', fs_validate: '1' };

export async function ensureForms(page, ids) {
  const notify = process.env.FN_NOTIFY_EMAIL || '';
  await page.goto('plugins/forms/admin/index.php');
  const html = await page.content();
  for (const [key, f] of Object.entries(FORMS)) {
    // an existing form with this slug: update it in place (its row links to edit.php?id=N)
    const row = new RegExp(`edit\\.php\\?id=(\\d+)[^<]*[\\s\\S]{0,600}?${f.slug}`).exec(html) || new RegExp(`${f.slug}[\\s\\S]{0,600}?edit\\.php\\?id=(\\d+)`).exec(html);
    const existing = row ? Number(row[1]) : 0;
    await page.goto(`plugins/forms/admin/edit.php${existing ? `?id=${existing}` : ''}`);
    const csrf = await page.locator('input[name="_csrf"]').first().getAttribute('value');
    const res = await page.evaluate(async ({ f, csrf, notify, look }) => {
      const body = new URLSearchParams({ _csrf: csrf, title: f.title, slug: f.slug, status: 'published', description: f.description, fields_dsl: f.dsl, submit_label: f.submit_label, success_message: f.success_message, notify_email: notify, redirect_url: '', ...look });
      const r = await fetch(location.href, { method: 'POST', body, credentials: 'same-origin', redirect: 'follow' });
      return { url: r.url, status: r.status, text: (await r.text()).slice(0, 4000) };
    }, { f, csrf, notify, look: LOOK });
    const id = /[?&]id=(\d+)/.exec(res.url);
    if (!id) throw new Error(`saving form ${key} failed: ${res.status} ${res.url}\n${res.text.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').slice(0, 500)}`);
    ids[key] = Number(id[1]);
    console.log('form', key, existing ? 'updated' : 'created', 'id', ids[key], notify ? `(emails ${notify})` : '(inbox only: set FN_NOTIFY_EMAIL to be emailed)');
  }
}
