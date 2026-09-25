<?php
/**
 * Slate — CustomerPortal Service.
 *
 * Canonical customer portal platform service coordinating:
 * - Single global navigation (Home, Book, Activity, Account, active plugin links)
 * - Feature-local contextual subnavigation (Membership, Coaching, Clientdesk, Studio)
 * - Composed dashboard widget aggregation with customer eligibility
 * - Normalized activity events timeline
 * - Centralized account sections
 *
 * Layer: Services / Presentation bridge.
 */

declare(strict_types=1);

namespace Slate\Services\Portal;

use Slate\Data\Database;
use Slate\Kernel\Event\Hook;
use Slate\Kernel\Module\PluginLoader;
use Slate\Presentation\CustomerNav;
use Slate\Services\Auth\Auth;

final class CustomerPortal
{
    private static ?self $currentInstance = null;
    private ?int $customerId;
    private ?array $customer;

    public function __construct(?int $customerId = null, ?array $customer = null)
    {
        $this->customerId = $customerId;
        $this->customer   = $customer;

        if ($this->customer === null && $this->customerId !== null && $this->customerId > 0) {
            if (defined('DB_HOST')) {
                try {
                    $tid = function_exists('current_tenant_id') ? current_tenant_id() : 1;
                    $row = Database::row(
                        "SELECT * FROM customers WHERE id = ? AND tenant_id = ?",
                        [$this->customerId, $tid]
                    );
                    $this->customer = is_array($row) ? $row : null;
                } catch (\Throwable $e) {
                    $this->customer = null;
                }
            }
        } elseif ($this->customer === null) {
            $this->customer = class_exists(Auth::class) ? Auth::customer() : null;
            if ($this->customer) {
                $this->customerId = (int) ($this->customer['id'] ?? 0);
            }
        }
    }

    public static function forCustomer(?int $customerId, ?array $customer = null): self
    {
        $instance = new self($customerId, $customer);
        self::$currentInstance = $instance;
        return $instance;
    }

    public static function current(): self
    {
        if (self::$currentInstance !== null) {
            return self::$currentInstance;
        }
        $cid = class_exists(Auth::class) ? Auth::customerId() : null;
        self::$currentInstance = new self($cid !== null ? (int) $cid : null);
        return self::$currentInstance;
    }

    public function customer(): ?array
    {
        return $this->customer;
    }

    public function customerId(): ?int
    {
        return $this->customerId;
    }

    /**
     * Resolve global navigation items for the portal top bar & mobile bar.
     *
     * @return array{items: array, groups: array, tabs: array, more: array}
     */
    public function navigation(?string $activeSlug = null): array
    {
        $slateUrl = defined('SLATE_URL') ? rtrim(SLATE_URL, '/') : '';

        // Core global destinations
        $core = [
            [
                'slug'  => 'home',
                'label' => function_exists('__') ? __('portal_home', 'Home') : 'Home',
                'href'  => $slateUrl . '/member',
                'icon'  => 'home',
                'order' => 10,
                'group' => 'main',
            ],
            [
                'slug'  => 'book',
                'label' => function_exists('__') ? __('book_now', 'Book') : 'Book',
                'href'  => $slateUrl . '/member/book',
                'icon'  => 'calendar',
                'order' => 20,
                'group' => 'main',
            ],
            [
                'slug'  => 'activity',
                'label' => function_exists('__') ? __('portal_activity', 'Activity') : 'Activity',
                'href'  => $slateUrl . '/member/activity',
                'icon'  => 'clipboard',
                'order' => 30,
                'group' => 'main',
            ],
            [
                'slug'  => 'account',
                'label' => function_exists('__') ? __('portal_account', 'Account') : 'Account',
                'href'  => $slateUrl . '/member/account',
                'icon'  => 'user',
                'order' => 990,
                'group' => 'main',
            ],
        ];

        // Gather plugin contributions through customer_portal_nav first, then legacy customer_nav_items
        $raw = $core;
        if (class_exists(Hook::class)) {
            $context = [
                'customer_id' => $this->customerId,
                'customer'    => $this->customer,
                'portal'      => $this,
            ];
            $raw = Hook::applyFilters('customer_portal_nav', $raw, $context);
            if (!is_array($raw)) {
                $raw = $core;
            }
            $raw = Hook::applyFilters('customer_nav_items', $raw);
            if (!is_array($raw)) {
                $raw = $core;
            }
        }

        // Canonical URL normalizer to prevent duplicate / legacy links
        $normalized = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $slug = (string) ($item['slug'] ?? '');
            $href = (string) ($item['href'] ?? '');

            // Normalize old links to canonical /member/* routes
            if ($slug === 'home' || $href === $slateUrl . '/customer/' || $href === $slateUrl . '/customer') {
                $item['href'] = $slateUrl . '/member';
                $item['slug'] = 'home';
            } elseif ($slug === 'account' || $href === $slateUrl . '/customer/profile.php') {
                $item['href'] = $slateUrl . '/member/account';
                $item['slug'] = 'account';
            } elseif ($slug === 'booking' || $href === $slateUrl . '/book') {
                $item['href'] = $slateUrl . '/member/book';
                $item['slug'] = 'book';
            } elseif ($slug === 'membership' && ($href === $slateUrl . '/member' || $href === $slateUrl . '/member/')) {
                // If membership registers bare /member, route it to membership overview so it doesn't collide with home
                $item['href'] = $slateUrl . '/member/membership/overview';
            }

            $normalized[] = $item;
        }

