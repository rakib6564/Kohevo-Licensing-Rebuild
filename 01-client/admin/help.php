<?php
/**
 * Slate — Help & Support.
 *
 * FAQ, Forms plugin documentation, and a support-ticket form that emails
 * the site owner. Available to every signed-in admin.
 */
require_once dirname(__DIR__) . '/config.php';
Auth::require();

$tid  = current_tenant_id();
$user = Auth::user();

// ── Branding / owner + support recipient ─────────────────────
$brandOwner    = Database::setting('brand_owner')    ?: 'Rakib Hasan';
$brandOwnerUrl = Database::setting('brand_owner_url') ?: 'https://rakibhasaan.com';
if (!preg_match('#^https?://#i', $brandOwnerUrl)) $brandOwnerUrl = 'https://' . ltrim($brandOwnerUrl, '/');
$brandOwnerHost = preg_replace('#^https?://#i', '', rtrim($brandOwnerUrl, '/'));
$siteName = Database::setting('site_name') ?: 'your site';

$supportTo = Database::setting('support_email')
    ?: Database::setting('smtp_from_email')
    ?: (string) Database::value("SELECT email FROM users WHERE tenant_id = ? ORDER BY id LIMIT 1", [$tid])
    ?: 'info@rakibhasaan.com';

// ── Support ticket submission ────────────────────────────────
$flash = null;
$old   = ['subject' => '', 'category' => '', 'message' => ''];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $flash = ['type' => 'error', 'msg' => __('csrf_failed', 'Security check failed. Please try again.')];
    } else {
        $fromName  = trim((string)($_POST['name'] ?? ($user['name'] ?? '')));
        $fromEmail = trim((string)($_POST['email'] ?? ($user['email'] ?? '')));
        $category  = trim((string)($_POST['category'] ?? 'General question'));
        $subject   = trim((string)($_POST['subject'] ?? ''));
        $message   = trim((string)($_POST['message'] ?? ''));
        $old = ['subject' => $subject, 'category' => $category, 'message' => $message];

        if ($subject === '' || $message === '') {
            $flash = ['type' => 'error', 'msg' => __('help_missing_fields', 'Please fill in a subject and a message.')];
        } elseif ($fromEmail !== '' && !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $flash = ['type' => 'error', 'msg' => __('email_invalid', 'Please enter a valid email address.')];
        } else {
            $rows = static function (array $kv): string {
                $h = '';
                foreach ($kv as $k => $v) {
                    $h .= '<tr><td style="padding:6px 14px 6px 0;color:#6b7280;font-size:12px;vertical-align:top;white-space:nowrap;">'
                        . htmlspecialchars((string)$k) . '</td>'
                        . '<td style="padding:6px 0;color:#111827;font-size:13px;">'
                        . nl2br(htmlspecialchars((string)$v)) . '</td></tr>';
                }
                return $h;
            };
            $body = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:560px;margin:0 auto;">'
                . '<h2 style="font-size:16px;color:#111827;margin:0 0 4px;">' . __('help_email_new_ticket', 'New support ticket') . '</h2>'
                . '<p style="font-size:12.5px;color:#6b7280;margin:0 0 16px;">' . sprintf(__('help_email_submitted_from', 'Submitted from %s admin'), htmlspecialchars($siteName)) . '</p>'
                . '<table style="border-collapse:collapse;width:100%;">'
                . $rows([
                    __('help_email_from', 'From')         => $fromName !== '' ? $fromName : __('help_email_dash', '—'),
                    __('email', 'Email')                  => $fromEmail !== '' ? $fromEmail : __('help_email_dash', '—'),
                    __('help_field_category', 'Category') => $category,
                    __('help_field_subject', 'Subject')   => $subject,
                    __('help_email_sent', 'Sent')          => date('j M Y, H:i'),
                  ])
                . '</table>'
                . '<div style="margin-top:16px;padding:14px 16px;background:#f9fafb;border:1px solid #eef0f3;border-radius:10px;'
                . 'font-size:13.5px;color:#111827;line-height:1.6;">' . nl2br(htmlspecialchars($message)) . '</div>'
                . '</div>';

            try {
                $ok = Mailer::send($supportTo, '[Support · ' . $category . '] ' . $subject, $body);
                if ($ok) {
                    if (class_exists('AuditLog')) AuditLog::record('support.ticket_sent', $category, ['subject' => $subject]);
                    $flash = ['type' => 'success', 'msg' => __('help_ticket_sent', 'Thanks — your message has been sent. We\'ll reply by email.')];
                    $old = ['subject' => '', 'category' => '', 'message' => ''];
                } else {
                    $flash = ['type' => 'error', 'msg' => sprintf(__('help_ticket_failed', 'Sorry, the message could not be sent. Please email %s directly.'), $supportTo)];
                }
            } catch (\Throwable $e) {
                $flash = ['type' => 'error', 'msg' => sprintf(__('help_ticket_failed', 'Sorry, the message could not be sent. Please email %s directly.'), $supportTo)];
            }
        }
    }
}

