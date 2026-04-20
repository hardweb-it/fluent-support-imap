(function($) {
    'use strict';

    if (typeof fsImapAdmin === 'undefined') return;

    var i18n = fsImapAdmin.i18n || {};

    var fsImap = {
        restUrl: fsImapAdmin.restUrl,
        nonce: fsImapAdmin.nonce,
        configs: fsImapAdmin.configs || {},
        currentBoxId: null,
        observer: null,
        active: false,
        $contentArea: null,

        init: function() {
            var self = this;
            this.observer = new MutationObserver(function() {
                self.tryInject();
            });
            this.observer.observe(document.body, { childList: true, subtree: true });
            this.tryInject();

            window.addEventListener('hashchange', function() {
                $('#fs-imap-sidebar-item-wrapper').remove();
                $('#fs-imap-injected-panel').remove();
                self.currentBoxId = null;
                self.active = false;
                self.$contentArea = null;
                setTimeout(self.tryInject.bind(self), 100);
            });
        },

        tryInject: function() {
            if ($('#fs-imap-sidebar-item-wrapper').length) return;

            var boxId = this.extractBoxId();
            if (!boxId) return;

            // Trova il punto di inserimento nella sidebar:
            // 1) Cerca "Email Piping" (pro attivo)
            // 2) Fallback: ultimo .fs_menu_item nella sidebar della mailbox
            var $anchorItem = this.findAnchorMenuItem();
            if (!$anchorItem || !$anchorItem.length) return;

            this.currentBoxId = boxId;
            this.injectSidebarItem($anchorItem, boxId);
        },

        extractBoxId: function() {
            var hash = window.location.hash || '';
            var match = hash.match(/mailbox(?:es)?\/(\d+)/i);
            if (match) return parseInt(match[1]);
            return null;
        },

        findAnchorMenuItem: function() {
            // Priorità 1: voce "Email Piping" (presente solo con pro)
            var $pipingLink = $('a[href*="email_piping"]').first();
            if ($pipingLink.length) {
                var $item = $pipingLink.closest('.fs_menu_item');
                if ($item.length) return $item;
            }

            // Priorità 2: ultimo .fs_menu_item nella sidebar della mailbox
            var $menuContainer = $('.fs_menu_container').first();
            if (!$menuContainer.length) return null;

            var $lastItem = $menuContainer.find('.fs_menu_item').last();
            return $lastItem.length ? $lastItem : null;
        },

        injectSidebarItem: function($anchorItem, boxId) {
            var self = this;

            var iconSvg = 'data:image/svg+xml;utf8,' + encodeURIComponent(
                '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#1f2937" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
                '<path d="M22 12h-6l-2 3h-4l-2-3H2"/>' +
                '<path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>' +
                '</svg>'
            );

            // Prendi l'icona arrow da un menu item esistente
            var arrowSrc = $anchorItem.find('.fs_menu_arrow').attr('src') || '';
            var arrowHtml = arrowSrc
                ? '<div class="fs_menu_arrow_wrapper"><img class="fs_menu_arrow" src="' + arrowSrc + '"></div>'
                : '';

            var $newItem = $('<div class="fs_menu_item" id="fs-imap-sidebar-item-wrapper"></div>');
            var $link = $('<a href="javascript:void(0)" id="fs-imap-sidebar-item"></a>');

            $link.append('<img src="' + iconSvg + '" alt="" style="width:18px;height:18px;">');
            $link.append('<span>' + i18n.menu_label + '</span>');
            if (arrowHtml) $link.append(arrowHtml);

            $newItem.append($link);
            $anchorItem.after($newItem);

            $newItem.on('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                self.activate();
            });

            // Click su qualsiasi altro menu item -> disattiva il nostro
            var $menuContainer = $anchorItem.closest('.fs_menu_container');
            $menuContainer.find('.fs_menu_item').each(function() {
                if (this.id === 'fs-imap-sidebar-item-wrapper') return;
                $(this).on('click.fsimap', function() {
                    self.deactivate();
                });
            });
        },

        findContentArea: function() {
            var $menuContainer = $('.fs_menu_container').first();
            if (!$menuContainer.length) return null;

            var $parent = $menuContainer.parent();
            var $candidate = $menuContainer.next();
            if (!$candidate.length || !$candidate.children().length) {
                $candidate = $parent.next();
            }
            if (!$candidate.length) {
                $candidate = $parent.children().not($menuContainer).filter(function() {
                    return $(this).children().length > 0;
                }).first();
            }
            return $candidate.length ? $candidate : null;
        },

        activate: function() {
            this.active = true;

            var $menuContainer = $('.fs_menu_container').first();
            $menuContainer.find('.fs_menu_item').removeClass('active');
            $('#fs-imap-sidebar-item-wrapper').addClass('active');

            if (!this.$contentArea || !this.$contentArea.length) {
                this.$contentArea = this.findContentArea();
            }
            if (!this.$contentArea || !this.$contentArea.length) {
                console.warn('[FS IMAP] Content area not found');
                return;
            }

            this.$contentArea.children().each(function() {
                if (this.id !== 'fs-imap-injected-panel') {
                    $(this).attr('data-fs-imap-hidden', 'true').hide();
                }
            });

            if (!$('#fs-imap-injected-panel').length) {
                this.renderPanel(this.$contentArea);
            } else {
                $('#fs-imap-injected-panel').show();
            }
        },

        deactivate: function() {
            if (!this.active) return;
            this.active = false;
            $('#fs-imap-sidebar-item-wrapper').removeClass('active');
            $('#fs-imap-injected-panel').hide();
            $('[data-fs-imap-hidden="true"]').removeAttr('data-fs-imap-hidden').show();
        },

        renderPanel: function($contentArea) {
            var boxId = this.currentBoxId;
            var config = this.configs[boxId] || {
                host: '', port: 993, username: '', has_password: false, encryption: 'ssl', enabled: false, interval: 5
            };

            var imapWarning = '';
            if (!fsImapAdmin.imapAvailable) {
                imapWarning = '<div class="fs-imap-warning">' + i18n.imap_missing + '</div>';
            }

            var html = '<div id="fs-imap-injected-panel" class="fs-imap-card" data-box-id="' + boxId + '">' +
                '<div class="fs-imap-card-header">' +
                    '<h3>' + i18n.panel_title + '</h3>' +
                    '<span class="fs-imap-subtitle">' + i18n.panel_subtitle + '</span>' +
                '</div>' +
                '<div class="fs-imap-card-body">' +
                    imapWarning +
                    '<div class="fs-imap-form">' +
                        this.row(i18n.label_host, '<input type="text" data-field="host" placeholder="' + i18n.placeholder_host + '" value="' + this.esc(config.host) + '" />') +
                        this.row(i18n.label_port, '<input type="number" data-field="port" value="' + (config.port || 993) + '" />') +
                        this.row(i18n.label_username, '<input type="text" data-field="username" placeholder="' + i18n.placeholder_username + '" value="' + this.esc(config.username) + '" />') +
                        this.row(i18n.label_password, '<input type="password" data-field="password" value="' + (config.has_password ? '********' : '') + '" />') +
                        this.row(i18n.label_encryption,
                            '<select data-field="encryption">' +
                                '<option value="ssl"' + (config.encryption === 'ssl' ? ' selected' : '') + '>' + i18n.opt_ssl + '</option>' +
                                '<option value="tls"' + (config.encryption === 'tls' ? ' selected' : '') + '>' + i18n.opt_tls + '</option>' +
                                '<option value="none"' + (config.encryption === 'none' ? ' selected' : '') + '>' + i18n.opt_none + '</option>' +
                            '</select>'
                        ) +
                        this.row(i18n.label_enabled,
                            '<label class="fs-imap-toggle">' +
                                '<input type="checkbox" data-field="enabled"' + (config.enabled ? ' checked' : '') + ' /> ' +
                                '<span>' + i18n.toggle_text + '</span>' +
                            '</label>'
                        ) +
                        this.row(i18n.label_interval,
                            '<input type="number" min="1" max="1440" data-field="interval" value="' + (config.interval || 5) + '" style="width:80px;" />' +
                            '<p class="fs-imap-hint">' + i18n.interval_hint + '</p>'
                        ) +
                    '</div>' +
                    '<div class="fs-imap-actions">' +
                        '<button type="button" class="fs-imap-btn fs-imap-btn-primary" id="fs-imap-save-btn">' + i18n.btn_save + '</button> ' +
                        '<button type="button" class="fs-imap-btn" id="fs-imap-test-btn">' + i18n.btn_test + '</button> ' +
                        '<button type="button" class="fs-imap-btn fs-imap-btn-success" id="fs-imap-fetch-btn">' + i18n.btn_fetch + '</button>' +
                    '</div>' +
                    '<div id="fs-imap-status" class="fs-imap-status" style="display:none;"></div>' +
                    '<div class="fs-imap-logs-section">' +
                        '<h4>' + i18n.logs_title + ' <button type="button" id="fs-imap-refresh-logs" class="fs-imap-btn-text">↻ ' + i18n.btn_refresh + '</button></h4>' +
                        '<div class="fs-imap-verbose-toggle">' +
                            '<label class="fs-imap-toggle">' +
                                '<input type="checkbox" id="fs-imap-verbose" /> ' +
                                '<span>' + i18n.verbose_label + '</span>' +
                            '</label>' +
                            '<p class="fs-imap-verbose-hint">' + i18n.verbose_hint + '</p>' +
                        '</div>' +
                        '<div id="fs-imap-logs"><em>' + i18n.loading + '</em></div>' +
                    '</div>' +
                '</div>' +
            '</div>';

            $contentArea.append(html);
            this.bindEvents();
            this.loadVerbose();
            this.loadLogs();
        },

        row: function(label, control) {
            return '<div class="fs-imap-row">' +
                '<label class="fs-imap-label">' + label + '</label>' +
                '<div class="fs-imap-control">' + control + '</div>' +
            '</div>';
        },

        esc: function(str) {
            if (str === null || str === undefined) return '';
            return String(str).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        },

        bindEvents: function() {
            var self = this;
            $('#fs-imap-save-btn').on('click', function() { self.saveConfig(); });
            $('#fs-imap-test-btn').on('click', function() { self.testConnection(); });
            $('#fs-imap-fetch-btn').on('click', function() { self.fetchNow(); });
            $('#fs-imap-refresh-logs').on('click', function() { self.loadLogs(); });
            $('#fs-imap-verbose').on('change', function() { self.toggleVerbose(); });
        },

        loadVerbose: function() {
            this.apiCall('GET', '/verbose').done(function(resp) {
                $('#fs-imap-verbose').prop('checked', resp.verbose);
            });
        },

        toggleVerbose: function() {
            var self = this;
            var enabled = $('#fs-imap-verbose').is(':checked');
            this.apiCall('POST', '/verbose', { verbose: enabled ? 'yes' : 'no' }).done(function(resp) {
                self.showStatus(resp.message, 'success');
            }).fail(function() {
                self.showStatus(i18n.save_error, 'error');
            });
        },

        getFormData: function() {
            var $panel = $('#fs-imap-injected-panel');
            return {
                host:       $panel.find('[data-field="host"]').val(),
                port:       $panel.find('[data-field="port"]').val(),
                username:   $panel.find('[data-field="username"]').val(),
                password:   $panel.find('[data-field="password"]').val(),
                encryption: $panel.find('[data-field="encryption"]').val(),
                enabled:    $panel.find('[data-field="enabled"]').is(':checked') ? 'yes' : 'no',
                interval:   $panel.find('[data-field="interval"]').val()
            };
        },

        apiCall: function(method, endpoint, data) {
            return $.ajax({
                url: this.restUrl + endpoint,
                method: method,
                data: data || {},
                beforeSend: function(xhr) {
                    xhr.setRequestHeader('X-WP-Nonce', fsImapAdmin.nonce);
                },
                dataType: 'json'
            });
        },

        showStatus: function(message, type) {
            var colors = {
                success: { bg: '#d4edda', border: '#28a745', color: '#155724' },
                error:   { bg: '#f8d7da', border: '#dc3545', color: '#721c24' },
                info:    { bg: '#d1ecf1', border: '#17a2b8', color: '#0c5460' },
                warning: { bg: '#fff3cd', border: '#ffc107', color: '#856404' }
            };
            var c = colors[type] || colors.info;
            $('#fs-imap-status').html(message).css({
                background: c.bg,
                'border-left': '4px solid ' + c.border,
                color: c.color,
                padding: '10px 14px',
                'border-radius': '4px',
                'margin-top': '12px'
            }).show();
        },

        saveConfig: function() {
            var self = this;
            var data = this.getFormData();
            var $btn = $('#fs-imap-save-btn');

            $btn.prop('disabled', true).text(i18n.saving);

            this.apiCall('POST', '/' + this.currentBoxId + '/config', data).done(function(resp) {
                self.showStatus(resp.message || i18n.config_saved, 'success');
                self.configs[self.currentBoxId] = {
                    host: data.host,
                    port: parseInt(data.port),
                    username: data.username,
                    has_password: !!data.password,
                    encryption: data.encryption,
                    enabled: data.enabled === 'yes',
                    interval: parseInt(data.interval) || 5
                };
            }).fail(function(xhr) {
                var msg = (xhr.responseJSON && xhr.responseJSON.message) || i18n.save_error;
                self.showStatus(msg, 'error');
            }).always(function() {
                $btn.prop('disabled', false).text(i18n.btn_save);
            });
        },

        testConnection: function() {
            var self = this;
            var $btn = $('#fs-imap-test-btn');

            $btn.prop('disabled', true).text(i18n.testing);
            self.showStatus(i18n.test_in_progress, 'info');

            this.apiCall('POST', '/' + this.currentBoxId + '/test').done(function(resp) {
                if (resp.success) {
                    self.showStatus(i18n.test_success.replace('%d', resp.total_emails || 0), 'success');
                } else {
                    self.showStatus(resp.message || i18n.test_failed, 'error');
                }
            }).fail(function(xhr) {
                var msg = (xhr.responseJSON && xhr.responseJSON.message) || i18n.test_error;
                self.showStatus(msg, 'error');
            }).always(function() {
                $btn.prop('disabled', false).text(i18n.btn_test);
            });
        },

        fetchNow: function() {
            var self = this;
            var $btn = $('#fs-imap-fetch-btn');

            $btn.prop('disabled', true).text(i18n.fetching);
            self.showStatus(i18n.fetch_in_progress, 'info');

            this.apiCall('POST', '/' + this.currentBoxId + '/fetch-now').done(function(resp) {
                var r = resp.result || {};
                if (r.error) {
                    self.showStatus(i18n.error_prefix + ': ' + r.error, 'error');
                } else {
                    var msg = i18n.fetch_completed
                        .replace('%total%', r.total || 0)
                        .replace('%processed%', r.processed || 0)
                        .replace('%skipped%', r.skipped || 0)
                        .replace('%errors%', r.errors || 0);
                    self.showStatus(msg, r.errors > 0 ? 'warning' : 'success');
                }
                self.loadLogs();
            }).fail(function(xhr) {
                var msg = (xhr.responseJSON && xhr.responseJSON.message) || i18n.fetch_error;
                self.showStatus(msg, 'error');
            }).always(function() {
                $btn.prop('disabled', false).text(i18n.btn_fetch);
            });
        },

        loadLogs: function() {
            var self = this;
            this.apiCall('GET', '/logs', { box_id: this.currentBoxId, limit: 20 }).done(function(resp) {
                var logs = resp.logs || [];
                if (!logs.length) {
                    $('#fs-imap-logs').html('<em>' + i18n.no_logs + '</em>');
                    return;
                }
                var html = '<table class="fs-imap-logs-table"><thead><tr>';
                html += '<th>' + i18n.col_date + '</th><th>' + i18n.col_level + '</th><th>' + i18n.col_message + '</th>';
                html += '</tr></thead><tbody>';
                for (var i = 0; i < logs.length; i++) {
                    var log = logs[i];
                    var levelClass = 'fs-imap-level-' + (log.level || 'info');
                    html += '<tr class="' + levelClass + '">';
                    html += '<td class="fs-imap-log-time">' + (log.time || '') + '</td>';
                    html += '<td class="fs-imap-log-level">' + (log.level || 'info').toUpperCase() + '</td>';
                    html += '<td>' + self.esc(log.message || '') + '</td>';
                    html += '</tr>';
                }
                html += '</tbody></table>';
                $('#fs-imap-logs').html(html);
            }).fail(function() {
                $('#fs-imap-logs').html('<em>' + i18n.logs_error + '</em>');
            });
        }
    };

    $(document).ready(function() {
        fsImap.init();
    });

})(jQuery);
