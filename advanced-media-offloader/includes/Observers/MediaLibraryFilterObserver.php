<?php

namespace Advanced_Media_Offloader\Observers;

use Advanced_Media_Offloader\Interfaces\ObserverInterface;

/**
 * Adds offload status filter dropdown to the WordPress Media Library.
 *
 * Allows filtering attachments by:
 * - Offloaded (successfully uploaded to cloud storage)
 * - Not Offloaded (pending, not yet processed)
 * - Failed (offload attempted but encountered errors)
 */
class MediaLibraryFilterObserver implements ObserverInterface
{
    /**
     * Query variable for the offload status filter.
     */
    private const QUERY_VAR = 'advmo_offload_status';

    /**
     * Meta key for offload status.
     */
    private const META_OFFLOADED = 'advmo_offloaded';

    /**
     * Meta key for offload errors.
     */
    private const META_ERROR_LOG = 'advmo_error_log';

    /**
     * Filter status values.
     */
    private const STATUS_OFFLOADED = 'offloaded';
    private const STATUS_NOT_OFFLOADED = 'not_offloaded';
    private const STATUS_FAILED = 'failed';

    /**
     * Register the observer with WordPress hooks.
     */
    public function register(): void
    {
        // Add filter dropdown to media library (list view)
        add_action('restrict_manage_posts', [$this, 'renderFilterDropdown']);

        // Modify query based on filter selection
        add_action('pre_get_posts', [$this, 'filterMediaQuery']);

        // Add filter support for grid view (AJAX)
        add_filter('ajax_query_attachments_args', [$this, 'filterAjaxQuery']);

        // Enqueue script for grid view filter
        add_action('admin_enqueue_scripts', [$this, 'enqueueGridFilterScript']);
    }

