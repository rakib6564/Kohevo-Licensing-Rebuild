<?php
/**
 * Kohevo Studio — Site Insights: the Settings tab (administrators only; insights.php decides that and fills the variables).
 *
 * @var \Slate\Module\StudioBuilder\Insights\GoogleConnection    $connection
 * @var \Slate\Module\StudioBuilder\Insights\SettingsInsightsStore $store
 * @var list<array{url: string, level: string}>                  $sites
 * @var list<array{property: string, name: string, account: string}> $properties
 * @var string $listProblem
 * @var string $site
 * @var string $property
 * @var int    $tenantId
 */

declare(strict_types=1);

use Slate\Module\StudioBuilder\Http\StudioCodePolicy;
use Slate\Module\StudioBuilder\Insights\GoogleConnection;

$secure = slate_has_app_secret();
$host = strtolower((string) parse_url((string) SLATE_URL, PHP_URL_HOST));
$measurement = $store->get(GoogleConnection::MEASUREMENT_ID);
$siteTag = (string) Database::setting(StudioCodePolicy::SETTING_GA4, $tenantId);
?>

<?php if (!$secure): ?>
    <div class="alert alert-error sb-alert" role="alert"><?= e(__('studio_insights_no_secret', 'APP_SECRET is not set on this site, so Google credentials cannot be stored safely.')) ?></div>
<?php endif; ?>

<section class="sb-card sb-form-card" data-testid="insights-client">
    <div class="sb-card__head">
        <div>
            <div class="sb-card__title"><?= e(__('studio_insights_client', 'Your Google Cloud client')) ?></div>
            <p class="sb-card__sub"><?= e(__('studio_insights_client_sub', 'Kohevo reads your data with an OAuth client from your own Google Cloud project.')) ?></p>
        </div>
    </div>
    <?php if (!$connection->isConfigured()): ?>
        <ol class="sb-backup-list sb-insights-steps">
            <li><?= e(__('studio_insights_step_project', 'In Google Cloud Console, create a project.')) ?> <a href="https://console.cloud.google.com/projectcreate" target="_blank" rel="noopener"><?= e(__('studio_insights_open', 'Open')) ?></a></li>
            <li><?= e(__('studio_insights_step_apis', 'Enable these APIs: Google Search Console API, Google Analytics Data API, Google Analytics Admin API, PageSpeed Insights API.')) ?> <a href="https://console.cloud.google.com/apis/library" target="_blank" rel="noopener"><?= e(__('studio_insights_open', 'Open')) ?></a></li>
            <li><?= e(__('studio_insights_step_consent', 'Set up the OAuth consent screen as External, add your Google account as a test user, then publish it when it works.')) ?> <a href="https://console.cloud.google.com/auth/overview" target="_blank" rel="noopener"><?= e(__('studio_insights_open', 'Open')) ?></a></li>
            <li><?= e(__('studio_insights_step_client', 'Create an OAuth client ID of type Web application, with the redirect address below.')) ?> <a href="https://console.cloud.google.com/auth/clients" target="_blank" rel="noopener"><?= e(__('studio_insights_open', 'Open')) ?></a></li>
        </ol>
    <?php endif; ?>
    <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="_action" value="save_client">
        <div class="sb-field">
            <label class="sb-field__label" for="sb-redirect-uri"><?= e(__('studio_field_google_redirect', 'Redirect address')) ?></label>
            <input id="sb-redirect-uri" type="text" readonly value="<?= e($connection->redirectUri()) ?>" data-testid="redirect-uri">
            <p class="sb-field__hint"><?= e(__('studio_insights_redirect_hint', 'Paste this into Authorized redirect URIs on the OAuth client. It must match exactly.')) ?></p>
        </div>
        <div class="sb-form-grid">
            <div class="sb-field">
                <label class="sb-field__label" for="sb-client-id"><?= e(__('studio_field_google_client_id', 'Client ID')) ?></label>
                <input id="sb-client-id" type="text" name="client_id" value="<?= e($store->get(GoogleConnection::CLIENT_ID)) ?>" required<?= $secure ? '' : ' disabled' ?>>
            </div>
            <div class="sb-field">
                <label class="sb-field__label" for="sb-client-secret"><?= e(__('studio_field_google_client_secret', 'Client secret')) ?></label>
                <input id="sb-client-secret" type="password" name="client_secret" autocomplete="new-password" placeholder="<?= e($connection->isConfigured() ? __('studio_insights_secret_kept', 'Stored. Leave blank to keep it.') : '') ?>"<?= $secure ? '' : ' disabled' ?>>
            </div>
            <div class="sb-field">
                <label class="sb-field__label" for="sb-api-key"><?= e(__('studio_field_google_api_key', 'PageSpeed API key')) ?> <small><?= e(__('studio_insights_optional', '(optional)')) ?></small></label>
                <input id="sb-api-key" type="password" name="api_key" autocomplete="new-password" placeholder="<?= e($connection->hasApiKey() ? __('studio_insights_secret_kept', 'Stored. Leave blank to keep it.') : '') ?>"<?= $secure ? '' : ' disabled' ?>>
                <p class="sb-field__hint"><?= e(__('studio_insights_api_key_hint', 'Speed works without it, but Google limits shared use heavily.')) ?></p>
            </div>
        </div>
        <div class="sb-card-actions">
            <button type="submit" class="btn btn-primary"<?= $secure ? '' : ' disabled' ?>><?= e(__('save_changes', 'Save changes')) ?></button>
        </div>
    </form>
