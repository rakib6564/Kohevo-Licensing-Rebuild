<?php
/**
 * Forms — built-in template library.
 *
 * A curated set of modern, fully-editable form blueprints shown in the
 * "Start a form" picker. Each template is plain canonical field JSON
 * (the shape the visual builder + FormsAPI::normalizeFields() use) plus
 * an optional `settings` block (appearance) and picker metadata
 * (category, icon, badges). Picking one just seeds the builder — it all
 * stays editable afterwards.
 *
 * Field keys: type, name, label, required, placeholder, options, width
 * (twelfths; 6 = half, 4 = third), card (bool for choice cards),
 * show_if {field,op,value}, formula (calc), and `type:'step'` markers
 * for multi-step wizards.
 *
 * No DB table — the library lives in code.
 */

class FormTemplates {

    /** Field factory: T(type, name, label, [extra]). */
    private static function f(string $type, string $name, string $label, array $x = []): array {
        return array_merge(['type' => $type, 'name' => $name, 'label' => $label], $x);
    }
    /** Step marker (wizard page break). */
    private static function step(string $name, string $title, string $sub = ''): array {
        return ['type' => 'step', 'name' => $name, 'label' => $title, 'placeholder' => $sub];
    }
    /** Choice-card option {value,label,icon,desc}. */
    private static function opt(string $value, string $icon = '', string $desc = ''): array {
        return ['value' => $value, 'label' => $value, 'icon' => $icon, 'desc' => $desc];
    }

