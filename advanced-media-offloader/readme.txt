=== Advanced Media Offloader – Offload Media to Amazon S3, Cloudflare R2, DigitalOcean Spaces & More ===
Contributors: masoudin, wpfitter, bahreynipour, teledark
Donate link: https://buymeacoffee.com/wpfitter?utm_source=wp-plugin&utm_medium=readme&utm_campaign=advanced-media-offloader&utm_content=readme-donate
Tags: offload, amazon s3, cloudflare r2, cloud storage, cdn
Requires at least: 5.6
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 4.4.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Offload WordPress media to Amazon S3, Cloudflare R2, DigitalOcean Spaces & more. Save server space, serve files via CDN, speed up your site.

== Description ==

**Advanced Media Offloader** automatically moves your WordPress Media Library to cloud storage — Amazon S3, Cloudflare R2, DigitalOcean Spaces, Backblaze B2, Wasabi, MinIO, or any S3-compatible service — and serves your files from there.

Your server stays light, your pages load faster, and you never touch your existing content: every media URL is rewritten automatically. Setup takes about five minutes, with no code required.

= Why offload your WordPress media? =

* **Cut hosting costs** — stop upgrading your hosting plan just to store images and videos. Cloud storage is far cheaper per GB.
* **Speed up your site** — serve media from cloud storage or a CDN close to your visitors, instead of your web server.
* **Lighter, faster backups** — with media in the cloud, your site backups shrink dramatically.
* **Zero content changes** — URLs are rewritten on the fly; posts, pages, and products keep working exactly as before.
* **Stay in control** — keep local copies, clean them up smartly, or go fully cloud. You choose the retention policy.

= Supported cloud storage providers =

* **Amazon S3** — The industry standard object storage service
* **Cloudflare R2** — S3-compatible storage with zero egress fees
* **DigitalOcean Spaces** — Simple object storage from DigitalOcean
* **Backblaze B2** — Affordable S3-compatible storage with predictable pricing
* **Wasabi** — Hot cloud storage with predictable pricing
* **MinIO** — Any S3-compatible storage (MinIO, OVHcloud Object Storage, Scaleway, Linode, Vultr, IBM COS, and more)

