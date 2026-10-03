<?php
/**
 * Kohevo Studio — Create a complete modern Freelancer Portfolio website.
 *
 * Bootstraps the Studio Freelancer Portfolio plugin, registers custom widgets,
 * constructs the elegant portfolio canonical document, and saves/publishes it.
 *
 * Run:  php bin/create-freelancer-portfolio.php [--homepage] [--tenant=ID]
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from CLI: php bin/create-freelancer-portfolio.php\n");
    exit(1);
}

require __DIR__ . '/../config.php';

use Slate\Data\Database;
use Slate\Module\StudioBuilder\Application\StudioActor;
use Slate\Module\StudioBuilder\Runtime\StudioRuntimeFactory;
use Slate\Module\StudioBuilder\StudioPermissions;
use Slate\Tenancy\TenantContext;

// Parse flags
$isHomepage = in_array('--homepage', $argv, true);
$tenantId   = (int) (defined('TENANT_ID') ? TENANT_ID : 1);
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--tenant=')) {
        $tenantId = (int) substr($arg, 9);
    }
}

echo "=== Kohevo Studio: Freelancer Portfolio Generator ===\n";
echo "Tenant ID: {$tenantId}\n";

// 1. Ensure portfolio plugin is booted
require_once dirname(__DIR__) . '/plugins/studio-freelancer-portfolio/StudioFreelancerPortfolio.php';

$portfolioPlugin = new StudioFreelancerPortfolio(
    'studio-freelancer-portfolio',
    ['version' => '1.0.0'],
    dirname(__DIR__) . '/plugins/studio-freelancer-portfolio'
);
$portfolioPlugin->boot();

// 2. Initialize Runtime
$rt = StudioRuntimeFactory::build();
$adminUser = Database::row('SELECT id FROM users WHERE tenant_id = ? ORDER BY id ASC LIMIT 1', [$tenantId]);
$userId = (int) ($adminUser['id'] ?? 1);
$actor = StudioActor::authenticated($userId, StudioPermissions::ALL);

try {
    $res = $rt->tenants->runAs($tenantId, static function () use ($rt, $actor, $isHomepage, $tenantId): array {
        $existingPage = $rt->pages->findBySlug('portfolio', 'page');
        $routeMode = $isHomepage ? 'homepage' : 'standalone';

        if ($existingPage !== null) {
            $pageId = (int) $existingPage['id'];
            $revId  = (int) ($existingPage['active_draft_revision_id'] ?? $existingPage['working_revision_id'] ?? 0);
            echo "Found existing portfolio page (ID: {$pageId})\n";
            if ($isHomepage && ($existingPage['route_mode'] ?? '') !== 'homepage') {
                $rt->app->updatePageAddress($actor, $pageId, ['route_mode' => 'homepage']);
                echo "Updated route_mode to 'homepage'\n";
            }
        } else {
            $created = $rt->app->createPage(
                $actor,
                'Elena Rostova — Portfolio',
                'portfolio',
                'page',
                $routeMode
            );
            $pageId = (int) $created['page']['id'];
            $revId  = (int) $created['revision']['id'];
            echo "Created new portfolio page (ID: {$pageId})\n";
        }

        // Generate full canonical document
        $doc = StudioFreelancerPortfolio::getPortfolioDocument();

        // Save Draft
        $saved = $rt->app->saveDraft($actor, $pageId, $doc, $revId, 'manual', 'Build complete modern freelancer portfolio');
        $newRevId = (int) $saved['revision']['id'];
        echo "Saved draft revision (ID: {$newRevId})\n";

        // Publish
        $published = $rt->app->publish($actor, $pageId, $newRevId, 'Publish modern freelancer portfolio website');
        echo "Published revision (ID: {$newRevId})\n";

        // Test Public Render
        $path = $isHomepage ? '/' : '/portfolio';
        $rendered = $rt->publicRuntime->handlePath($path);

        return [
            'page_id'     => $pageId,
            'revision_id' => $newRevId,
            'path'        => $path,
            'status'      => $rendered?->status ?? 0,
            'bytes'       => strlen($rendered?->body ?? ''),
        ];
    });

    echo "✓ Successfully deployed portfolio website!\n";
    echo "Page ID:     {$res['page_id']}\n";
    echo "Revision ID: {$res['revision_id']}\n";
    echo "Path:        {$res['path']}\n";
    echo "HTTP Status: {$res['status']}\n";
    echo "HTML Size:   {$res['bytes']} bytes\n";
    echo "\nSections included:\n";
    echo "  1. Hero with Live Status Pill, Bold Typography & Stat Highlights\n";
    echo "  2. Selected Flagship Work (4 Project Case Study Cards with Metric Badges)\n";
    echo "  3. Areas of Expertise (3 Premium Service Cards)\n";
    echo "  4. Technical Stack & Technologies Grid\n";
    echo "  5. Client Endorsements (portfolio.testimonial_card quotes)\n";
    echo "  6. Project Inquiry / Consultation Contact Form (core.form)\n";
} catch (\Slate\Module\StudioBuilder\Exception\StudioEntitlementException $e) {
    fwrite(STDERR, "Notice: Tenant {$tenantId} is not currently entitled for 'studio-builder'.\n");
    fwrite(STDERR, "To deploy in production, ensure your license includes the 'studio-builder' entitlement.\n");
    exit(1);
} catch (\Throwable $e) {
    fwrite(STDERR, "Error generating portfolio: " . $e->getMessage() . "\n");
    exit(1);
}
