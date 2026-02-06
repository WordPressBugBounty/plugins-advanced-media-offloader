/**
 * Advanced Media Offloader - Media Library Grid View Filter
 *
 * Adds offload status filter dropdown to the media library grid view.
 */
(function ($, wp) {
    'use strict';

    if (typeof wp === 'undefined' || typeof wp.media === 'undefined' || typeof wp.media.view === 'undefined') {
        return;
    }

    var config = window.advmoMediaFilter || {};
    var queryVar = config.queryVar || 'advmo_offload_status';

    /**
     * Custom filter view for offload status.
     * Extends wp.media.view.AttachmentFilters
     */
    wp.media.view.AttachmentFilters.AdvmoOffloadStatus = wp.media.view.AttachmentFilters.extend({
        id: 'advmo-offload-status-filter',
        className: 'attachment-filters advmo-offload-filter',

        createFilters: function () {
            var filters = {};

            if (config.options) {
                $.each(config.options, function (value, text) {
                    var props = {};

                    // Set the custom query var for non-empty values
                    if (value !== '') {
                        props[queryVar] = value;
                    }

                    filters[value === '' ? 'all' : value] = {
                        text: text,
                        props: props,
                        priority: value === '' ? 10 : 20
                    };
                });
            }

            this.filters = filters;
        },

        /**
         * Override change to properly set our custom property.
         */
        change: function () {
            var filter = this.filters[this.el.value];

            if (filter) {
                // Unset our custom query var first if switching to 'all'
                if (this.el.value === 'all') {
                    this.model.unset(queryVar);
                }
                this.model.set(filter.props);
            }
        },

        /**
         * Override select to properly sync dropdown with model.
         */
        select: function () {
            var model = this.model,
                value = model.get(queryVar),
                key = 'all';

            // Find the matching filter key
            if (value && this.filters[value]) {
                key = value;
            }

            this.$el.val(key);
        }
    });

    /**
     * Extend the AttachmentsBrowser view to add our filter to the toolbar.
     */
    var originalAttachmentsBrowser = wp.media.view.AttachmentsBrowser;

    wp.media.view.AttachmentsBrowser = originalAttachmentsBrowser.extend({
        createToolbar: function () {
            // Call the original createToolbar method
            originalAttachmentsBrowser.prototype.createToolbar.call(this);

            // Add our custom filter to the toolbar
            // Priority -72 places it after Date filter (-75) but before Bulk Select (-70)
            this.toolbar.set('advmoOffloadFilter', new wp.media.view.AttachmentFilters.AdvmoOffloadStatus({
                controller: this.controller,
                model: this.collection.props,
                priority: -72
            }).render());
        }
    });

})(jQuery, wp);