</section>

<?php if ($connection->isConfigured()): ?>
    <section class="sb-card sb-form-card" data-testid="insights-account">
        <div class="sb-card__head">
            <div>
                <div class="sb-card__title"><?= e(__('studio_insights_account', 'Google account')) ?></div>
                <p class="sb-card__sub"><?= e($connection->isConnected() ? sprintf(__('studio_insights_connected_as', 'Connected as %s'), $connection->email()) : __('studio_insights_account_sub', 'Sign in with the account that has access to your Search Console and Analytics.')) ?></p>
            </div>
        </div>
        <div class="sb-form-actions">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="connect">
                <button type="submit" class="btn btn-primary" data-testid="connect-google"><?= e($connection->isConnected() ? __('studio_insights_reconnect', 'Connect again') : __('studio_insights_connect', 'Connect with Google')) ?></button>
            </form>
            <?php if ($connection->isConnected()): ?>
                <form method="post" onsubmit="return confirm(<?= e((string) json_encode(__('studio_insights_disconnect_confirm', 'Disconnect this site from Google?'))) ?>)">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="disconnect">
                    <button type="submit" class="btn btn-outline"><?= e(__('studio_insights_disconnect', 'Disconnect')) ?></button>
                </form>
            <?php endif; ?>
        </div>
    </section>
<?php endif; ?>

<?php if ($connection->isConnected()): ?>
    <section class="sb-card sb-form-card" data-testid="insights-properties">
        <div class="sb-card__head">
            <div>
                <div class="sb-card__title"><?= e(__('studio_insights_properties', 'What to show')) ?></div>
                <p class="sb-card__sub"><?= e(__('studio_insights_properties_sub', 'Keep your existing properties and your history follows you when the site moves to Kohevo.')) ?></p>
            </div>
        </div>
        <?php if ($listProblem !== ''): ?>
            <?= sb_insights_empty($listProblem) ?>
        <?php else: ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="_action" value="save_choices">
                <div class="sb-form-grid">
                    <div class="sb-field">
                        <label class="sb-field__label" for="sb-search-site"><?= e(__('studio_field_google_search_site', 'Search Console property')) ?></label>
                        <select id="sb-search-site" name="search_site">
                            <option value=""><?= e(__('studio_insights_none', 'None')) ?></option>
                            <?php foreach ($sites as $option): $match = $site === '' && $host !== '' && str_contains($option['url'], preg_replace('/^www\./', '', $host)); ?>
                                <option value="<?= e($option['url']) ?>" <?= $site === $option['url'] || $match ? 'selected' : '' ?>><?= e($option['url']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="sb-field">
                        <label class="sb-field__label" for="sb-analytics-property"><?= e(__('studio_field_google_analytics_property', 'Analytics property')) ?></label>
                        <select id="sb-analytics-property" name="analytics_property">
                            <option value=""><?= e(__('studio_insights_none', 'None')) ?></option>
                            <?php foreach ($properties as $option): ?>
                                <option value="<?= e($option['property']) ?>" <?= $property === $option['property'] ? 'selected' : '' ?>><?= e($option['name'] . ' (' . $option['account'] . ')') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="sb-card-actions">
                    <button type="submit" class="btn btn-primary" data-testid="save-properties"><?= e(__('save_changes', 'Save changes')) ?></button>
                </div>
            </form>
        <?php endif; ?>
    </section>

    <?php if ($measurement !== ''): ?>
        <section class="sb-card sb-form-card" data-testid="insights-tag">
            <div class="sb-card__head">
                <div>
                    <div class="sb-card__title"><?= e(__('studio_insights_tag', 'Google tag')) ?></div>
                    <p class="sb-card__sub"><?= e($siteTag === $measurement
                        ? sprintf(__('studio_insights_tag_on', '%s loads on every public page.'), $measurement)
                        : ($siteTag !== ''
                            ? sprintf(__('studio_insights_tag_other', 'The site loads %1$s, but this property is measured by %2$s.'), $siteTag, $measurement)
                            : sprintf(__('studio_insights_tag_off', 'This site does not load %s yet, so Analytics sees no visits to it.'), $measurement))) ?></p>
                </div>
            </div>
            <?php if ($siteTag !== $measurement): ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="_action" value="use_tag">
                    <button type="submit" class="btn btn-primary" data-testid="use-tag"><?= e(sprintf(__('studio_insights_use_tag', 'Load %s on this site'), $measurement)) ?></button>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>
<?php endif; ?>
