## fix(security): fail-closed OAuth secrets, encrypted translate key, vetted AI URL

Branch `fix/secret-handling-gaps`, based on `origin/main` `ba4c9a5`.

- `45eff7a`: the three fixes below.
- `6fed518`: stricter AI URL ranges, plus no `CURLOPT_RESOLVE` pin for IP-literal hosts.

Both trees (`01-client`, `02-licensing`) are changed identically: 28 files, +976 / −54 against `origin/main`.

### Scope

| Area | Before | After |
|---|---|---|
| **SMTP OAuth** (`admin/oauth_callback.php`, `includes/SmtpOAuth.php`) | The callback set `smtp_auth_type=xoauth2` first, then encrypted. A missing `APP_SECRET` threw halfway, leaving a half-configured mailer behind a 500. There was also a plaintext fallback when the helper was absent. | `SmtpOAuth::sealSecrets()` encrypts the client secret, refresh token and access token **before any write**. If any of them can't be encrypted, nothing is stored and the admin is told why. Refreshed or rotated tokens are cached only when they can be encrypted; otherwise they are used for that one request only. |
| **Multilang auto-translate key** (`multilang-translate`) | No write path. The key was read raw from settings. | A write-only password field: blank keeps the stored key, a checkbox clears it explicitly. It saves through the new `save_autotranslate_key` action (POST only; the file's CSRF gate, `mlt.manage`, an audit log entry). The key is stored as an `enc:v1:` envelope, refused with a 422 when encryption is unavailable, and decrypted only for the provider call. Legacy plaintext keys keep working and are upgraded on the next save. The key is never echoed or rendered. |
| **AI base URL** (`mcp-gateway`) | Any URL was saved and called. | `AiProviderClient::vetBaseUrl()` requires https and no credentials. Every resolved address (A and AAAA) must be public. Refused: private, loopback, link-local and reserved ranges, CGNAT `100.64/10`, `198.18/15`, multicast, NAT64 `64:ff9b::/96` and `64:ff9b:1::/48`, 6to4 `2002::/16`, Teredo `2001::/32`, `ff00::/8`, `100::/64`. A refused URL on save writes nothing from the form. At call time the URL is vetted again and curl is pinned to the vetted IP (`CURLOPT_RESOLVE`, which closes DNS rebinding), with https as the only protocol and redirects off. |

### Security notes

- **Resolver override:** `vetBaseUrl()` takes a resolver argument for tests. The only production callers are `ai-settings.php` and `AiProviderClient::chat()`, and neither passes one, so no request input can reach it.
- **Shorthand IPv4:** forms like `127.1`, `0177.0.0.1`, `2130706433` and `0x7f.1` are resolved by the system resolver to `127.0.0.1` and refused (checked against real DNS).
- **Test helper:** the only change is that `tests/fixtures/admin-page-post-probe.php` now sets `$_REQUEST = array_merge($_GET, $_POST)`, matching PHP's default `request_order`. No production authorization or tenant code is touched.

### Tests

New or changed tests, mirrored in both trees except where marked:

- `AiProviderClientUrlVettingTest` (unit, 5 cases)
- `MltAutoTranslateKeyTest` (unit, 3 cases; the no-`APP_SECRET` case runs in a child process)
- `SmtpOAuthSealSecretsTest` (integration, 2 cases)
- `SecretHandlingGapsTest` (client integration, 3 cases, driven through the real admin pages)
- `SmtpOAuthAccessTokenEncryptionTest`, updated to require the sealed flow

Results on this branch against `origin/main` `ba4c9a5`, run locally on 2026-10-17. Each suite has the same set of failures as `origin/main`; no new failures.

| Suite | `origin/main` | Branch |
|---|---|---|
| Client unit | 977/983 | 985/991 |
| Licensing unit | 426/430 | 434/438 |
| Client integration | 608/631 | 613/636 |
| Licensing integration, one process per file | 349/403 | 351/405 (at `45eff7a`) |

The licensing integration suite still has **54 failures on this branch, all present on `origin/main`**. It is not passing. The CI subset of that suite passes.

Combined with the audit branch (`integration/secrets-and-audit`), every client and licensing unit test and every client integration test passes. See `04-test-results.md`.

Mutation checks: each of the four fixes, and the new range check, was broken on purpose; its test failed and the code was then restored. That is 9 mutations in all, re-run on the integrated tree.

### Limitations

- CI does not run `tests/unit/run.php` or `tests/integration/run.php` in either tree, so none of these tests run in CI. They were run locally only.
- No live Google or Microsoft OAuth exchange, translation provider or AI provider was called. The network paths are covered with injected DNS and child processes.
- If HTTPS proxy environment variables are set, curl sends the request through the proxy, and the proxy does its own DNS lookup, so the pin does not apply.
- `multilang-translate.autotranslate_endpoint` has no admin write path and is not vetted. The decrypted key is sent to whatever endpoint is stored in the database.
- Rotating `APP_SECRET` makes every stored envelope unreadable; there is no re-key tool.

### Merge order

Merge this PR first. `audit/settings-secrets-hardening` then conflicts only in `lang/fr.php` (both trees; resolve by keeping both sets of keys). `mcp-gateway/admin/ai-settings.php` merges cleanly: the audit's try/catch on key encryption sits inside this PR's "nothing saved if the URL is refused" guard.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