Not sure which provider to choose? Check our [cloud storage pricing comparison](https://wpfitter.com/blog/best-cloud-storage-for-wordpress-media-pricing-comparison/?utm_source=wp-plugin&utm_medium=readme&utm_campaign=advanced-media-offloader&utm_content=pricing-comparison) for real-world cost breakdowns.

= How it works =

1. **Install and activate** the plugin, then open **Media Offloader** in your WordPress admin.
2. **Connect your cloud storage** — pick a provider, enter its credentials, save your settings, then click **Test Connection**.
3. **Offload** — new uploads go to the cloud automatically. Move existing files with one click from the Media Overview page.

That's it. Most sites are fully set up in about five minutes.

= Features =

* **Automatic offloading** — every new upload goes straight to your cloud storage.
* **Bulk offload existing media** — migrate your current Media Library from the Media Overview page, or use WP-CLI (`wp advmo offload`) for unlimited, scriptable migrations. ([Learn more](https://wpfitter.com/blog/advmo-bulk-offload-with-wp-cli/?utm_source=wp-plugin&utm_medium=readme&utm_campaign=advanced-media-offloader&utm_content=bulk-offload-cli))
* **Flexible retention policies** — keep local copies (Retain Local Files), free up space while keeping originals as backup (Smart Local Cleanup), or remove all local files (Full Cloud Migration). ([Learn more](https://wpfitter.com/blog/implementing-smart-retention-policies-with-advanced-media-offloader/?utm_source=wp-plugin&utm_medium=readme&utm_campaign=advanced-media-offloader&utm_content=smart-policies))
* **Smart URL rewriting** — all media URLs, including every thumbnail size and srcset, are served from the cloud automatically.
* **Offload status at a glance** — cloud badges in the Media Library, an offload-status filter, and per-file "Offload Now" and "Retry Offload" buttons.
* **Mirror Delete** — optionally remove files from cloud storage when you delete them in WordPress.
* **Custom path prefix** — control exactly where your files live inside your bucket.
* **File versioning** — add unique timestamps to media paths to prevent stale CDN caches.
* **Thumbnail regeneration support** — compatible with WP-CLI `wp media regenerate` and the Regenerate Thumbnails plugin; regenerated thumbnails offload automatically. (Not available with the Full Cloud Migration policy.)

= Works with your favorite plugins =

* **WooCommerce** — product images and WooCommerce-specific image sizes offload and display correctly.
* **Page builders** — Elementor, Beaver Builder, Visual Composer, and any builder that uses standard WordPress media functions.
* **Image optimizers** — [Modern Image Formats](https://wordpress.org/plugins/webp-uploads/) (recommended), Imagify, and EWWW Image Optimizer. Optimized WebP and AVIF files are offloaded alongside the originals. ([Learn more](https://wpfitter.com/blog/ewww-imagify-support-added-to-advanced-media-offloader/?utm_source=wp-plugin&utm_medium=readme&utm_campaign=advanced-media-offloader&utm_content=image-optimizer-compatibility))
* **WP-CLI** — automate offloading in deployment scripts and cron jobs.

= For developers =

Advanced Media Offloader is built to be extended: 20+ action and filter hooks let you control what gets offloaded, where it goes, and what happens before and after each step.

Quick example — skip offloading files larger than 5MB:

`
add_filter('advmo_should_offload_attachment', function($should_offload, $attachment_id) {
    $file = get_attached_file($attachment_id);
    if ($file && filesize($file) > 5 * 1024 * 1024) {
        return false;
    }
    return $should_offload;
}, 10, 2);
`

**[View Developer Hooks Documentation →](https://wpfitter.com/documents/advanced-media-offloader/development-hooks/?utm_source=wp-plugin&utm_medium=readme&utm_campaign=advanced-media-offloader&utm_content=developer-hooks)**

Under the hood, the plugin uses the official [AWS SDK for PHP](https://aws.amazon.com/sdk-for-php/), namespace-isolated with PHP-Scoper so it never conflicts with other plugins that bundle the same SDK.

= Pro version coming soon =

We're working on **Advanced Media Offloader Pro** with premium features such as private media files with secure, authenticated access. Follow [wpfitter.com](https://wpfitter.com/?utm_source=wp-plugin&utm_medium=readme&utm_campaign=advanced-media-offloader&utm_content=pro-teaser) to be the first to know when it launches.

== Installation ==

Setup takes about five minutes and requires no code changes.

1. Install the plugin from **Plugins → Add New** (search for "Advanced Media Offloader"), or upload the plugin files to `/wp-content/plugins/advanced-media-offloader/`.
2. Activate the plugin through the "Plugins" menu in WordPress.
3. Go to **Media Offloader** in the admin menu, choose your cloud provider, and enter its credentials.
4. Save your settings, then click **Test Connection** to verify your credentials.
5. New uploads are now offloaded automatically. Offload your existing media from the **Media Overview** tab.

= Advanced (optional): define credentials in wp-config.php =

The settings page is the quickest way to configure the plugin — no code required. Alternatively, you can define your credentials as constants in `wp-config.php` using the examples below. Constants take priority over credentials saved in the admin and lock the corresponding fields. This keeps secrets out of the database — useful for version-controlled or multi-environment setups.

**Note:** Domain and endpoint URLs will automatically be prefixed with `https://` if you don't include it, but we recommend always including the full URL for clarity.

**[Amazon S3](https://aws.amazon.com/s3/) Configuration**
`
define('ADVMO_AWS_KEY', 'your-access-key');
define('ADVMO_AWS_SECRET', 'your-secret-key');
define('ADVMO_AWS_BUCKET', 'your-bucket-name');
define('ADVMO_AWS_REGION', 'your-bucket-region');
define('ADVMO_AWS_DOMAIN', 'your-domain-url');
`

**[Cloudflare R2](https://developers.cloudflare.com/r2/) Configuration**
`
define('ADVMO_CLOUDFLARE_R2_KEY', 'your-access-key');
define('ADVMO_CLOUDFLARE_R2_SECRET', 'your-secret-key');
define('ADVMO_CLOUDFLARE_R2_BUCKET', 'your-bucket-name');
define('ADVMO_CLOUDFLARE_R2_DOMAIN', 'your-domain-url');
define('ADVMO_CLOUDFLARE_R2_ENDPOINT', 'your-endpoint-url');
`

**[DigitalOcean Spaces](https://www.digitalocean.com/products/spaces) Configuration**
`
define('ADVMO_DOS_KEY', 'your-access-key');
define('ADVMO_DOS_SECRET', 'your-secret-key');
define('ADVMO_DOS_BUCKET', 'your-bucket-name');
define('ADVMO_DOS_DOMAIN', 'your-domain-url');
define('ADVMO_DOS_ENDPOINT', 'your-endpoint-url');
`

**[Backblaze B2](https://www.backblaze.com/apidocs/introduction-to-the-s3-compatible-api) Configuration**
`
define('ADVMO_BACKBLAZE_B2_KEY', 'your-application-key-id');
define('ADVMO_BACKBLAZE_B2_SECRET', 'your-application-key');
define('ADVMO_BACKBLAZE_B2_BUCKET', 'your-bucket-name');
define('ADVMO_BACKBLAZE_B2_REGION', 'your-bucket-region');
define('ADVMO_BACKBLAZE_B2_DOMAIN', 'your-domain-url');
define('ADVMO_BACKBLAZE_B2_ENDPOINT', 'your-endpoint-url');
`

**[Wasabi](https://docs.wasabi.com/docs/creating-a-new-access-key) Configuration**
`
define('ADVMO_WASABI_KEY', 'your-access-key');
define('ADVMO_WASABI_SECRET', 'your-secret-key');
define('ADVMO_WASABI_BUCKET', 'your-bucket-name');
define('ADVMO_WASABI_REGION', 'your-bucket-region');
define('ADVMO_WASABI_DOMAIN', 'your-domain-url');
`

**[MinIO](https://min.io/docs/minio/linux/administration/identity-access-management/minio-user-management.html) Configuration**

Use this for any storage that supports the S3 API via a custom endpoint (e.g., MinIO, OVHcloud Object Storage, Scaleway, Linode, Vultr, IBM COS). Select this if your provider isn't listed separately.

`
define('ADVMO_MINIO_KEY', 'your-access-key');
define('ADVMO_MINIO_SECRET', 'your-secret-key');
define('ADVMO_MINIO_BUCKET', 'your-bucket-name');
define('ADVMO_MINIO_DOMAIN', 'your-domain-url');
define('ADVMO_MINIO_ENDPOINT', 'your-endpoint-url');
define('ADVMO_MINIO_PATH_STYLE_ENDPOINT', false); // Optional. Set to true if your MinIO server requires path-style URLs (most self-hosted MinIO setups). Default is false.
define('ADVMO_MINIO_REGION', 'your-bucket-region'); // Optional. Set your MinIO bucket region if needed. Default is 'us-east-1'.
`

== Frequently Asked Questions ==

= Is Advanced Media Offloader free? =

Yes. Everything described here — automatic offloading, bulk migration, WP-CLI, all six providers — is completely free. A Pro version with extras such as private media files is in the works.

= Which cloud storage providers are supported? =

Amazon S3, Cloudflare R2, DigitalOcean Spaces, Backblaze B2, Wasabi, and MinIO — plus any other S3-compatible service (OVHcloud, Scaleway, Linode, Vultr, IBM COS, and more) via the MinIO option. Additional providers are added based on user demand.

= What happens to media files already on my server? =

Nothing, until you say so. Existing files stay untouched until you run the bulk offload from the Media Overview page (or via WP-CLI). New uploads are offloaded automatically based on your settings.

= Will offloading make my site faster? =

Serving media from cloud storage takes load off your web server, and pairing your bucket with a CDN delivers files from locations close to your visitors. Media-heavy sites usually see the biggest improvement.

= Does it work with WooCommerce, Elementor, and other page builders? =

Yes. URL rewriting hooks into WordPress core media functions (`wp_get_attachment_url` and related filters), so WooCommerce, Elementor, Beaver Builder, and most themes and plugins work without any changes.

= Can I keep local copies of my files? =

Yes — that's the default. You can choose between three retention policies: **Retain Local Files** (keep everything), **Smart Local Cleanup** (free up space but keep originals as backup), or **Full Cloud Migration** (remove all local copies).

= Is there a limit on bulk offloading? =

The browser-based bulk offload processes up to 50 files (max 150 MB) per batch — simply run it again for the next batch. For large libraries, the WP-CLI command `wp advmo offload` has no limit and supports options like `--limit` and `--skip-failed`.

= Can I undo offloading and go back to local files? =

If you use the "Retain Local Files" policy, yes — deactivate the plugin and your media is served locally again. With Full Cloud Migration you would need to re-download your files first, so keep local copies until you're confident.

= How are image sizes and thumbnails handled? =

All generated image sizes are offloaded alongside the original, and URL rewriting covers every size and srcset attribute — responsive images keep working as expected.

= Does it work with WebP/AVIF image optimizer plugins? =

Yes — Modern Image Formats (recommended), Imagify, and EWWW Image Optimizer are fully supported. Optimized WebP and AVIF versions are offloaded automatically alongside the originals.

= Does it support private files with access control? =

Not yet — the free version supports publicly accessible files. Private media with secure, authenticated access is planned for the upcoming Pro version.

= What happens if I delete a media file from the WordPress Media Library? =

With **Mirror Delete** enabled, the corresponding cloud files are removed automatically. Otherwise, files remain in cloud storage, potentially creating orphaned objects.

= What's the recommended bucket configuration? =

For optimal performance:

1. Enable CORS configuration
2. Set appropriate public read permissions
3. Configure the region closest to your audience
4. Consider using a CDN for global distribution

= How do I configure public access for my bucket? =

By default, the plugin sets a `public-read` ACL on uploaded objects. However, some providers don't support ACLs, and AWS S3 has ACLs disabled by default on new buckets since April 2023. You should configure bucket-level public access using your provider's bucket policies.

If you encounter `AccessControlListNotSupported` errors or need to disable ACLs, add the following code to your theme's `functions.php` or a custom plugin:

`
add_filter('advmo_object_acl', '__return_false');
`

= How can I debug issues with file offloading? =

Check the Media Overview page — it shows offload errors and lets you download a CSV of failed attachments. The plugin also logs errors to attachment metadata, and you can enable WordPress debug logging for more detail.

== Screenshots ==

1. Settings page — connect Amazon S3, Cloudflare R2, or any S3-compatible cloud storage in minutes and verify it with one-click Test Connection.
2. Media Overview — see how much of your Media Library is offloaded and bulk offload existing files to cloud storage.
3. Attachment details — per-file offload status with Offload Now and Retry buttons right in the Media Library.

== Changelog ==

= 4.4.4 =
* Fixed: Conflict with other plugins or themes that also use the AWS PHP SDK. The plugin's SDK is now fully isolated and no longer shares the unprefixed `Aws\S3\Exception\S3Exception` class name.
* Fixed: With EWWW Image Optimizer in background mode, deleting a media item while its optimization job was still queued caused a fatal error that permanently blocked EWWW's queue — new uploads no longer got their WebP versions generated or offloaded. Deleted attachments are now skipped cleanly and the queue keeps processing.
* Fixed: The bulk offload stall-check cron (`advmo_check_stalled_processes`) no longer runs every 15 minutes when idle. It is now scheduled only while a bulk offload job is active and cleared when the job completes, is cancelled, or recovers from a stall.
* Fixed: The bulk offload background healthcheck cron (`advmo_bulk_offload_media_process_cron`) could remain scheduled after a successful bulk offload. It is now cleared reliably when the job finishes or the queue is empty.
* Improved: Small fixes and improvements

= 4.4.3 =
* Fixed: With Object Versioning enabled, newly offloaded images could appear broken on your site. The files were uploaded correctly, but the saved location sometimes didn't match — so images failed to load. The correct location is now always saved, so your images display properly.
* Fixed: Offloading an existing image that is stored directly in the uploads folder could add an extra `/./` to its URL — for example `https://cdn.example.com/./image.png` — so the image failed to load on some CDNs. The URL is now correct.
* Fixed: Repeated PHP warnings and possible high server load on pages with images whose saved metadata had a broken size entry.

= 4.4.2 =
* Added: Compatibility with WordPress 7.0
* Fixed: Editing an image (crop, rotate, or scale) could fail to upload to your cloud storage and show an error. Edited images now offload reliably.
* Fixed: With Full Cloud Migration active alongside an image optimizer (such as Imagify or EWWW), editing an image could leave the edited copies — including their WebP/AVIF versions — on your server. They are now removed according to your retention policy.
* Fixed: With EWWW Image Optimizer in background mode, newly offloaded images could be left on your server instead of being removed under your retention policy. They are now cleaned up reliably.
* Fixed: Deleting an image you had previously edited could leave leftover WebP/AVIF copies behind in your cloud storage. These are now removed too — covering Modern Image Formats, Imagify, and EWWW Image Optimizer.

= 4.4.1 =
* Fixed: Custom/intermediate image sizes in src attribute being replaced with the full-size URL when using page builders like Elementor

= 4.4.0 =
* Added: Imagify compatibility for WebP/AVIF offloading
* Added: EWWW Image Optimizer compatibility

= 4.3.2 =
* Fixed: Visual Composer compatibility - resolved PHP TypeError caused by strict type hints on admin_enqueue_scripts callbacks

= 4.3.1 =
* Added: `advmo_object_acl` filter to customize or disable object-level ACL permissions

= 4.3.0 =
* Added: New visual badges in Media Library show offload status at a glance. Cloud icon for offloaded files, warning icon for failed uploads.
* Added: Visual "Deleting..." loading indicator with spinner in Media Library attachment modal when deleting offloaded media
* Added: Offload status filter dropdown in Media Library for quick filtering by offload state.
* Improved: Optimized cloud deletion performance using batched deleteObjects API (up to 1000 keys per request) for faster deletion of attachments with multiple sizes
* Fixed: MinIO "Use Path-Style Endpoint" now correctly respects boolean `wp-config.php` constants (e.g. `define('ADVMO_MINIO_PATH_STYLE_ENDPOINT', true);`).

= 4.2.3 =
* Fixed: TypeError when uploading non-image files (SVG, ZIP, PDF)

= 4.2.2 =
* Added: Compatibility with WordPress 6.9
* Added: Full Compatibility with [Modern Image Formats](https://wordpress.org/plugins/webp-uploads/)
* Fixed: Minor changes and improvements

= 4.2.1 =
* Fix: Checkbox states for credential fields now properly persist when unchecked
* Fix: Deletion failure when WordPress year/month folders are disabled

= 4.2.0 =
* New: Added compatibility for thumbnail regeneration with WP-CLI `wp media regenerate` command and the Regenerate Thumbnails plugin. Regenerated thumbnails now automatically offload to cloud storage. Note: This feature does not work with Full Cloud Migration retention policy.
* New: Added the ability to configure cloud provider credentials through the WordPress admin settings page while maintaining backward compatibility with wp-config.php constants. Constants take priority and disable corresponding fields when defined.
* New: Added setting to toggle automatic cloud offloading for new uploads
* New: Added customizable Name field S3-compatible providers to identify specific storage services (e.g., MinIO, OVHcloud, Scaleway). Default is "MinIO" with backward compatibility for existing installations.
* Fix: Minor changes and improvements

Earlier releases are documented in `changelog.txt` included with the plugin.

== Upgrade Notice ==

= 4.4.4 =
Fixes AWS SDK conflicts with other plugins, an EWWW background-mode queue blocker when deleting media, and leftover bulk-offload cron events that could keep running after jobs finished.