    /** All templates, grouped-friendly (carry a `category`). */
    public static function all(): array {
        $f = [self::class, 'f']; $S = [self::class, 'step']; $o = [self::class, 'opt'];

        return [

            // ── General ──────────────────────────────────────────
            [
                'slug' => 'contact', 'name' => 'Contact us', 'icon' => 'mail', 'category' => 'General',
                'desc' => 'Name, email and a message — the classic contact form.',
                'badges' => ['Columns'],
                'title' => 'Contact us',
                'settings' => ['accent' => '#2563EB', 'density' => 'comfortable', 'field_style' => 'outline', 'shape' => 'rounded'],
                'fields' => [
                    $f('text',  'first_name', 'First name', ['required' => true, 'width' => 6]),
                    $f('text',  'last_name',  'Last name',  ['required' => true, 'width' => 6]),
                    $f('email', 'email',      'Email',      ['required' => true, 'width' => 6]),
                    $f('tel',   'phone',      'Phone',      ['width' => 6]),
                    $f('text',  'subject',    'Subject'),
                    $f('textarea', 'message',  'Message',   ['required' => true]),
                ],
            ],
            [
                'slug' => 'newsletter', 'name' => 'Newsletter signup', 'icon' => 'send', 'category' => 'Marketing',
                'desc' => 'Minimal, on-brand email capture with a pill button.',
                'badges' => ['Minimal'],
                'title' => 'Join our newsletter',
                'settings' => ['accent' => '#111827', 'density' => 'comfortable', 'field_style' => 'filled', 'shape' => 'pill', 'labels' => 'hide'],
                'fields' => [
                    $f('email',  'email', 'Email address', ['required' => true, 'placeholder' => 'you@example.com']),
                    $f('text',   'name',  'First name', ['width' => 6, 'placeholder' => 'First name']),
                    $f('select', 'frequency', 'How often?', ['width' => 6, 'options' => ['Daily', 'Weekly', 'Monthly']]),
                ],
            ],

            // ── Events ───────────────────────────────────────────
            [
                'slug' => 'event-registration', 'name' => 'Event registration', 'icon' => 'calendar', 'category' => 'Events',
                'desc' => 'Multi-step sign-up with selectable ticket cards.',
                'badges' => ['3 steps', 'Choice cards', 'Animated'],
                'title' => 'Register for the event',
                'settings' => ['accent' => '#7C3AED', 'density' => 'comfortable', 'field_style' => 'outline', 'shape' => 'rounded', 'rail' => true, 'animate' => true],
                'fields' => [
                    $S('s_you', 'Your details', 'Who should we put on the guest list?'),
                    $f('text',  'full_name', 'Full name', ['required' => true, 'width' => 6]),
                    $f('email', 'email',     'Email',     ['required' => true, 'width' => 6]),
                    $f('tel',   'phone',     'Phone',     ['width' => 6]),
                    $f('text',  'company',   'Company',   ['width' => 6]),
                    $S('s_ticket', 'Choose your ticket', 'Pick the pass that fits you best.'),
                    $f('radio', 'ticket', 'Ticket type', ['required' => true, 'card' => true, 'options' => [
                        $o('General', 'tag', 'Full conference access'),
                        $o('VIP', 'star', 'Front-row seats + lounge'),
                        $o('Backstage', 'shield', 'Meet the speakers'),
                    ]]),
                    $f('number', 'guests', 'Extra guests', ['width' => 6, 'placeholder' => '0']),
                    $f('text',   'dietary', 'Dietary needs', ['width' => 6]),
                    $S('s_done', 'Almost there', 'Anything else we should know?'),
                    $f('textarea', 'notes', 'Notes for the organisers'),
                ],
            ],
            [
                'slug' => 'rsvp', 'name' => 'Event RSVP', 'icon' => 'check', 'category' => 'Events',
                'desc' => 'Quick yes/no RSVP with guest count.',
                'badges' => ['Choice cards'],
                'title' => 'Will you join us?',
                'settings' => ['accent' => '#DB2777', 'density' => 'comfortable', 'shape' => 'rounded', 'animate' => true],
                'fields' => [
                    $f('text',  'full_name', 'Your name', ['required' => true, 'width' => 6]),
                    $f('email', 'email',     'Email',     ['required' => true, 'width' => 6]),
                    $f('radio', 'attending', 'Can you make it?', ['required' => true, 'card' => true, 'options' => [
                        $o('Yes', 'check', 'I’ll be there!'),
                        $o('No', 'tag', 'Sorry, can’t make it'),
                    ]]),
                    $f('number', 'guests', 'Number of guests', ['width' => 6, 'placeholder' => '0',
                        'show_if' => ['field' => 'attending', 'op' => 'eq', 'value' => 'Yes']]),
                    $f('text', 'dietary', 'Dietary needs', ['width' => 6,
                        'show_if' => ['field' => 'attending', 'op' => 'eq', 'value' => 'Yes']]),
                ],
            ],

            // ── HR ───────────────────────────────────────────────
            [
                'slug' => 'job-application', 'name' => 'Job application', 'icon' => 'clipboard-list', 'category' => 'HR',
                'desc' => 'A polished multi-step application with résumé upload.',
                'badges' => ['3 steps', 'File upload', 'Animated'],
                'title' => 'Apply for this role',
                'settings' => ['accent' => '#0EA5E9', 'density' => 'comfortable', 'field_style' => 'outline', 'shape' => 'rounded', 'rail' => true, 'animate' => true],
                'fields' => [
                    $S('s_about', 'About you', 'The basics first.'),
                    $f('text',  'first_name', 'First name', ['required' => true, 'width' => 6]),
                    $f('text',  'last_name',  'Last name',  ['required' => true, 'width' => 6]),
                    $f('email', 'email',      'Email',      ['required' => true, 'width' => 6]),
                    $f('tel',   'phone',      'Phone',      ['width' => 6]),
                    $S('s_exp', 'Experience', 'Tell us about your background.'),
                    $f('select', 'position', 'Position', ['required' => true, 'options' => ['Engineering', 'Design', 'Product', 'Sales', 'Support', 'Other']]),
                    $f('number', 'years', 'Years of experience', ['width' => 6, 'placeholder' => '0']),
                    $f('url',    'portfolio', 'Portfolio / LinkedIn', ['width' => 6]),
                    $f('file',   'resume', 'Résumé / CV', ['required' => true]),
                    $S('s_more', 'Final touches', 'Anything to add?'),
                    $f('textarea', 'cover', 'Cover letter'),
                    $f('date', 'start_date', 'Earliest start date', ['width' => 6]),
                ],
            ],

            // ── Feedback / Research ──────────────────────────────
            [
                'slug' => 'feedback', 'name' => 'Customer feedback', 'icon' => 'star', 'category' => 'Feedback',
                'desc' => 'Star rating + an NPS slider and an open comment.',
                'badges' => ['Rating', 'Slider'],
                'title' => 'How did we do?',
                'settings' => ['accent' => '#16A34A', 'density' => 'comfortable', 'shape' => 'rounded', 'animate' => true],
                'fields' => [
                    $f('rating', 'rating', 'Rate your experience', ['required' => true, 'options' => ['5']]),
                    $f('range',  'nps', 'How likely are you to recommend us? (0–10)', ['options' => ['0', '10', '1']]),
                    $f('textarea', 'comment', 'What stood out — good or bad?'),
                    $f('email', 'email', 'Email (optional)', ['width' => 6]),
                ],
            ],
            [
                'slug' => 'survey', 'name' => 'Customer survey', 'icon' => 'bar-chart-2', 'category' => 'Feedback',
                'desc' => 'A short, stepped survey that feels effortless.',
                'badges' => ['3 steps', 'Rating', 'Animated'],
                'title' => 'Quick survey',
                'settings' => ['accent' => '#F59E0B', 'density' => 'comfortable', 'shape' => 'rounded', 'rail' => true, 'animate' => true],
                'fields' => [
                    $S('s_rate', 'Your rating', ''),
                    $f('rating', 'rating', 'Overall, how happy are you?', ['required' => true, 'options' => ['5']]),
                    $S('s_choice', 'A few questions', ''),
                    $f('radio', 'satisfaction', 'How satisfied are you?', ['required' => true, 'options' => ['Very satisfied', 'Satisfied', 'Neutral', 'Unsatisfied']]),
                    $f('radio', 'recommend', 'Would you recommend us?', ['options' => ['Definitely', 'Maybe', 'No']]),
                    $S('s_open', 'In your words', ''),
                    $f('textarea', 'improve', 'What could we do better?'),
                ],
            ],

            // ── Sales / Commerce ─────────────────────────────────
            [
                'slug' => 'demo-request', 'name' => 'Request a demo', 'icon' => 'send', 'category' => 'Sales',
                'desc' => 'B2B lead form with company size and use case.',
                'badges' => ['Columns', 'Slider'],
                'title' => 'See it in action',
                'settings' => ['accent' => '#4F46E5', 'density' => 'comfortable', 'field_style' => 'filled', 'shape' => 'rounded'],
                'fields' => [
                    $f('text',  'full_name',  'Full name',  ['required' => true, 'width' => 6]),
                    $f('email', 'work_email', 'Work email', ['required' => true, 'width' => 6]),
                    $f('text',  'company',    'Company',    ['width' => 6]),
                    $f('select','company_size','Company size', ['width' => 6, 'options' => ['1–10', '11–50', '51–200', '201–1000', '1000+']]),
                    $f('textarea', 'use_case', 'What are you hoping to solve?'),
                    $f('range', 'timeline', 'How soon? (weeks)', ['options' => ['0', '12', '1']]),
                ],
            ],
            [
                'slug' => 'order-quote', 'name' => 'Order / quote', 'icon' => 'tag', 'category' => 'Commerce',
                'desc' => 'Product order with a live calculated total.',
                'badges' => ['Calculated total', 'Columns'],
                'title' => 'Place your order',
                'settings' => ['accent' => '#0D9488', 'density' => 'comfortable', 'field_style' => 'outline', 'shape' => 'rounded'],
                'fields' => [
                    $f('text',  'full_name', 'Name',  ['required' => true, 'width' => 6]),
                    $f('email', 'email',     'Email', ['required' => true, 'width' => 6]),
                    $f('select', 'product', 'Product', ['required' => true, 'options' => ['Starter', 'Pro', 'Enterprise']]),
                    $f('number', 'qty', 'Quantity', ['required' => true, 'width' => 6, 'placeholder' => '1']),
                    $f('number', 'unit_price', 'Unit price (USD)', ['width' => 6, 'placeholder' => '49']),
                    $f('calc',   'total', 'Estimated total (USD)', ['formula' => '{qty} * {unit_price}']),
                    $f('textarea', 'notes', 'Order notes'),
                ],
            ],

            // ── Booking ──────────────────────────────────────────
            [
                'slug' => 'appointment', 'name' => 'Book an appointment', 'icon' => 'calendar', 'category' => 'Booking',
                'desc' => 'Pick a service, date and time in a clean flow.',
                'badges' => ['Choice cards', 'Columns'],
                'title' => 'Book your appointment',
                'settings' => ['accent' => '#0891B2', 'density' => 'comfortable', 'shape' => 'rounded', 'animate' => true],
                'fields' => [
                    $f('radio', 'service', 'Service', ['required' => true, 'card' => true, 'options' => [
                        $o('Consultation', 'user', '30 min intro call'),
                        $o('Standard', 'check', '60 min session'),
                        $o('Premium', 'star', '90 min deep-dive'),
                    ]]),
                    $f('date', 'date', 'Preferred date', ['required' => true, 'width' => 6]),
                    $f('time', 'time', 'Preferred time', ['required' => true, 'width' => 6]),
                    $f('text',  'full_name', 'Your name', ['required' => true, 'width' => 6]),
                    $f('tel',   'phone',     'Phone',     ['required' => true, 'width' => 6]),
                    $f('textarea', 'notes', 'Anything we should prepare?'),
                ],
            ],

            // ── Support ──────────────────────────────────────────
            [
                'slug' => 'support-ticket', 'name' => 'Support ticket', 'icon' => 'shield', 'category' => 'Support',
                'desc' => 'Smart ticket form that adapts to the issue type.',
                'badges' => ['Conditional', 'File upload'],
                'title' => 'Open a support ticket',
                'settings' => ['accent' => '#DC2626', 'density' => 'comfortable', 'field_style' => 'outline', 'shape' => 'rounded'],
                'fields' => [
                    $f('text',  'full_name', 'Your name', ['required' => true, 'width' => 6]),
                    $f('email', 'email',     'Email',     ['required' => true, 'width' => 6]),
                    $f('radio', 'topic', 'What can we help with?', ['required' => true, 'options' => ['Bug', 'Billing', 'Account', 'Other']]),
                    $f('text', 'invoice', 'Invoice number', ['width' => 6,
                        'show_if' => ['field' => 'topic', 'op' => 'eq', 'value' => 'Billing']]),
                    $f('radio', 'priority', 'Priority', ['options' => ['Low', 'Normal', 'High', 'Urgent']]),
                    $f('text', 'subject', 'Subject', ['required' => true]),
                    $f('textarea', 'description', 'Describe the issue', ['required' => true]),
                    $f('file', 'attachment', 'Attach a screenshot or file'),
                ],
            ],

            // ── Nonprofit ────────────────────────────────────────
            [
                'slug' => 'donation', 'name' => 'Donation', 'icon' => 'star', 'category' => 'Nonprofit',
                'desc' => 'Preset amount cards with a custom-amount option.',
                'badges' => ['Choice cards', 'Conditional'],
                'title' => 'Make a donation',
                'settings' => ['accent' => '#059669', 'density' => 'comfortable', 'shape' => 'rounded', 'animate' => true],
                'fields' => [
                    $f('radio', 'amount', 'Choose an amount', ['required' => true, 'card' => true, 'options' => [
                        $o('$25', 'heart', 'Feeds a family'),
                        $o('$50', 'gift', 'Supplies for a week'),
                        $o('$100', 'star', 'Sponsors a child'),
                        $o('Custom', 'tag', 'Your choice'),
                    ]]),
                    $f('number', 'custom_amount', 'Custom amount (USD)', ['width' => 6,
                        'show_if' => ['field' => 'amount', 'op' => 'eq', 'value' => 'Custom']]),
                    $f('text',  'full_name', 'Your name', ['required' => true, 'width' => 6]),
                    $f('email', 'email',     'Email',     ['required' => true, 'width' => 6]),
                    $f('checkbox', 'recurring', 'Make this a monthly gift', ['width' => 6]),
                ],
            ],

            // ═══════════ ADVANCED ═══════════════════════════════

            // ── Healthcare: 4-step intake with consent signature ──
            [
                'slug' => 'patient-intake', 'name' => 'Patient intake', 'icon' => 'clipboard-list', 'category' => 'Healthcare',
                'desc' => '4-step intake with conditional history and an e-signature consent.',
                'badges' => ['4 steps', 'Conditional', 'E-signature', 'File'],
                'title' => 'New patient intake',
                'settings' => ['accent' => '#0EA5E9', 'density' => 'comfortable', 'field_style' => 'outline', 'shape' => 'rounded', 'rail' => true, 'animate' => true],
                'fields' => [
                    $S('s_pt', 'Patient details', 'Tell us who we’re seeing.'),
                    $f('text', 'first_name', 'First name', ['required' => true, 'width' => 6]),
                    $f('text', 'last_name',  'Last name',  ['required' => true, 'width' => 6]),
                    $f('date', 'dob',   'Date of birth', ['required' => true, 'width' => 6]),
                    $f('tel',  'phone', 'Phone',         ['required' => true, 'width' => 6]),
                    $f('email','email', 'Email',         ['width' => 6]),
                    $S('s_hist', 'Medical history', 'This helps us care for you safely.'),
                    $f('radio', 'has_allergies', 'Any allergies?', ['required' => true, 'options' => ['No', 'Yes']]),
                    $f('textarea', 'allergies', 'List your allergies', ['show_if' => ['field' => 'has_allergies', 'op' => 'eq', 'value' => 'Yes']]),
                    $f('textarea', 'medications', 'Current medications'),
                    $f('radio', 'smoker', 'Do you smoke?', ['options' => ['No', 'Occasionally', 'Daily']]),
                    $S('s_ins', 'Insurance', 'Skip if self-paying.'),
                    $f('radio', 'insured', 'Do you have insurance?', ['required' => true, 'options' => ['Yes', 'No']]),
                    $f('text', 'insurer',  'Insurance provider', ['width' => 6, 'show_if' => ['field' => 'insured', 'op' => 'eq', 'value' => 'Yes']]),
                    $f('text', 'policy_no', 'Policy number',     ['width' => 6, 'show_if' => ['field' => 'insured', 'op' => 'eq', 'value' => 'Yes']]),
                    $f('file', 'insurance_card', 'Photo of insurance card', ['show_if' => ['field' => 'insured', 'op' => 'eq', 'value' => 'Yes']]),
                    $S('s_consent', 'Consent', 'Please review and sign.'),
                    $f('checkbox',  'agree', 'I consent to treatment and confirm the above is accurate', ['required' => true]),
                    $f('signature', 'signature', 'Signature', ['required' => true]),
                ],
            ],

            // ── Finance: loan application with calculated estimate ──
            [
                'slug' => 'loan-application', 'name' => 'Loan application', 'icon' => 'tag', 'category' => 'Finance',
                'desc' => 'Multi-step application with a live monthly-payment estimate.',
                'badges' => ['3 steps', 'Calculated', 'Conditional'],
                'title' => 'Apply for a loan',
                'settings' => ['accent' => '#1D4ED8', 'density' => 'comfortable', 'field_style' => 'filled', 'shape' => 'rounded', 'rail' => true, 'animate' => true],
                'fields' => [
                    $S('s_app', 'Applicant', 'Your details.'),
                    $f('text',  'full_name', 'Full name', ['required' => true, 'width' => 6]),
                    $f('email', 'email',     'Email',     ['required' => true, 'width' => 6]),
                    $f('tel',   'phone',     'Phone',     ['width' => 6]),
                    $f('number','annual_income', 'Annual income (USD)', ['width' => 6, 'placeholder' => '60000']),
                    $S('s_loan', 'Loan details', 'How much, and for how long?'),
                    $f('select', 'purpose', 'Purpose', ['required' => true, 'options' => ['Auto', 'Home', 'Personal', 'Business', 'Education']]),
                    $f('number', 'amount', 'Amount requested (USD)', ['required' => true, 'width' => 6, 'placeholder' => '10000']),
                    $f('number', 'months', 'Term (months)', ['required' => true, 'width' => 6, 'placeholder' => '36']),
                    $f('calc',   'monthly', 'Rough monthly payment (USD, before interest)', ['formula' => '{amount} / {months}']),
                    $S('s_emp', 'Employment', 'Almost done.'),
                    $f('radio', 'employed', 'Are you employed?', ['required' => true, 'options' => ['Employed', 'Self-employed', 'Unemployed']]),
                    $f('text',  'employer', 'Employer', ['show_if' => ['field' => 'employed', 'op' => 'eq', 'value' => 'Employed']]),
                    $f('checkbox', 'consent', 'I authorise a credit check', ['required' => true]),
                ],
            ],

            // ── Events: wedding RSVP with conditional guest + meals ──
            [
                'slug' => 'wedding-rsvp', 'name' => 'Wedding RSVP', 'icon' => 'mail', 'category' => 'Events',
                'desc' => 'Elegant RSVP that branches into meals and plus-one details.',
                'badges' => ['Choice cards', 'Conditional'],
                'title' => 'You’re invited',
                'settings' => ['accent' => '#9333EA', 'density' => 'comfortable', 'field_style' => 'outline', 'shape' => 'pill', 'animate' => true],
                'fields' => [
                    $f('text',  'full_name', 'Your name', ['required' => true, 'width' => 6]),
                    $f('email', 'email',     'Email',     ['required' => true, 'width' => 6]),
                    $f('radio', 'attending', 'Will you attend?', ['required' => true, 'card' => true, 'options' => [
                        $o('Joyfully accepts', 'heart', 'Count me in'),
                        $o('Regretfully declines', 'mail', 'Can’t make it'),
                    ]]),
                    $f('radio', 'meal', 'Meal preference', ['card' => true, 'show_if' => ['field' => 'attending', 'op' => 'eq', 'value' => 'Joyfully accepts'], 'options' => [
                        $o('Chicken', 'check', ''),
                        $o('Fish', 'check', ''),
                        $o('Vegetarian', 'check', ''),
                    ]]),
                    $f('checkbox', 'plus_one', 'I’m bringing a guest', ['show_if' => ['field' => 'attending', 'op' => 'eq', 'value' => 'Joyfully accepts']]),
                    $f('text',  'guest_name', 'Guest’s name', ['width' => 6, 'show_if' => ['field' => 'plus_one', 'op' => 'filled', 'value' => '']]),
                    $f('select','guest_meal', 'Guest’s meal', ['width' => 6, 'options' => ['Chicken', 'Fish', 'Vegetarian'], 'show_if' => ['field' => 'plus_one', 'op' => 'filled', 'value' => '']]),
                    $f('textarea', 'song', 'Song request (optional)'),
                ],
            ],

            // ── Fitness: membership signup with plan cards + waiver ──
            [
                'slug' => 'membership', 'name' => 'Gym membership', 'icon' => 'users', 'category' => 'Fitness',
                'desc' => 'Plan cards, optional add-ons and a signed liability waiver.',
                'badges' => ['Choice cards', 'Conditional', 'E-signature'],
                'title' => 'Join the gym',
                'settings' => ['accent' => '#EA580C', 'density' => 'comfortable', 'field_style' => 'filled', 'shape' => 'pill', 'animate' => true],
                'fields' => [
                    $f('text',  'full_name', 'Full name', ['required' => true, 'width' => 6]),
                    $f('email', 'email',     'Email',     ['required' => true, 'width' => 6]),
                    $f('tel',   'phone',     'Phone',     ['width' => 6]),
                    $f('date',  'dob',       'Date of birth', ['width' => 6]),
                    $f('radio', 'plan', 'Membership plan', ['required' => true, 'card' => true, 'options' => [
                        $o('Basic', 'check', '$29/mo — gym floor'),
                        $o('Plus', 'star', '$49/mo — + classes'),
                        $o('Elite', 'shield', '$89/mo — + trainer'),
                    ]]),
                    $f('checkbox', 'pt_addon', 'Add personal training', ['width' => 6]),
                    $f('select', 'pt_sessions', 'Sessions per week', ['width' => 6, 'options' => ['1', '2', '3'], 'show_if' => ['field' => 'pt_addon', 'op' => 'filled', 'value' => '']]),
                    $f('checkbox',  'waiver', 'I have read and accept the liability waiver', ['required' => true]),
                    $f('signature', 'signature', 'Signature', ['required' => true]),
                ],
            ],

            // ── Real estate: inquiry that branches by intent ──────
            [
                'slug' => 'real-estate', 'name' => 'Property inquiry', 'icon' => 'box', 'category' => 'Real estate',
                'desc' => 'Buy / rent / sell flow with budget slider and conditional fields.',
                'badges' => ['Choice cards', 'Slider', 'Conditional'],
                'title' => 'Property inquiry',
                'settings' => ['accent' => '#0D9488', 'density' => 'comfortable', 'field_style' => 'outline', 'shape' => 'rounded', 'animate' => true],
                'fields' => [
                    $f('radio', 'intent', 'I want to…', ['required' => true, 'card' => true, 'options' => [
                        $o('Buy', 'tag', 'Find a home'),
                        $o('Rent', 'calendar', 'Lease a place'),
                        $o('Sell', 'box', 'List my property'),
                    ]]),
                    $f('select', 'property_type', 'Property type', ['width' => 6, 'options' => ['House', 'Apartment', 'Condo', 'Land', 'Commercial']]),
                    $f('number', 'bedrooms', 'Bedrooms', ['width' => 6, 'placeholder' => '3', 'show_if' => ['field' => 'intent', 'op' => 'ne', 'value' => 'Sell']]),
                    $f('range',  'budget', 'Budget (USD thousands)', ['options' => ['50', '2000', '50'], 'show_if' => ['field' => 'intent', 'op' => 'ne', 'value' => 'Sell']]),
                    $f('text',   'address', 'Property address', ['show_if' => ['field' => 'intent', 'op' => 'eq', 'value' => 'Sell']]),
                    $f('textarea','sell_details', 'Tell us about the property', ['show_if' => ['field' => 'intent', 'op' => 'eq', 'value' => 'Sell']]),
                    $f('text',  'full_name', 'Your name', ['required' => true, 'width' => 6]),
                    $f('email', 'email',     'Email',     ['required' => true, 'width' => 6]),
                    $f('tel',   'phone',     'Phone',     ['width' => 6]),
                ],
            ],

            // ── Business: contractor onboarding with docs + signature ──
            [
                'slug' => 'contractor-onboarding', 'name' => 'Vendor onboarding', 'icon' => 'package', 'category' => 'Business',
                'desc' => 'Collect company details, compliance docs and a signed agreement.',
                'badges' => ['3 steps', 'File upload', 'E-signature'],
                'title' => 'Vendor onboarding',
                'settings' => ['accent' => '#475569', 'density' => 'comfortable', 'field_style' => 'outline', 'shape' => 'square', 'rail' => true, 'animate' => true],
                'fields' => [
                    $S('s_co', 'Company', 'Tell us about your business.'),
                    $f('text',  'company', 'Company name', ['required' => true]),
                    $f('text',  'contact', 'Primary contact', ['required' => true, 'width' => 6]),
                    $f('email', 'email',   'Email',           ['required' => true, 'width' => 6]),
                    $f('tel',   'phone',   'Phone',           ['width' => 6]),
                    $f('url',    'website', 'Website',         ['width' => 6]),
                    $f('select', 'category', 'Service category', ['required' => true, 'options' => ['Construction', 'IT', 'Consulting', 'Logistics', 'Marketing', 'Other']]),
                    $S('s_docs', 'Compliance', 'Upload your documents.'),
                    $f('file', 'w9', 'Tax form (W-9)', ['required' => true]),
                    $f('file', 'coi', 'Certificate of insurance'),
                    $f('text', 'tax_id', 'Tax ID / EIN', ['width' => 6]),
                    $S('s_agree', 'Agreement', 'Review and sign to finish.'),
                    $f('checkbox',  'terms', 'I accept the vendor terms and code of conduct', ['required' => true]),
                    $f('signature', 'signature', 'Authorised signature', ['required' => true]),
                ],
            ],

        ];
    }

    /** Distinct category names, in first-seen order. */
    public static function categories(): array {
        $seen = [];
        foreach (self::all() as $t) {
            $c = (string)($t['category'] ?? 'Other');
            if (!in_array($c, $seen, true)) $seen[] = $c;
        }
        return $seen;
    }

    /** One template by slug, or null. */
    public static function get(string $slug): ?array {
        foreach (self::all() as $t) {
            if ($t['slug'] === $slug) return $t;
        }
        return null;
    }
}
