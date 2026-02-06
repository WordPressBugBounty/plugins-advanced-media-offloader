/**
 * Advanced Media Offloader - Attachment delete loading indicator
 *
 * Shows a “Deleting…” spinner in:
 * - Media Library list view (table)
 * - Media Library grid view (wp.media)
 */
(function ($, wp) {
    'use strict';

    var config = window.advmoDeleteLoading || {};
    var deletingText = (config.i18n && config.i18n.deleting) ? config.i18n.deleting : 'Deleting…';

    /**
     * List view (upload.php list table)
     */
    function initListView() {
        // Media Library list view navigates/reloads on delete, so we intentionally do nothing here.
        return;
    }


    /**
     * Grid view (wp.media)
     *
     * Patch the attachment model destroy() so we can toggle a flag during deletion.
     * Patch the attachment view render() to add/remove an overlay when that flag is set.
     */
    function initGridView() {
        if (!wp || !wp.media || !wp.media.view || !wp.media.view.Attachment || !wp.media.model || !wp.media.model.Attachment) {
            return;
        }

        // Patch model destroy
        var AttachmentModel = wp.media.model.Attachment;
        var modelProto = AttachmentModel.prototype;
        if (!modelProto._advmoDeletePatched) {
            var originalDestroy = modelProto.destroy;
            modelProto.destroy = function (options) {
                options = options || {};

                // Mark deleting (view will render overlay).
                try {
                    this.set('advmoDeleting', true);
                } catch (e) {
                    // no-op
                }

                var self = this;
                var originalSuccess = options.success;
                var originalError = options.error;

                options.success = function () {
                    try {
                        self.set('advmoDeleting', false);
                    } catch (e) {
                        // no-op
                    }
                    if (typeof originalSuccess === 'function') {
                        return originalSuccess.apply(this, arguments);
                    }
                };

                options.error = function () {
                    try {
                        self.set('advmoDeleting', false);
                    } catch (e) {
                        // no-op
                    }
                    if (typeof originalError === 'function') {
                        return originalError.apply(this, arguments);
                    }
                };

                return originalDestroy.apply(this, arguments);
            };

            modelProto._advmoDeletePatched = true;
        }

        // Patch view render
        var AttachmentView = wp.media.view.Attachment;
        var viewProto = AttachmentView.prototype;
        if (viewProto._advmoDeleteOverlayPatched) {
            return;
        }

        var originalRender = viewProto.render;
        var originalInitialize = viewProto.initialize;

        viewProto.initialize = function () {
            if (typeof originalInitialize === 'function') {
                originalInitialize.apply(this, arguments);
            }
            if (this.model) {
                this.listenTo(this.model, 'change:advmoDeleting', this.render);
            }
        };

        viewProto.render = function () {
            var result = originalRender.apply(this, arguments);

            if (!this.model) {
                return result;
            }

            var isDeleting = !!this.model.get('advmoDeleting');
            var viewClass = this.el && this.el.className ? String(this.el.className) : '';
            var $thumbnail = this.$el.find('.thumbnail').first();
            var $preview = this.$el.find('.attachment-preview').first();
            var $overlayHost = $preview.length ? $preview : $thumbnail;

            if (!$overlayHost.length) {
                return result;
            }

            // Remove any existing overlay first.
            this.$el.find('.advmo-delete-overlay').remove();
            this.$el.find('.advmo-delete-host').removeClass('advmo-delete-host');

            if (isDeleting) {
                // USER REQUIREMENT: only show overlay in the open attachment modal (not on the grid thumbnails).
                var inMediaModal = this.$el.closest('.media-modal').length > 0;
                var isDetailsView = viewClass.indexOf('attachment-details') !== -1;

                if (!inMediaModal || !isDetailsView) {
                    return result;
                }

                // attachment-details sometimes reports <img> as 0x0 briefly (lazy load/hidden).
                // In that case, render a host-sized overlay immediately (so the user still sees feedback),
                // and if/when the image rect becomes available, the overlay will tighten to the image box.
                var $detailsImg = this.$el.find('img').first();
                var detailsImgRect = null;
                try {
                    detailsImgRect = $detailsImg.length ? $detailsImg.get(0).getBoundingClientRect() : null;
                } catch (e) {
                    detailsImgRect = null;
                }

                if (!detailsImgRect || detailsImgRect.width <= 2 || detailsImgRect.height <= 2) {
                }

                // Add class first so CSS positions the overlay relative to the thumbnail immediately.
                this.$el.addClass('advmo-deleting');

                // If the host is larger than the rendered image, center overlay on the image rect instead of the host rect.
                // This avoids the “Deleting…” label looking like it sits below the image.
                var $img = $overlayHost.find('img').first();
                var overlayStyleAttr = '';
                var overlayBox = null;
                var hostContainsImg = false;
                var overlayBoxApplied = false;

                if ($img.length) {
                    try {
                        var hostElForBox = $overlayHost.get(0);
                        var imgElForBox = $img.get(0);
                        hostContainsImg = !!(hostElForBox && imgElForBox && hostElForBox.contains(imgElForBox));

                        if (hostContainsImg && hostElForBox.getBoundingClientRect) {
                            var hostRectForBox = hostElForBox.getBoundingClientRect();
                            var imgRectForBox = imgElForBox.getBoundingClientRect();

                            // IMPORTANT: In some WP media views the <img> can report 0x0 briefly (lazy load / hidden),
                            // which would create a 0-sized overlay (looks “broken”). Only apply the image-box overlay
                            // when we have a real, visible image rectangle.
                            if (imgRectForBox.width > 2 && imgRectForBox.height > 2) {
                            overlayBox = {
                                top: Math.max(0, imgRectForBox.top - hostRectForBox.top),
                                left: Math.max(0, imgRectForBox.left - hostRectForBox.left),
                                width: Math.max(0, imgRectForBox.width),
                                height: Math.max(0, imgRectForBox.height)
                            };
                            overlayStyleAttr =
                                ' style="' +
                                'top:' + overlayBox.top + 'px;' +
                                'left:' + overlayBox.left + 'px;' +
                                'width:' + overlayBox.width + 'px;' +
                                'height:' + overlayBox.height + 'px;' +
                                '"';
                            overlayBoxApplied = true;
                            }
                        }
                    } catch (e) {
                        // no-op; fallback to host inset
                    }
                }

                $overlayHost.append(
                    '<div class="advmo-delete-overlay"' + overlayStyleAttr + '>' +
                        '<span class="spinner is-active advmo-delete-spinner"></span>' +
                        '<span class="advmo-delete-overlay-text"></span>' +
                    '</div>'
                );
                $overlayHost.find('.advmo-delete-overlay-text').text(deletingText);

            } else {
                this.$el.removeClass('advmo-deleting');
            }

            return result;
        };

        viewProto._advmoDeleteOverlayPatched = true;
    }

    $(function () {
        initListView();
        initGridView();
    });
})(jQuery, window.wp);


