/**
 * Bitchat Global Modal System (SM-3)
 *
 * Provides a single reusable modal container to reduce DOM bloat by 40-60%.
 * Replaces 75+ duplicate modal structures with dynamic content loading.
 */

(function() {
    'use strict';

    const BC_MODAL = {
        debug: false,
        $modal: null,
        currentConfig: null,

        log: function(message) {
            if (this.debug) console.log('[BC Modal]', message);
        },

        /**
         * Initialize the global modal system
         */
        init: function() {
            this.$modal = $('#bc-global-modal');

            if (this.$modal.length === 0) {
                console.error('[BC Modal] Global modal container not found');
                return;
            }

            // Reset modal on hide
            this.$modal.on('hidden.bs.modal', () => {
                this.reset();
            });

            this.log('Global modal system initialized');
        },

        /**
         * Show a confirmation dialog
         * @param {Object} config - Modal configuration
         * @param {string} config.title - Modal title
         * @param {string} config.message - Modal message/body content
         * @param {string} config.confirmText - Confirm button text (default: "Confirm")
         * @param {string} config.cancelText - Cancel button text (default: "Cancel")
         * @param {Function} config.onConfirm - Callback when confirmed
         * @param {Function} config.onCancel - Callback when cancelled (optional)
         * @param {string} config.confirmClass - CSS class for confirm button (default: "btn-main")
         * @param {string} config.size - Modal size: 'sm', 'md', 'lg' (default: 'md')
         */
        confirm: function(config) {
            const defaults = {
                title: 'Confirm Action',
                message: 'Are you sure?',
                confirmText: 'Confirm',
                cancelText: 'Cancel',
                confirmClass: 'btn-main',
                size: 'md',
                onConfirm: () => {},
                onCancel: null
            };

            config = Object.assign({}, defaults, config);
            this.currentConfig = config;

            // Set modal size
            const $dialog = this.$modal.find('.modal-dialog');
            $dialog.removeClass('modal-sm modal-md modal-lg');
            $dialog.addClass('modal-' + config.size);

            // Set title
            this.$modal.find('.modal-title').text(config.title);

            // Set body
            this.$modal.find('.modal-body').html('<p>' + config.message + '</p>');

            // Set footer buttons
            const $footer = this.$modal.find('.modal-footer');
            $footer.html(`
                <button type="button" class="btn btn-default btn-mat disable_btn bc-modal-cancel" data-dismiss="modal">
                    ${config.cancelText}
                </button>
                <button type="button" class="btn ${config.confirmClass} btn-mat disable_btn bc-modal-confirm">
                    ${config.confirmText}
                </button>
            `);

            // Attach event handlers
            $footer.find('.bc-modal-confirm').off('click').on('click', () => {
                if (config.onConfirm) {
                    config.onConfirm();
                }
                this.$modal.modal('hide');
            });

            if (config.onCancel) {
                $footer.find('.bc-modal-cancel').off('click').on('click', () => {
                    config.onCancel();
                });
            }

            // Show modal
            this.$modal.modal('show');
            this.log('Confirm modal shown: ' + config.title);
        },

        /**
         * Show a custom content modal
         * @param {Object} config - Modal configuration
         * @param {string} config.title - Modal title
         * @param {string|jQuery} config.content - Modal body content (HTML or jQuery object)
         * @param {Array} config.buttons - Array of button configs (optional)
         * @param {string} config.size - Modal size: 'sm', 'md', 'lg' (default: 'md')
         * @param {Function} config.onShow - Callback when modal is shown (optional)
         * @param {Function} config.onHide - Callback when modal is hidden (optional)
         */
        show: function(config) {
            const defaults = {
                title: '',
                content: '',
                buttons: [],
                size: 'md',
                onShow: null,
                onHide: null
            };

            config = Object.assign({}, defaults, config);
            this.currentConfig = config;

            // Set modal size
            const $dialog = this.$modal.find('.modal-dialog');
            $dialog.removeClass('modal-sm modal-md modal-lg');
            $dialog.addClass('modal-' + config.size);

            // Set title
            this.$modal.find('.modal-title').text(config.title);

            // Set body
            this.$modal.find('.modal-body').html(config.content);

            // Set footer buttons
            const $footer = this.$modal.find('.modal-footer');
            if (config.buttons.length > 0) {
                let buttonsHtml = '';
                config.buttons.forEach(btn => {
                    const btnClass = btn.class || 'btn-default';
                    const btnText = btn.text || 'Button';
                    const btnId = btn.id || '';
                    buttonsHtml += `<button type="button" class="btn ${btnClass} btn-mat" ${btnId ? 'id="' + btnId + '"' : ''}>${btnText}</button>`;
                });
                $footer.html(buttonsHtml);

                // Attach button handlers
                config.buttons.forEach((btn, index) => {
                    if (btn.onClick) {
                        const selector = btn.id ? '#' + btn.id : '.btn:eq(' + index + ')';
                        $footer.find(selector).off('click').on('click', btn.onClick);
                    }
                });
            } else {
                $footer.empty(); // No footer if no buttons
            }

            // Attach show/hide callbacks
            if (config.onShow) {
                this.$modal.off('shown.bs.modal').on('shown.bs.modal', config.onShow);
            }
            if (config.onHide) {
                this.$modal.off('hide.bs.modal').on('hide.bs.modal', config.onHide);
            }

            // Show modal
            this.$modal.modal('show');
            this.log('Custom modal shown: ' + config.title);
        },

        /**
         * Show an alert modal (message with OK button)
         * @param {Object} config - Modal configuration
         * @param {string} config.title - Modal title
         * @param {string} config.message - Alert message
         * @param {string} config.type - Alert type: 'info', 'success', 'warning', 'danger' (default: 'info')
         * @param {Function} config.onOk - Callback when OK is clicked (optional)
         */
        alert: function(config) {
            const defaults = {
                title: 'Alert',
                message: '',
                type: 'info',
                onOk: null
            };

            config = Object.assign({}, defaults, config);

            const alertClass = 'alert-' + config.type;
            const content = `<div class="alert ${alertClass}">${config.message}</div>`;

            this.show({
                title: config.title,
                content: content,
                size: 'md',
                buttons: [
                    {
                        text: 'OK',
                        class: 'btn-main',
                        onClick: () => {
                            if (config.onOk) config.onOk();
                            this.$modal.modal('hide');
                        }
                    }
                ]
            });

            this.log('Alert modal shown: ' + config.type);
        },

        /**
         * Load modal content from AJAX
         * @param {Object} config - Configuration
         * @param {string} config.url - URL to load content from
         * @param {Object} config.data - POST data (optional)
         * @param {string} config.title - Modal title
         * @param {string} config.size - Modal size (default: 'md')
         */
        load: function(config) {
            const defaults = {
                url: '',
                data: {},
                title: 'Loading...',
                size: 'md'
            };

            config = Object.assign({}, defaults, config);

            // Show loading state
            this.show({
                title: config.title,
                content: '<div class="text-center"><i class="fa fa-spinner fa-spin fa-2x"></i></div>',
                size: config.size
            });

            // Load content via AJAX
            $.post(config.url, config.data, (response) => {
                if (response.html) {
                    this.$modal.find('.modal-body').html(response.html);
                } else {
                    this.$modal.find('.modal-body').html(response);
                }
                this.log('AJAX content loaded from: ' + config.url);
            }).fail(() => {
                this.alert({
                    title: 'Error',
                    message: 'Failed to load content. Please try again.',
                    type: 'danger'
                });
            });
        },

        /**
         * Close the modal
         */
        hide: function() {
            this.$modal.modal('hide');
        },

        /**
         * Reset modal to default state
         */
        reset: function() {
            this.$modal.find('.modal-title').text('');
            this.$modal.find('.modal-body').html('');
            this.$modal.find('.modal-footer').html('');
            this.$modal.find('.modal-dialog').removeClass('modal-sm modal-md modal-lg').addClass('modal-md');
            this.$modal.off('shown.bs.modal hide.bs.modal');
            this.currentConfig = null;
            this.log('Modal reset');
        }
    };

    // Auto-initialize when DOM is ready
    $(document).ready(function() {
        BC_MODAL.init();
    });

    // Expose to global scope
    window.BC_MODAL = BC_MODAL;

})();