// ── FAQ + docs content ───────────────────────────────────────
$faqs = [
    [__('help_faq_1_q', 'How do I create a new form?'),
     __('help_faq_1_a', 'Go to <strong>Forms → New form</strong>, choose a template or start blank, add your fields, then hit <strong>Publish</strong>. You\'ll get a public URL plus an embed snippet.')],
    [__('help_faq_2_q', 'Where do form submissions appear?'),
     __('help_faq_2_a', 'Under <strong>Submissions</strong>. You can search by name or email, filter by form and status, set a triage status (New → In progress → Done), and export everything to CSV.')],
    [__('help_faq_3_q', 'How do I get notified when someone submits?'),
     __('help_faq_3_a', 'Open a form, go to the <strong>Notifications</strong> tab, and set the notify email. You can also attach a branded PDF of each submission.')],
    [__('help_faq_4_q', 'How do I embed a form on my website?'),
     __('help_faq_4_a', 'In the <strong>Forms</strong> list, expand a published form and copy the <strong>Embed snippet</strong> (an iframe) or the public link.')],
    [__('help_faq_5_q', 'What is the Contacts list?'),
     __('help_faq_5_a', 'A contact list built automatically from form submissions — one row per email, with the submission count and last-seen date. Open <strong>Contacts</strong> to browse or export it.')],
    [__('help_faq_6_q', 'How do I add another admin or change permissions?'),
     __('help_faq_6_a', 'Go to <strong>Users → New user</strong> to invite someone, and use <strong>Roles</strong> to control what each role can access.')],
    [__('help_faq_7_q', 'How do I change the logo, name, or email settings?'),
     __('help_faq_7_a', 'Everything brand- and email-related lives under <strong>Settings</strong> — site name, logo, accent color, and SMTP/sender details.')],
];

$formTypes = ['text', 'email', 'tel', 'url', 'number', 'date', 'time', 'textarea',
    'select', 'radio', 'checkbox', 'range', 'rating', 'file', 'signature', 'heading', 'calc', 'step', 'disclaimer'];

$pageTitle  = __('help_support', 'Help & Support');
$currentNav = 'help';

require __DIR__ . '/partials/header.php';
?>

<?php slate_breadcrumbs([
    ['label' => __('dashboard', 'Dashboard'), 'href' => SLATE_URL . '/admin/'],
    ['label' => __('help_support', 'Help & Support')],
]); ?>

<style>
.help-layout { display: grid; grid-template-columns: minmax(0, 1fr) 380px; gap: 16px; align-items: start; }
.help-main { display: flex; flex-direction: column; gap: 16px; min-width: 0; }
.help-rail { display: flex; flex-direction: column; gap: 16px; position: sticky; top: 84px; }

/* FAQ accordion (native <details>) */
.faq { border-top: 1px solid var(--border); }
.faq details { border-bottom: 1px solid var(--border); }
.faq summary {
    list-style: none; cursor: pointer; padding: 14px 2px;
    display: flex; align-items: center; gap: 12px;
    font-size: 14px; font-weight: 600; color: var(--text);
}
.faq summary::-webkit-details-marker { display: none; }
.faq summary .chev {
    margin-left: auto; flex: none; color: var(--subtle);
    transition: transform .18s ease;
}
.faq details[open] summary .chev { transform: rotate(180deg); }
.faq summary .q {
    flex: none; width: 22px; height: 22px; border-radius: 7px;
    display: grid; place-items: center; font-size: 11px; font-weight: 700;
    background: var(--accent-soft); color: var(--accent);
}
.faq-a { padding: 0 2px 16px 36px; font-size: 13.5px; color: var(--muted); line-height: 1.65; }
.faq-a strong { color: var(--text-2, var(--text)); font-weight: 600; }

