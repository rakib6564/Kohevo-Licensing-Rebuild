# Site Insights: connecting a Kohevo site to Google

Site Insights (Studio › Search › Site Insights) shows Search Console, Analytics 4 and PageSpeed data in the admin, like
Google Site Kit on WordPress.

## How the connection works

Site Kit signs in through a proxy that only Google runs, and that proxy accepts WordPress plugins only. So a Kohevo site
signs in with an OAuth client from a Google Cloud project, the same model as Booking's Google Calendar sync.

- The site talks to Google directly. No Kohevo server sits in the middle.
- One Google Cloud project and one OAuth client can serve many sites: list each site's redirect address on the client.
  Copy the Client ID and secret into each site.
- Access is read-only. The scopes are `webmasters.readonly`, `analytics.readonly`, `openid` and `email`.
- The client secret, the PageSpeed key and both tokens are encrypted with `APP_SECRET` and stored in the tenant's settings.
  They are never rendered or logged, are not in a Studio backup, and `bin/rekey-secrets.php` rotates them with the rest.
- Reports are cached in `studiobuilder_google_cache` for an hour, PageSpeed for six. Refresh empties the cache.

`APP_SECRET` must be set on the site, or the screen refuses to store anything.

## What to create in Google Cloud

Sign in to <https://console.cloud.google.com> with an account you will keep. Kohevo's own account suits many client sites.

### 1. Create a project

<https://console.cloud.google.com/projectcreate>. Any name, for example `Kohevo Site Insights`.

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

1. Audience: **External** (or Internal, if the project belongs to a Google Workspace and only its users connect).
2. App name, support email and developer email.
3. Data access: add `.../auth/webmasters.readonly` and `.../auth/analytics.readonly`.
4. While the app is in **Testing**, add each Google account that will connect under Test users.
5. When it works, press **Publish app** to move it to **In production**.

In Testing, Google ends a grant after **7 days**: Site Insights then asks you to connect again. Publishing ends that limit.
Both read-only scopes count as sensitive, so a published app that Google has not verified shows a warning on sign-in
("Google hasn't verified this app", then Advanced › Go to the app) and is capped at 100 users. That is fine for your own
sites. Offering this to clients at scale needs Google's app verification, which needs a homepage and a privacy policy on a
domain you own, and a demo video.

### 4. Create the OAuth client

<https://console.cloud.google.com/auth/clients> › Create client

- Application type: **Web application**
- Authorized redirect URIs: the **Redirect address** shown on the site's Settings tab. It looks like
  `https://example.com/plugins/studio-builder/admin/insights-callback.php`. It must match exactly, including `https`, `www`
  and any subfolder, and it follows the site's `APP_URL`.

Keep the Client ID and Client secret. Google shows the secret in full only once.

### 5. Create a PageSpeed key (recommended)

<https://console.cloud.google.com/apis/credentials> › Create credentials › API key. Under API restrictions choose
**PageSpeed Insights API**. Leave Application restrictions at None: a website restriction blocks calls made from a server.

## On the Kohevo site

1. Studio › Site Insights › Settings: paste the Client ID, secret and key, and save.
2. Connect with Google, using an account that can read the Search Console property and the Analytics property. Leave every
   permission ticked.
3. Choose the Search Console property and the Analytics property, and save.
4. If the card says the site does not load the Google tag, press the button to load it. It fills the same setting as
   Tracking & code.

## Moving a site from WordPress

Choose the same Search Console and Analytics properties the old site uses, and the history carries on. Before the move, keep
the old tag running and do not load the tag on a staging copy that should not count as traffic. Load it when the domain
points at Kohevo. Connecting from a staging address works, because reports follow the chosen properties, not the address the
admin is on. The redirect address, though, is per site, so staging and live each need their own entry on the client.

## When something is wrong

| The screen says | Cause | Fix |
| --- | --- | --- |
| Google did not confirm the connection | wrong Client ID or secret, or the redirect address differs | compare with the Settings tab. `redirect_uri_mismatch` on Google's page means the same |
| Google no longer accepts this connection | grant revoked or expired, usually the 7-day Testing limit | publish the consent screen, then Connect again |
| This API is not enabled | one of the four APIs is off | enable it, wait a minute, Refresh |
| This Google account has no access | the account lost the property | add it in Search Console or Analytics, or connect another account |
| Google's limit is reached | quota, most often PageSpeed without a key | add the API key |

The log has one line per failure, starting `[studio-builder] site insights:`, with the HTTP status and reason only.

## Trying it without Google

`tests/fixtures/fake-google.php` answers like Google, with made-up figures:

```bash
php -S 127.0.0.1:9191 tests/fixtures/fake-google.php
```

Add `STUDIO_GOOGLE_FAKE_URL=http://127.0.0.1:9191` to a sandbox's `.env`. Only a loopback address is honoured, so this cannot
send a token to another host. Any Client ID ending in `.apps.googleusercontent.com` works. Set `FAKE_GOOGLE_FAIL` to
`disabled`, `quota` or `revoked` to see the error states.
