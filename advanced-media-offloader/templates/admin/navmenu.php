<?php

/**
 * Advanced Media Offloader - Admin Navigation Menu
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Generate admin page URL for Advanced Media Offloader.
 *
 * @param string $page The page slug.
 * @return string The full admin URL.
 */
if (!function_exists('advmo_get_admin_page_url')) {
    function advmo_get_admin_page_url(string $page): string
    {
        return get_admin_url(null, "admin.php?page={$page}");
    }
}

/**
 * Menu items configuration.
 */
$menu_items = [
    'general' => [
        'title' => __('General Settings', 'advanced-media-offloader'),
        'url' => advmo_get_admin_page_url('advmo'),
    ],
    'media-overview' => [
        'title' => __('Media Overview', 'advanced-media-offloader'),
        'url' => advmo_get_admin_page_url('advmo_media_overview'),
    ],
    'support' => [
        'title' => __('Need Support?', 'advanced-media-offloader'),
        'url' => advmo_get_admin_page_url('advmo_support'),
    ],
];

/**
 * Generate a menu item HTML.
 *
 * @param array $item Menu item configuration.
 * @param string $page page slug.
 * @return string HTML for the menu item.
 */
function advmo_generate_menu_item(array $item, string $page): string
{
    $is_active = advmo_is_settings_page($page);
    return sprintf(
        '<a href="%s" class="%s"%s>%s</a>',
        esc_url($item['url']),
        esc_attr($is_active ? 'active' : ''),
        $is_active ? ' aria-current="page"' : '',
        esc_html($item['title'])
    );
}
?>

<div class="advmo-menu">
    <nav>
        <?php foreach ($menu_items as $slug => $item) : ?>
            <?php echo advmo_generate_menu_item($item, $slug); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The helper escapes every dynamic value. ?>
        <?php endforeach; ?>
    </nav>
</div>
