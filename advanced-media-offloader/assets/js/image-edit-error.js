/**
 * Keep Advanced Media Offloader image-edit errors visible in media modals.
 */
(function ($) {
    'use strict';

    var config = window.advmoImageEditError || {};

    function getResponse(xhr) {
        if (xhr.responseJSON) {
            return xhr.responseJSON;
        }

        if (!xhr.responseText) {
            return null;
        }

        try {
            return JSON.parse(xhr.responseText);
        } catch (error) {
            return null;
        }
    }

    function getError(response) {
        var data;
        var message;

        if (!response || response.success !== false || !response.data) {
            return null;
        }

        data = response.data;
        if (data.advmo_image_edit_error !== true) {
            return null;
        }

        message = data.error;
        if (!message && data.message) {
            message = data.message.error;
        }

        if (!message) {
            return null;
        }

        return {
            attachmentId: parseInt(data.attachment_id, 10) || 0,
            message: String(message)
        };
    }

    function createNotice(message) {
        var $notice = $('<div>', {
            'class': 'notice notice-error is-dismissible advmo-image-edit-error',
            'role': 'alert',
            'tabindex': '-1'
        });
        var $dismiss = $('<button>', {
            'type': 'button',
            'class': 'notice-dismiss'
        });

        $notice.append($('<p>').text(message));
        $dismiss.append(
            $('<span>', {'class': 'screen-reader-text'}).text(
                config.dismiss || 'Dismiss this notice.'
            )
        );
        $dismiss.on('click', function () {
            $notice.remove();
        });
        $notice.append($dismiss);

        return $notice;
    }

    function showModalError(error) {
        var $modal;
        var $target;
        var responseSelector;

        if (error.attachmentId > 0) {
            responseSelector = '#imgedit-response-' + error.attachmentId;
            if ($(responseSelector).find('.notice-error:visible').length) {
                return;
            }
        }

        $modal = $('.media-modal:visible').last();
        if (!$modal.length) {
            // The classic attachment editor keeps Core's notice visible.
            return;
        }

        $modal.find('.advmo-image-edit-error').remove();
        $target = $modal.find('.media-frame-content:visible').first();
        if (!$target.length) {
            $target = $modal.find('.media-modal-content').first();
        }

        if (!$target.length) {
            return;
        }

        $target.prepend(createNotice(error.message));
        $target.find('.advmo-image-edit-error').first().trigger('focus');
    }

    $(document).ajaxComplete(function (event, xhr) {
        var error = getError(getResponse(xhr));

        if (!error) {
            return;
        }

        // Core changes the modal state in its success callback. Wait until that
        // synchronous transition has finished before choosing the visible view.
        window.setTimeout(function () {
            showModalError(error);
        }, 50);
    });
}(jQuery));
