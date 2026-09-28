# Security Policy

## Reporting a vulnerability

**Please do not open a public issue.** Report privately through GitHub:
**Security → Report a vulnerability** on this repository. Include the affected app (`01-client` or
`02-licensing`), the version (`VERSION`), reproduction steps and the impact you expect.

You will get an acknowledgement, and we will keep you informed while a fix is prepared.

## Supported versions

Only the latest release (see [`VERSION`](VERSION) and [`CHANGELOG.md`](CHANGELOG.md)) receives security fixes.

## Handling of secrets

- Credentials are read from the environment (`.env`, git-ignored) or, for runtime settings such as SMTP, from the
  database where they are **encrypted at rest** with `APP_SECRET`. Nothing real belongs in the repository.
- The central server's Ed25519 secret key is encrypted at rest; only the public key is ever distributed.
- If a credential is ever exposed (in a commit, chat, log or ticket), **rotate it** — deleting the message is not
  enough — then update the affected `.env` / settings.

Application-specific configuration notes: [`01-client/SECURITY.md`](01-client/SECURITY.md) and
[`02-licensing/SECURITY.md`](02-licensing/SECURITY.md).