        $items = CustomerNav::resolve($normalized, $activeSlug);
        $split = CustomerNav::split($items);

        return [
            'items'  => $items,
            'groups' => CustomerNav::group($items),
            'tabs'   => $split['tabs'],
            'more'   => $split['more'],
        ];
    }

    /**
     * Resolve contextual feature subnavigation (e.g. for membership, coaching).
     *
     * @param string $area Feature area key (e.g. 'membership', 'coaching', 'studio', 'clientdesk')
     * @param string|null $activeTab Active subtab ID
     * @return array<int, array{id: string, label: string, href: string, icon?: string, active: bool, badge?: string}>
     */
    public function contextNavigation(string $area, ?string $activeTab = null): array
    {
        $area = strtolower(trim($area));
        if ($area === '' || $area === 'home' || $area === 'account' || $area === 'activity') {
            return [];
        }

        $context = [
            'area'        => $area,
            'customer_id' => $this->customerId,
            'customer'    => $this->customer,
            'active_tab'  => $activeTab,
            'portal'      => $this,
        ];

        $nav = [];
        if (class_exists(Hook::class)) {
            $nav = Hook::applyFilters('customer_portal_context_nav', $nav, $context);
        }
        if (!is_array($nav)) {
            return [];
        }

        $out = [];
        foreach ($nav as $item) {
            if (!is_array($item) || empty($item['label']) || empty($item['href'])) {
                continue;
            }
            $id = (string) ($item['id'] ?? $item['slug'] ?? '');
            $isActive = !empty($item['active']) || ($activeTab !== null && $activeTab !== '' && $id === $activeTab);

            $out[] = [
                'id'     => $id !== '' ? $id : (string) count($out),
                'label'  => (string) $item['label'],
                'href'   => (string) $item['href'],
                'icon'   => isset($item['icon']) ? (string) $item['icon'] : null,
                'active' => $isActive,
                'badge'  => isset($item['badge']) ? (string) $item['badge'] : '',
            ];
        }

        return $out;
    }

    /**
     * Resolve typed dashboard widgets for an area (default: 'home').
     *
     * @return array<int, array{id: string, area: string, title?: string, order: int, size: string, tone?: string, html: string}>
     */
    public function widgets(string $area = 'home'): array
    {
        $context = [
            'area'        => $area,
            'customer_id' => $this->customerId,
            'customer'    => $this->customer,
            'portal'      => $this,
        ];

        $registered = [];
        if (class_exists(Hook::class)) {
            $registered = Hook::applyFilters('customer_portal_widgets', $registered, $context);
            if (!is_array($registered)) {
                $registered = [];
            }

            // Legacy customer_dashboard_widgets fallback
            $legacy = Hook::applyFilters('customer_dashboard_widgets', []);
            if (is_array($legacy)) {
                $idx = 500;
                foreach ($legacy as $w) {
                    if (is_string($w) && trim($w) !== '') {
                        $registered[] = [
                            'id'    => 'legacy_' . $idx,
                            'area'  => 'home',
                            'order' => $idx++,
                            'size'  => 'full',
                            'html'  => $w,
                        ];
                    }
                }
            }
        }

        $out = [];
        foreach ($registered as $w) {
            if (!is_array($w)) {
                continue;
            }
            if (($w['area'] ?? 'home') !== $area) {
                continue;
            }

            // Capability check
            if (!empty($w['capability']) && class_exists(PluginLoader::class)) {
                $cap = (string) $w['capability'];
                if (str_contains($cap, '.')) {
                    [$pSlug, $cKey] = explode('.', $cap, 2);
                    if (!PluginLoader::isCapabilityEnabled($pSlug, $cKey)) {
                        continue;
                    }
                }
            }

            // Customer eligibility check
            if (isset($w['eligible']) && is_callable($w['eligible'])) {
                try {
                    if (!$w['eligible']($this->customerId, $this->customer)) {
                        continue;
                    }
                } catch (\Throwable $e) {
                    continue;
                }
            }

            // Render HTML
            $html = '';
            if (!empty($w['html']) && is_string($w['html'])) {
                $html = $w['html'];
            } elseif (isset($w['render']) && is_callable($w['render'])) {
                try {
                    $html = (string) $w['render']($context);
                } catch (\Throwable $e) {
                    $html = '';
                }
            }

            if (trim($html) === '') {
                continue;
            }

            $out[] = [
                'id'    => (string) ($w['id'] ?? 'widget_' . count($out)),
                'area'  => $area,
                'title' => isset($w['title']) ? (string) $w['title'] : null,
                'order' => isset($w['order']) ? (int) $w['order'] : 500,
                'size'  => in_array($w['size'] ?? '', ['half', 'full'], true) ? $w['size'] : 'half',
                'tone'  => isset($w['tone']) ? (string) $w['tone'] : null,
                'html'  => $html,
            ];
        }

        usort($out, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return $out;
    }

    /**
     * Resolve KPI stats for the headline cards row.
     */
    public function stats(): array
    {
        $stats = [];
        if (class_exists(Hook::class)) {
            $context = [
                'customer_id' => $this->customerId,
                'customer'    => $this->customer,
                'portal'      => $this,
            ];
            $stats = Hook::applyFilters('customer_portal_kpis', $stats, $context);
            if (!is_array($stats)) {
                $stats = [];
            }
            $legacy = Hook::applyFilters('customer_dashboard_kpis', []);
            if (is_array($legacy)) {
                $stats = array_merge($stats, $legacy);
            }
        }

        $clean = array_values(array_filter($stats, 'is_array'));
        usort($clean, static fn (array $a, array $b): int =>
            (int) !empty($b['tone']) <=> (int) !empty($a['tone']));

        return array_slice($clean, 0, 4);
    }

    /**
     * Resolve combined activity timeline events across plugins.
     *
     * @return array<int, array{id: string, type: string, label: string, description?: string, occurred_at: string, href?: string, icon?: string, tone?: string}>
     */
    public function activity(int $limit = 20): array
    {
        $events = [];
        if (class_exists(Hook::class)) {
            $context = [
                'customer_id' => $this->customerId,
                'customer'    => $this->customer,
                'limit'       => $limit,
                'portal'      => $this,
            ];
            $events = Hook::applyFilters('customer_portal_activity', $events, $context);
        }
        if (!is_array($events)) {
            return [];
        }

        $clean = [];
        foreach ($events as $ev) {
            if (!is_array($ev) || empty($ev['label']) || empty($ev['occurred_at'])) {
                continue;
            }
            $clean[] = [
                'id'          => (string) ($ev['id'] ?? bin2hex(random_bytes(6))),
                'type'        => (string) ($ev['type'] ?? 'generic'),
                'label'       => (string) $ev['label'],
                'description' => isset($ev['description']) ? (string) $ev['description'] : '',
                'occurred_at' => (string) $ev['occurred_at'],
                'href'        => isset($ev['href']) ? (string) $ev['href'] : '',
                'icon'        => isset($ev['icon']) ? (string) $ev['icon'] : 'clock',
                'tone'        => isset($ev['tone']) ? (string) $ev['tone'] : 'neutral',
            ];
        }

        usort($clean, static fn (array $a, array $b): int =>
            strtotime($b['occurred_at']) <=> strtotime($a['occurred_at']));

        return array_slice($clean, 0, $limit);
    }

    /**
     * Resolve plugin-contributed account sections.
     *
     * @return array<int, array{id: string, title: string, order: int, render: callable, save_handler?: callable}>
     */
    public function accountSections(): array
    {
        $sections = [];
        if (class_exists(Hook::class)) {
            $context = [
                'customer_id' => $this->customerId,
                'customer'    => $this->customer,
                'portal'      => $this,
            ];
            $sections = Hook::applyFilters('customer_portal_account_sections', $sections, $context);
        }
        if (!is_array($sections)) {
            return [];
        }

        $clean = array_values(array_filter($sections, static fn ($s) => is_array($s) && !empty($s['title'])));
        usort($clean, static fn (array $a, array $b): int => ($a['order'] ?? 500) <=> ($b['order'] ?? 500));

        return $clean;
    }
}