/* Docs */
.docs-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 8px; margin-top: 4px; }
.docs-chip {
    font-family: var(--font-mono); font-size: 11.5px; color: var(--text-2, var(--text));
    background: var(--surface-2); border: 1px solid var(--border); border-radius: 8px;
    padding: 7px 10px; text-align: center;
}
.docs-dsl {
    font-family: var(--font-mono); font-size: 12.5px; color: var(--text);
    background: var(--surface-2); border: 1px solid var(--border); border-radius: 10px;
    padding: 12px 14px; overflow-x: auto; white-space: pre; margin: 8px 0 0;
}
.docs-feats { list-style: none; margin: 6px 0 0; padding: 0; display: grid; gap: 8px; }
.docs-feats li { display: flex; gap: 10px; font-size: 13px; color: var(--muted); }
.docs-feats svg { flex: none; width: 16px; height: 16px; color: var(--accent); margin-top: 2px; }
.docs-feats b { color: var(--text); font-weight: 600; }

/* Support form */
.help-field { margin-bottom: 12px; }
.help-field label { display: block; font-size: 12px; font-weight: 600; color: var(--text-2, var(--text)); margin-bottom: 5px; }
.help-field input, .help-field select, .help-field textarea {
    width: 100%; padding: 9px 11px; font: inherit; font-size: 13px;
    border: 1px solid var(--border); border-radius: 9px; background: var(--surface); color: var(--text);
}
.help-field textarea { min-height: 120px; resize: vertical; }
.help-contact { display: flex; flex-direction: column; gap: 8px; font-size: 13px; color: var(--muted); }
.help-contact a { color: var(--accent); font-weight: 600; text-decoration: none; }
.help-contact-row { display: flex; align-items: center; gap: 10px; }
.help-contact-row svg { flex: none; width: 16px; height: 16px; color: var(--muted); }

@media (max-width: 980px) {
    .help-layout { grid-template-columns: 1fr; }
    .help-rail { position: static; }
}
</style>

<div class="page-header">
    <div>
        <h1><?= e(__('help_support', 'Help & Support')) ?></h1>
        <p class="page-header-sub"><?= e(__('help_support_sub', 'Guides, answers, and a direct line to support.')) ?></p>
    </div>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['msg']) ?></div>
<?php endif; ?>

<div class="help-layout">
    <div class="help-main">
        <!-- FAQ -->
        <div class="card">
            <div class="card-header"><h2><?= e(__('help_faq_heading', 'Frequently asked questions')) ?></h2></div>
            <div class="faq">
                <?php foreach ($faqs as $i => $f): ?>
                    <details<?= $i === 0 ? ' open' : '' ?>>
                        <summary>
                            <span class="q"><?= $i + 1 ?></span>
                            <span><?= e($f[0]) ?></span>
                            <svg class="chev" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
                        </summary>
                        <div class="faq-a"><?= $f[1] /* trusted static HTML */ ?></div>
                    </details>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Forms documentation -->
        <div class="card">
            <div class="card-header"><h2><?= e(__('help_forms_docs_heading', 'Forms — quick documentation')) ?></h2></div>
            <p class="text-sm text-muted"><?= e(__('help_forms_docs_intro', 'Fields can be built visually (drag-and-drop) or with the one-line DSL — one field per line:')) ?></p>
            <div class="docs-dsl">type|name|label|required|placeholder|options