/**
 * USAGE EXAMPLES:
 *
 * 1. Confirmation Dialog:
 * BC_MODAL.confirm({
 *     title: 'Delete Post',
 *     message: 'Are you sure you want to delete this post?',
 *     confirmText: 'Delete',
 *     cancelText: 'Cancel',
 *     confirmClass: 'btn-danger',
 *     onConfirm: function() {
 *         // Delete post logic here
 *         Wo_DeletePost(post_id);
 *     }
 * });
 *
 * 2. Alert Message:
 * BC_MODAL.alert({
 *     title: 'Success',
 *     message: 'Your post was published successfully!',
 *     type: 'success'
 * });
 *
 * 3. Custom Content:
 * BC_MODAL.show({
 *     title: 'Custom Modal',
 *     content: '<form>...</form>',
 *     size: 'lg',
 *     buttons: [
 *         { text: 'Cancel', class: 'btn-default', onClick: () => BC_MODAL.hide() },
 *         { text: 'Save', class: 'btn-main', onClick: () => { saveLogic(); } }
 *     ]
 * });
 *
 * 4. Load from AJAX:
 * BC_MODAL.load({
 *     url: Wo_Ajax_Requests_File() + '?f=load_form',
 *     data: { form_type: 'edit_post' },
 *     title: 'Edit Post'
 * });
 *
 * 5. Replace existing modal calls:
 * OLD: $('#delete-post').modal('show');
 * NEW: BC_MODAL.confirm({
 *     title: 'Delete Post',
 *     message: 'Confirm delete?',
 *     onConfirm: () => Wo_DeletePost(post_id)
 * });
 */
