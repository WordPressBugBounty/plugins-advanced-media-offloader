<?php

/**
 * Advanced Media Offloader - Need Support? page
 *
 * Copy here is written for readers of every English level: one idea per
 * sentence, everyday words, no idioms. Product terms that appear as labels
 * elsewhere in the plugin (bucket, offload, Custom Domain) are kept, because
 * different wording here would stop matching the screen the reader is
 * looking at — they are explained in plain words instead.
 */

use Advanced_Media_Offloader\Admin\SupportPage;

if (!defined('ABSPATH')) {
    exit;
}

if (!current_user_can('manage_options')) {
    return;
}

?>
<div id="advmo">
    <div class="wrap">
        <h2 class="advmo-print-notices-after screen-reader-text"><?php esc_html_e('Need Support?', 'advanced-media-offloader'); ?></h2>

    <div class="advmo-support">

        <div class="advmo-support-intro">
            <h1><?php esc_html_e('Not working? Start here.', 'advanced-media-offloader'); ?></h1>
            <p><?php esc_html_e('Most problems have one of a few simple causes. Read the list below first. If it still does not work, send us a message and we will help you.', 'advanced-media-offloader'); ?></p>
        </div>

        <div class="advmo-section">
            <div class="advmo-section-header">
                <h2><?php esc_html_e('Check these first', 'advanced-media-offloader'); ?></h2>
            </div>
            <div class="advmo-section-body">
                <p class="advmo-section-intro"><?php esc_html_e('Almost every question we get is about one of these seven things.', 'advanced-media-offloader'); ?></p>
                <ol class="advmo-support-checklist">
                    <li>
                        <strong><?php esc_html_e('You must fill in the Custom Domain (CDN URL).', 'advanced-media-offloader'); ?></strong>
                        <?php esc_html_e('If you leave it empty, your files go to the cloud, but your pages still load them from your own server. Use the web address your visitors get files from. Do not use the Endpoint URL. That one is only for the plugin.', 'advanced-media-offloader'); ?>
                    </li>
                    <li>
                        <strong><?php esc_html_e('Old files do not move by themselves.', 'advanced-media-offloader'); ?></strong>
                        <?php esc_html_e('New uploads go to the cloud on their own. Files that were already in your Media Library stay on your server until you run a bulk offload.', 'advanced-media-offloader'); ?>
                    </li>
                    <li>
                        <strong><?php esc_html_e('The retention policy only changes what happens next.', 'advanced-media-offloader'); ?></strong>
                        <?php esc_html_e('Files that were already sent to the cloud keep the setting they had at that time. This is the usual reason your free disk space does not go up.', 'advanced-media-offloader'); ?>
                    </li>
                    <li>
                        <strong><?php esc_html_e('Your access key must be allowed to read and to write.', 'advanced-media-offloader'); ?></strong>
                        <?php esc_html_e('A key that can only read will pass the connection test. It then fails the first time the plugin tries to send a file.', 'advanced-media-offloader'); ?>
                    </li>
                    <li>
                        <strong><?php esc_html_e('Full Cloud Migration removes local files after cloud sync.', 'advanced-media-offloader'); ?></strong>
                        <?php esc_html_e('When WordPress needs to regenerate image sizes or edit a cloud-only image, the plugin temporarily restores the required file and reapplies your retention policy after a successful cloud sync. Keep an independent backup because deactivating the plugin does not download your Media Library.', 'advanced-media-offloader'); ?>
                    </li>
                    <li>
                        <strong><?php esc_html_e('Changes only travel from WordPress to the cloud.', 'advanced-media-offloader'); ?></strong>
                        <?php esc_html_e('If you delete or move files inside your bucket yourself, WordPress does not see it. It still shows those files as offloaded.', 'advanced-media-offloader'); ?>
                    </li>
                    <li>
                        <strong><?php esc_html_e('Do you have thousands of files? Use WP-CLI.', 'advanced-media-offloader'); ?></strong>
                        <?php
                        printf(
                            /* translators: %s: link to the WP-CLI guide */
                            esc_html__('The button in Media Overview moves 50 files at a time, and it stops if you close the tab. %s', 'advanced-media-offloader'),
                            '<a href="' . esc_url(SupportPage::cli_doc_url()) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Read the WP-CLI guide', 'advanced-media-offloader') . '</a>'
                        );
                        ?>
                    </li>
                </ol>
            </div>
        </div>

        <div class="advmo-support-channels">
            <div class="advmo-support-card">
                <span class="dashicons dashicons-groups" aria-hidden="true"></span>
                <h2><?php esc_html_e('Ask in the WordPress forum', 'advanced-media-offloader'); ?></h2>
                <p><?php esc_html_e('Good for setup questions, and for asking if something is a bug. Anyone can read these messages, so your answer helps other people too.', 'advanced-media-offloader'); ?></p>
                <a class="button button-primary advmo-cta" href="<?php echo esc_url(SupportPage::forum_url()); ?>" target="_blank" rel="noopener noreferrer">
                    <span class="dashicons dashicons-external" aria-hidden="true"></span>
                    <?php esc_html_e('Open a forum topic', 'advanced-media-offloader'); ?>
                </a>
            </div>

            <div class="advmo-support-card">
                <span class="dashicons dashicons-email-alt" aria-hidden="true"></span>
                <h2><?php esc_html_e('Write to us directly', 'advanced-media-offloader'); ?></h2>
                <p><?php esc_html_e('Better when you do not want to show something in public. For example your website address, a picture of your bucket settings, or a question about your licence.', 'advanced-media-offloader'); ?></p>
                <a class="button advmo-cta" href="<?php echo esc_url(SupportPage::contact_url()); ?>" target="_blank" rel="noopener noreferrer">
                    <span class="dashicons dashicons-external" aria-hidden="true"></span>
                    <?php esc_html_e('Go to the contact page', 'advanced-media-offloader'); ?>
                </a>
            </div>
        </div>

        <div class="advmo-section">
            <div class="advmo-section-header">
                <h2><?php esc_html_e('Send us this with your message', 'advanced-media-offloader'); ?></h2>
            </div>
            <div class="advmo-section-body">
                <p class="advmo-section-intro"><?php esc_html_e('Copy this text and paste it into your message. It answers the questions we would need to ask you first, so you get help faster. It does not contain your keys, your passwords or your bucket name, so it is safe to post in public.', 'advanced-media-offloader'); ?></p>
                <div class="advmo-support-report">
                    <pre class="advmo-wpconfig-snippet"><code id="advmo-system-report"><?php echo esc_html(SupportPage::system_report()); ?></code></pre>
                    <button type="button" class="button advmo-copy-report" data-copied="<?php esc_attr_e('Copied!', 'advanced-media-offloader'); ?>" data-copy-hint="<?php esc_attr_e('Selected — press Ctrl/Cmd + C', 'advanced-media-offloader'); ?>">
                        <?php esc_html_e('Copy system report', 'advanced-media-offloader'); ?>
                    </button>
                </div>
                <p class="advmo-support-tip">
                    <?php esc_html_e('Please also tell us what you expected to happen, what happened instead, and the exact error message if you saw one.', 'advanced-media-offloader'); ?>
                </p>
            </div>
        </div>

        <?php
        /**
         * The ask sits at the end, after the page has tried to solve the
         * reader's problem. Asking for money above the help would be asking
         * before giving anything.
         */
        ?>
        <div class="advmo-support-give">
            <span class="dashicons dashicons-heart" aria-hidden="true"></span>
            <div class="advmo-support-give-text">
                <h2><?php esc_html_e('Do you like this plugin?', 'advanced-media-offloader'); ?></h2>
                <p><?php esc_html_e('Advanced Media Offloader is free to use. If it helped you, here are two ways to support our work.', 'advanced-media-offloader'); ?></p>
            </div>
            <div class="advmo-support-give-actions">
                <a class="button advmo-cta" href="<?php echo esc_url(SupportPage::review_url()); ?>" target="_blank" rel="noopener noreferrer">
                    <span class="dashicons dashicons-star-filled" aria-hidden="true"></span>
                    <?php esc_html_e('Leave a review', 'advanced-media-offloader'); ?>
                </a>
                <?php if (SupportPage::show_donate_links()) : ?>
                    <a class="button button-primary advmo-cta" href="<?php echo esc_url(SupportPage::donate_url()); ?>" target="_blank" rel="noopener noreferrer">
                        <span class="dashicons dashicons-coffee" aria-hidden="true"></span>
                        <?php esc_html_e('Make a donation', 'advanced-media-offloader'); ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    </div>
</div>