text|full_name|Your name|required
email|email|Email|required|you@example.com
tel|phone|Phone
select|reason|Reason||Choose…|sales,support,other
textarea|message|Tell us more|required</div>

            <p class="text-sm text-muted" style="margin-top:14px;"><?= e(__('help_forms_docs_types', 'Supported field types:')) ?></p>
            <div class="docs-grid">
                <?php foreach ($formTypes as $t): ?>
                    <span class="docs-chip"><?= e($t) ?></span>
                <?php endforeach; ?>
            </div>

            <p class="text-sm text-muted" style="margin-top:16px;"><?= e(__('help_forms_docs_features', 'Advanced features included:')) ?></p>
            <ul class="docs-feats">
                <?php
                $feats = [
                    [__('help_feat_1_title', 'Multi-step forms'), __('help_feat_1_desc', 'split long forms into steps with a progress bar')],
                    [__('help_feat_2_title', 'Conditional logic'), __('help_feat_2_desc', 'show or hide fields based on earlier answers')],
                    [__('help_feat_3_title', 'Calculated fields'), __('help_feat_3_desc', 'live totals from a formula like {qty} * {price}')],
                    [__('help_feat_4_title', 'E-signature & file upload'), __('help_feat_4_desc', 'capture drawn/typed signatures and attachments')],
                    [__('help_feat_5_title', 'Spam protection'), __('help_feat_5_desc', 'honeypot, rate-limit, CAPTCHA, country & keyword rules')],
                    [__('help_feat_6_title', 'Branded PDF + webhooks'), __('help_feat_6_desc', 'auto-PDF of each submission and POST to your endpoints')],
                ];
                foreach ($feats as $ft): ?>
                    <li>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        <span><b><?= e($ft[0]) ?></b> — <?= e($ft[1]) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <!-- Rail: support ticket + contact -->
    <aside class="help-rail">
        <div class="card">
            <div class="card-header"><h2><?= e(__('help_contact_heading', 'Contact support')) ?></h2></div>
            <p class="text-sm text-muted"><?= e(__('help_contact_intro', 'Found a bug or have a question? Send the details and we\'ll reply by email.')) ?></p>
            <form method="post" style="margin-top:12px;">
                <?= csrf_field() ?>
                <div class="help-field">
                    <label for="h-name"><?= e(__('help_field_your_name', 'Your name')) ?></label>
                    <input type="text" id="h-name" name="name" value="<?= e($user['name'] ?? '') ?>" required>
                </div>
                <div class="help-field">
                    <label for="h-email"><?= e(__('email', 'Email')) ?></label>
                    <input type="email" id="h-email" name="email" value="<?= e($user['email'] ?? '') ?>" required>
                </div>
                <div class="help-field">
                    <label for="h-cat"><?= e(__('help_field_category', 'Category')) ?></label>
                    <select id="h-cat" name="category">
                        <?php foreach ([
                            __('help_cat_general', 'General question'),
                            __('help_cat_bug', 'Bug report'),
                            __('help_cat_feature', 'Feature request'),
                            __('help_cat_forms', 'Forms help'),
                            __('help_cat_billing', 'Billing'),
                            __('help_cat_other', 'Other'),
                        ] as $c): ?>
                            <option value="<?= e($c) ?>" <?= ($old['category'] === $c) ? 'selected' : '' ?>><?= e($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="help-field">
                    <label for="h-subject"><?= e(__('help_field_subject', 'Subject')) ?></label>
                    <input type="text" id="h-subject" name="subject" value="<?= e($old['subject']) ?>" placeholder="<?= e(__('help_subject_ph', 'Short summary')) ?>" required>
                </div>
                <div class="help-field">
                    <label for="h-message"><?= e(__('help_field_message', 'Describe the issue')) ?></label>
                    <textarea id="h-message" name="message" placeholder="<?= e(__('help_message_ph', 'What happened, what you expected, and any steps to reproduce…')) ?>" required><?= e($old['message']) ?></textarea>
                </div>
                <button type="submit" class="btn btn-primary btn-block"><?= e(__('help_send_message', 'Send message')) ?></button>
            </form>
        </div>

        <div class="card">
            <div class="card-header"><h2><?= e(__('help_owner_heading', 'Owner')) ?></h2></div>
            <div class="help-contact">
                <div class="help-contact-row">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>
                    <span><strong style="color:var(--text);"><?= e($brandOwner) ?></strong></span>
                </div>
                <div class="help-contact-row">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg>
                    <a href="<?= e($brandOwnerUrl) ?>" target="_blank" rel="noopener"><?= e($brandOwnerHost) ?></a>
                </div>
                <div class="help-contact-row">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/></svg>
                    <a href="mailto:<?= e($supportTo) ?>"><?= e($supportTo) ?></a>
                </div>
            </div>
        </div>
    </aside>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
