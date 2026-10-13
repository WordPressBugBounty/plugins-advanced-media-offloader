<?php

declare(strict_types=1);

namespace AdvancedMediaOffloader\Support;

/**
 * WordPress's deprecation notices, kept out of AJAX and REST responses.
 *
 * With WP_DEBUG on, WordPress prints them through trigger_error(), which breaks a JSON body.
 * During those requests the notice goes to the error log instead, as WooCommerce does.
 */
final class Deprecation
{
    public static function function(string $function, string $version, string $replacement = ''): void
    {
        if (!self::isQuietRequest()) {
            _deprecated_function($function, $version, $replacement); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            return;
        }

        do_action('deprecated_function_run', $function, $replacement, $version); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's hook.

        $message = $replacement
            ? sprintf('%1$s is deprecated since Advanced Media Offloader %2$s. Use %3$s instead.', $function, $version, $replacement)
            : sprintf('%1$s is deprecated since Advanced Media Offloader %2$s with no alternative available.', $function, $version);

        self::log($message);
    }

    public static function argument(string $function, string $version, string $message = ''): void
    {
        if (!self::isQuietRequest()) {
            _deprecated_argument($function, $version, $message); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            return;
        }

        do_action('deprecated_argument_run', $function, $message, $version); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's hook.

        self::log(sprintf('%1$s was called with an argument that is deprecated since Advanced Media Offloader %2$s. %3$s', $function, $version, $message));
    }

    private static function isQuietRequest(): bool
    {
        return wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST);
    }

    private static function log(string $message): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG && apply_filters('deprecated_function_trigger_error', true)) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's filter.
            error_log('ADVMO: ' . $message); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
    }
}
