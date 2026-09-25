<?php
/**
 * Slate — shared behaviour for the customer auth pages.
 *
 * login.php and register.php had drifted apart: register got the multi-step
 * wizard and the solid input borders, login kept a plain single form. The
 * layout shells are chosen per page (both now use 'auth-split'); the two
 * behaviours that must match on every auth screen live here.
 *
 *   1. Password reveal — a text-labelled Show/Hide toggle inside the field.
 *      Typing a password you cannot see is the single biggest cause of a
 *      failed sign-in, and the register wizard asks for it twice.
 *   2. Submit latch — disables re-submission and shows a spinner, so a
 *      double-click can't post the credentials or the registration twice.
 *
 * Include after the form markup, before partials/footer.php.
 */

if (!defined('SLATE_ROOT')) { exit; }

if (!function_exists('slate_auth_ui')) {
    function slate_auth_ui(): void
    {
        if (defined('SLATE_AUTH_UI_EMITTED')) { return; }
        define('SLATE_AUTH_UI_EMITTED', true);
        ?>
        <style>
        .pw-wrap { position: relative; }
        /* Reserve room for the toggle so a long password never runs under it. */
        .pw-wrap input { padding-right: 62px; width: 100%; }
        .pw-toggle {
            position: absolute; right: 6px; top: 50%; transform: translateY(-50%);
            border: 0; background: transparent; cursor: pointer;
            font: inherit; font-size: 12px; font-weight: 600;
            color: var(--muted); padding: 6px 8px; border-radius: 7px;
        }
        .pw-toggle:hover { color: var(--accent); background: var(--surface-2); }
        .pw-toggle:focus-visible { outline: 2px solid var(--accent); outline-offset: 1px; }

        .btn.is-busy { position: relative; pointer-events: none; color: transparent !important; }
        .btn.is-busy::after {
            content: ""; position: absolute; inset: 0; margin: auto;
            width: 15px; height: 15px; border-radius: 50%;
            border: 2px solid var(--on-accent); border-top-color: transparent;
            animation: auth-spin .6s linear infinite;
        }
        @keyframes auth-spin { to { transform: rotate(360deg); } }
        @media (prefers-reduced-motion: reduce) { .btn.is-busy::after { animation-duration: 2s; } }
        </style>
        <script>
        (function () {
            var SHOW = <?= json_encode(__('show', 'Show')) ?>, HIDE = <?= json_encode(__('hide', 'Hide')) ?>;

            document.querySelectorAll('.auth-card input[type=password]').forEach(function (input) {
                if (input.dataset.pwWrapped) return;
                input.dataset.pwWrapped = '1';

                var wrap = document.createElement('div');
                wrap.className = 'pw-wrap';
                input.parentNode.insertBefore(wrap, input);
                wrap.appendChild(input);

                var btn = document.createElement('button');
                btn.type = 'button';           // never submits the form
                btn.className = 'pw-toggle';
                btn.textContent = SHOW;
                btn.setAttribute('aria-controls', input.id || '');
                btn.setAttribute('aria-pressed', 'false');
                btn.addEventListener('click', function () {
                    var shown = input.type === 'text';
                    input.type = shown ? 'password' : 'text';
                    btn.textContent = shown ? SHOW : HIDE;
                    btn.setAttribute('aria-pressed', shown ? 'false' : 'true');
                    input.focus();
                });
                wrap.appendChild(btn);
            });

            document.addEventListener('submit', function (e) {
                var form = e.target;
                if (!form || form.method.toLowerCase() !== 'post') return;
                // The register wizard validates in its own submit handler and
                // cancels the event on a bad step. Latching before checking that
                // would leave the form permanently unsubmittable.
                if (e.defaultPrevented) return;
                if (form.dataset.authSubmitted) { e.preventDefault(); return; }
                form.dataset.authSubmitted = '1';
                var btn = form.querySelector('button[type=submit]');
                if (btn) { btn.classList.add('is-busy'); btn.setAttribute('aria-busy', 'true'); }
            });
        })();
        </script>
        <?php
    }
}