    /**
     * Render the offload status filter dropdown in list view.
     *
     * @param string $post_type The current post type.
     */
    public function renderFilterDropdown(string $post_type): void
    {
        if ($post_type !== 'attachment') {
            return;
        }

        if (!current_user_can('upload_files')) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter in admin list table
        $current_status = isset($_GET[self::QUERY_VAR])
            ? $this->validateStatus(sanitize_text_field(wp_unslash($_GET[self::QUERY_VAR])))
            : '';

        $options = $this->getFilterOptions();

        printf(
            '<select name="%s" id="%s" class="advmo-offload-filter">',
            esc_attr(self::QUERY_VAR),
            esc_attr(self::QUERY_VAR)
        );

        printf(
            '<option value="">%s</option>',
            esc_html__('All offload statuses', 'advanced-media-offloader')
        );

        foreach ($options as $value => $label) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($value),
                selected($current_status, $value, false),
                esc_html($label)
            );
        }

        echo '</select>';
    }

    /**
     * Get filter options with labels.
     *
     * @return array<string, string> Filter value => Label pairs.
     */
    private function getFilterOptions(): array
    {
        return [
            self::STATUS_OFFLOADED     => __('Offloaded', 'advanced-media-offloader'),
            self::STATUS_NOT_OFFLOADED => __('Not Offloaded', 'advanced-media-offloader'),
            self::STATUS_FAILED        => __('Failed', 'advanced-media-offloader'),
        ];
    }

    /**
     * Validate status against allowed values (whitelist).
     *
     * @param string $status The status to validate.
     * @return string Validated status or empty string if invalid.
     */
    private function validateStatus(string $status): string
    {
        $allowed = [
            self::STATUS_OFFLOADED,
            self::STATUS_NOT_OFFLOADED,
            self::STATUS_FAILED,
        ];

        return in_array($status, $allowed, true) ? $status : '';
    }

    /**
     * Filter media query based on selected offload status (list view).
     *
     * @param \WP_Query $query The WordPress query object.
     */
    public function filterMediaQuery(\WP_Query $query): void
    {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        if ($query->get('post_type') !== 'attachment') {
            return;
        }

        if (!current_user_can('upload_files')) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter in admin list table
        $status = isset($_GET[self::QUERY_VAR])
            ? $this->validateStatus(sanitize_text_field(wp_unslash($_GET[self::QUERY_VAR])))
            : '';

        if (empty($status)) {
            return;
        }

        $meta_query = $this->buildMetaQuery($status);

        if (!empty($meta_query)) {
            $existing_meta_query = $query->get('meta_query') ?: [];

            // If our meta query has a relation key, it's a complete query structure
            // Add it as a nested condition to preserve the structure
            if (isset($meta_query['relation'])) {
                $existing_meta_query[] = $meta_query;
            } else {
                // For simple conditions, add each one
                foreach ($meta_query as $condition) {
                    $existing_meta_query[] = $condition;
                }
            }

            $query->set('meta_query', $existing_meta_query);
        }
    }

    /**
     * Filter AJAX query for grid view.
     *
     * @param array $query The query arguments.
     * @return array Modified query arguments.
     */
    public function filterAjaxQuery(array $query): array
    {
        // WordPress strips unknown params via array_intersect_key before this filter
        // So we need to check the original $_REQUEST['query'] array directly
        $status = '';

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- AJAX nonce verified by WordPress core
        if (isset($_REQUEST['query']) && is_array($_REQUEST['query'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $request_query = wp_unslash($_REQUEST['query']);
            if (isset($request_query[self::QUERY_VAR]) && !empty($request_query[self::QUERY_VAR])) {
                $status = $this->validateStatus(sanitize_text_field($request_query[self::QUERY_VAR]));
            }
        }

        // Fallback: check if it somehow made it through to $query
        if (empty($status) && isset($query[self::QUERY_VAR]) && !empty($query[self::QUERY_VAR])) {
            $status = $this->validateStatus(sanitize_text_field($query[self::QUERY_VAR]));
        }

        if (empty($status)) {
            return $query;
        }

        $meta_query = $this->buildMetaQuery($status);

        if (!empty($meta_query)) {
            // Initialize meta_query if not set
            if (!isset($query['meta_query']) || !is_array($query['meta_query'])) {
                $query['meta_query'] = [];
            }

            // If our meta query has a relation key, it's a complete query structure
            // Add it as a nested condition to preserve the structure
            if (isset($meta_query['relation'])) {
                $query['meta_query'][] = $meta_query;
            } else {
                // For simple conditions, add each one
                foreach ($meta_query as $condition) {
                    $query['meta_query'][] = $condition;
                }
            }
        }

        return $query;
    }

    /**
     * Build meta query based on offload status.
     *
     * @param string $status The offload status filter value.
     * @return array The meta query array.
     */
    private function buildMetaQuery(string $status): array
    {
        switch ($status) {
            case self::STATUS_OFFLOADED:
                // Simple equality check - uses efficient INNER JOIN
                return [
                    [
                        'key'     => self::META_OFFLOADED,
                        'value'   => '1',
                        'compare' => '=',
                    ],
                ];

            case self::STATUS_NOT_OFFLOADED:
                // Find items that are neither offloaded nor failed.
                // Note: NOT EXISTS queries use LEFT JOIN which can be slower on large datasets.
                // For better performance on large libraries, consider adding a database index
                // on wp_postmeta (meta_key, meta_value) or using a unified status meta key.
                return [
                    'relation' => 'AND',
                    [
                        'relation' => 'OR',
                        [
                            'key'     => self::META_OFFLOADED,
                            'compare' => 'NOT EXISTS',
                        ],
                        [
                            'key'     => self::META_OFFLOADED,
                            'value'   => '1',
                            'compare' => '!=',
                        ],
                    ],
                    [
                        'key'     => self::META_ERROR_LOG,
                        'compare' => 'NOT EXISTS',
                    ],
                ];

            case self::STATUS_FAILED:
                // Non-empty error log indicates failure.
                // Single condition is sufficient - INNER JOIN excludes rows without this meta key.
                return [
                    [
                        'key'     => self::META_ERROR_LOG,
                        'value'   => '',
                        'compare' => '!=',
                    ],
                ];

            default:
                return [];
        }
    }

    /**
     * Enqueue script for grid view filter functionality.
     *
     * @param string $hook The current admin page hook.
     */
    public function enqueueGridFilterScript(string $hook): void
    {
        if ($hook !== 'upload.php') {
            return;
        }

        wp_enqueue_script(
            'advmo-media-library-filter',
            $this->getAssetUrl('js/media-library-filter.js'),
            ['jquery', 'media-views'],
            $this->getAssetVersion('js/media-library-filter.js'),
            true
        );

        wp_localize_script('advmo-media-library-filter', 'advmoMediaFilter', [
            'queryVar' => self::QUERY_VAR,
            'label'    => __('Offload Status', 'advanced-media-offloader'),
            'options'  => array_merge(
                ['' => __('All offload statuses', 'advanced-media-offloader')],
                $this->getFilterOptions()
            ),
        ]);
    }

    /**
     * Get the URL for an asset file.
     *
     * @param string $path Relative path to the asset.
     * @return string Full URL to the asset.
     */
    private function getAssetUrl(string $path): string
    {
        return plugins_url('assets/' . $path, dirname(__DIR__, 2) . '/advanced-media-offloader.php');
    }

    /**
     * Get the version for an asset file based on file modification time.
     *
     * @param string $path Relative path to the asset.
     * @return string Version string.
     */
    private function getAssetVersion(string $path): string
    {
        $file = dirname(__DIR__, 2) . '/assets/' . $path;

        if (file_exists($file)) {
            return (string) filemtime($file);
        }

        // @phpstan-ignore-next-line - Constant is defined in main plugin file
        return defined('ADVMO_VERSION') ? constant('ADVMO_VERSION') : '1.0.0';
    }
}

