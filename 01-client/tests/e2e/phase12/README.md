# Phase 12 end-to-end harness

Real installer / licensing flows over HTTP against two `php -S` servers:
a throwaway copy of the central server (`127.0.0.1:8091`) and of the client
(`127.0.0.1:8092`), each on a **fresh** database (`p12_central`,
`p12_client` — dropped and recreated on every run). The checkout's own
`.env`, `.installed` and test databases are never touched.

```bash
01-client/tests/e2e/phase12/run.sh
```

Environment: `P12_WORKDIR` (default `$TMPDIR/kohevo-p12-e2e`),
`P12_DB_USER` / `P12_DB_PASS` (MySQL on 127.0.0.1, default `root` / empty).
Needs `php` (sodium, pdo_mysql, curl), `mysql`, `rsync`, `openssl`.

| Scenario | What it drives |
| :--- | :--- |
| `scenario_install.php` | Fresh install step by step: DB config, install, installation identity, key rejection paths (invalid, malformed, suspended, bound elsewhere, revoked), central down, activation, admin, finish, entitled plugins, dashboard, unattended check-in |
| `scenario_runtime.php` | Lifecycle on the central server → real `bin/license-check.php` → HTTP: module downgrade/upgrade per module, suspend/unsuspend, expiring-soon, grace, beyond grace, renew, extend, central outage, cache tampering per column, revoke; lock matrix (admin, public, plugin PHP, API, AJAX, whitelist, cron, platform page) |
| `scenario_central_authz.php` | Central admin: anonymous, no `licensing.manage`, forged CSRF, valid CSRF, Core/unknown modules, IDOR, revoked-is-terminal, event history |
| `scenario_expired_activation.php` | First activation of a license issued with a past expiry (Phase 12 D2) |
| `scenario_reinstall.php` | Bound installation reinstalling while suspended / revoked / expired (Phase 12 D1) |
| `scenario_stress.php` | Concurrent activation (12 installations; 12 × same installation), 300 refreshes, 300 unknown-key requests |
| `perf_client.php` | Informational per-request timings on the client |

`central-modules-fixture.php` is copied into the throwaway central only; it
grants/revokes a module on a license through `LicenseService`.
