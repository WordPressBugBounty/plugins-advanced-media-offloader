/**
 * Offload Status Indicator for Media Library Grid View
 *
 * Adds cloud/warning badges to media attachments in grid view.
 */
(function($) {
    'use strict';

    if (!window.wp || !wp.media || !wp.media.view || !wp.media.view.Attachment) {
        return;
    }

    // Get icons from localized data
    var config = window.advmoOffloadStatus || {};
    var cloudIcon = config.cloudIcon || '';
    var warningIcon = config.warningIcon || '';

    var AttachmentView = wp.media.view.Attachment;
    var proto = AttachmentView.prototype;

    // Prevent double-patching
    if (proto._advmoPatched) {
        return;
    }

    var originalRender = proto.render;
    var originalInitialize = proto.initialize;

    proto.initialize = function() {
        if (typeof originalInitialize === 'function') {
            originalInitialize.apply(this, arguments);
        }
        if (this.model) {
            this.listenTo(this.model, 'change:advmoOffloaded change:advmoHasErrors', this.render);
        }
    };

    proto.render = function() {
        var result = originalRender.apply(this, arguments);

        if (!this.model) {
            return result;
        }

        var isOffloaded = this.model.get('advmoOffloaded');
        var hasErrors = this.model.get('advmoHasErrors');
        var $thumbnail = this.$el.find('.thumbnail');
        var $badge = $thumbnail.find('.advmo-grid-badge');

        // Remove existing badge first
        $badge.remove();

        // Add appropriate badge
        if (isOffloaded) {
            $thumbnail.append('<span class="advmo-grid-badge advmo-grid-badge--success" title="Offloaded">' + cloudIcon + '</span>');
        } else if (hasErrors) {
            $thumbnail.append('<span class="advmo-grid-badge advmo-grid-badge--error" title="Offload failed">' + warningIcon + '</span>');
        }

        return result;
    };

    proto._advmoPatched = true;

})(jQuery);

