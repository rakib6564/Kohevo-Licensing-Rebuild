# Site Insights: connecting a Kohevo site to Google

Site Insights (Studio › Search › Site Insights) shows Search Console, Analytics 4 and PageSpeed data in the admin, like
Google Site Kit on WordPress.

## How the connection works

Site Kit signs in through a proxy that only Google runs, for WordPress plugins only. Kohevo has its own equivalent: **Kohevo
Central holds one Google OAuth client for every licensed site**. A site owner presses Sign in with Google and sets up nothing
in Google Cloud.

1. The site asks Central to start, server to server, showing its licence key, installation id and domain.
2. Central checks them: a live licence, an installation already bound to that domain, and a return address that is the Insights
   callback on that domain. It answers with Google's consent address, carrying a state it signed, which expires in 10 minutes.
3. The person agrees at Google. Google returns to Central, which checks the signature and trades the code using the secret.
4. Central sends the browser back to the site with a one-time handoff, never a token. The site claims the tokens server to
   server, with its licence. A handoff lasts 2 minutes, works once, and only for its own installation.
5. The site lists the person's properties and picks the ones matching its domain. If it can't tell, it shows the lists.

Afterwards the site keeps its tokens, encrypted, and Central keeps none. When the access token ends, the site asks Central to
refresh it, so **a licence that is suspended or expired stops refreshing**, and if Central is down the panels fail once the
current token ends, within an hour.

- Access is read-only. The scopes are `webmasters.readonly`, `analytics.readonly`, `openid` and `email`.
- Sites never see the client secret. It lives in Central's environment only.
- The tokens, and any secret or key on a site, are encrypted with `APP_SECRET` in the tenant's settings. They are never rendered
  or logged, are not in a Studio backup, and `bin/rekey-secrets.php` rotates them with the rest.
- Reports are cached in `studiobuilder_google_cache` for an hour, PageSpeed for six. Refresh empties the cache.
- PageSpeed has no per-site key on this path: the sign-in identifies the request, so it spends the Central project's quota.

`APP_SECRET` must be set on the site, or the screen refuses to store anything.

## Setting up the one Central client (once, by Kohevo)

Sign in to <https://console.cloud.google.com> with an account Kohevo will keep.

### 1. Create a project

<https://console.cloud.google.com/projectcreate>, for example `Kohevo Site Insights`.

### 2. Enable four APIs

In <https://console.cloud.google.com/apis/library>, with that project selected, enable:

| API | Used for |
| --- | --- |
| Google Search Console API | clicks, impressions, queries |
| Google Analytics Data API | visitors, pages, sources |
| Google Analytics Admin API | listing properties, finding the G- tag |
| PageSpeed Insights API | the Speed tab |

### 3. Set up the consent screen

<https://console.cloud.google.com/auth/overview>

1. Audience: **External**.
2. App name `Kohevo`, support email, developer email, and on Branding: homepage, privacy policy, and Kohevo's domain under
   authorized domains.
3. Data access: add `.../auth/webmasters.readonly` and `.../auth/analytics.readonly`.
4. While the app is in **Testing**, add each Google account that will connect under Test users.
5. When it works, press **Publish app**.

### What Google's limits mean with one shared client

These limits apply to the whole client, so to every Kohevo site together.

- **Testing:** at most 100 listed test users, and each grant ends after **7 days**. Sites then ask to connect again.
- **Published, not verified:** every user sees "Google hasn't verified this app" and must choose Advanced › Go to Kohevo.
  The 100-user cap still applies, across all sites.
- **Verified:** no warning and no such cap. Google reviews the app: a homepage and privacy policy on a domain you own, a
  written reason for each scope, and a demo video of the consent flow. Allow weeks, and start before selling this to clients.

### 4. Create the OAuth client

<https://console.cloud.google.com/auth/clients> › Create client

- Application type: **Web application**
- Authorized redirect URIs: `https://<central>/licensing/google/callback`, one address for all sites. Sites are not listed.

Keep the Client ID and Client secret. Google shows the secret in full only once.

### 5. Put it on Central

In Central's `.env`, never in the repository:

```
GOOGLE_OAUTH_CLIENT_ID=...
GOOGLE_OAUTH_CLIENT_SECRET=...
```

`APP_SECRET` must be set on Central too: it signs the state and seals tokens in transit.

## On a Kohevo site

1. Studio › Site Insights opens on Settings. Press **Sign in with Google**, using an account that can read the Search Console and
   Analytics properties. Leave every permission ticked.
2. If your properties were matched, your data appears. Otherwise choose them from the lists on Settings.
3. If the card says the site does not load the Google tag, press the button to load it. It fills the same setting as
   Tracking & code.

The site needs `LICENSE_KEY`, `LICENSE_PRODUCT` and `LICENSE_SERVER_URL`, and a completed licence check-in, because Central only
accepts an installation already bound to the domain. `APP_URL` must be on that licensed domain.

## Advanced: a self-hosted site with its own client

A site without a Kohevo licence, or one that wants its own client, opens **Advanced: use your own Google client** on Settings.
Follow steps 1 to 4 above in your own Google Cloud project, with two differences: the redirect address is the one shown on the
Settings tab, on the site itself, and it follows the site's `APP_URL`. Then paste the Client ID and secret, save, and press
**Connect with my own client**. The site then talks to Google directly and Central is not involved.

For the Speed tab on this path, create an API key at <https://console.cloud.google.com/apis/credentials>, restrict it to
**PageSpeed Insights API**, leave Application restrictions at None, and paste it next to the secret. Speed works without it, but
Google limits shared use heavily.

## Moving a site from WordPress

Choose the same Search Console and Analytics properties the old site uses, and the history carries on. Before the move, keep
the old tag running and do not load the tag on a staging copy that should not count as traffic. Load it when the domain
points at Kohevo. Connecting from a staging address works if that address is licensed, because reports follow the chosen
properties, not the address the admin is on. It won't match them by name, so choose them on Settings.

## When something is wrong

| The screen says | Cause | Fix |
| --- | --- | --- |
| Kohevo could not sign this site in | licence inactive, installation not bound to this domain, or no client on Central | check the licence and its domain, run a licence check-in, or check Central's `.env` |
| Google did not confirm the connection | own client: wrong Client ID or secret, or the redirect address differs. Central: Central's redirect URI differs | compare with the client in Google Cloud. `redirect_uri_mismatch` on Google's page means the same |
| Google no longer accepts this connection | grant revoked or expired, usually the 7-day Testing limit | publish the consent screen, then Connect again |
| This API is not enabled | one of the four APIs is off | enable it, wait a minute, Refresh |
| This Google account has no access | the account lost the property | add it in Search Console or Analytics, or connect another account |
| Google's limit is reached | quota, most often PageSpeed | on Central, raise the project's PageSpeed quota. On your own client, add the API key |

The log has one line per failure, starting `[studio-builder] site insights:`, with the HTTP status and reason only.

## Trying it without Google

`tests/fixtures/fake-google.php` answers like Google, with made-up figures:

```bash
php -S 127.0.0.1:9191 tests/fixtures/fake-google.php
```

Add `STUDIO_GOOGLE_FAKE_URL=http://127.0.0.1:9191` to a sandbox's `.env`. Only a loopback address is honoured, so this cannot
send a token to another host. Set `FAKE_GOOGLE_FAIL` to `disabled`, `quota` or `revoked` to see the error states.

To try the Central path, `02-licensing/tests/fixtures/google-broker-sandbox.php` runs the real broker with one made-up licence;
its header has the command. Point a sandbox at it with `STUDIO_GOOGLE_BROKER_URL`.
