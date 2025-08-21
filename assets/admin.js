jQuery(document).ready(function($) {
    'use strict';

    let checkedPlugins = new Set();
    let pluginCheckQueue = [];
    let isProcessingQueue = false;

    // Debug helper
    function dbg() {
        if (typeof kissSbiAjax !== 'undefined' && kissSbiAjax && kissSbiAjax.debug) {
            try { console.debug.apply(console, ['[KISS SBI]'].concat([].slice.call(arguments))); } catch (e) {}
        }
    }

    // Initialize
    init();

    function init() {
        bindEvents();
        updateBatchInstallButton();

        // Queue all plugin checks instead of running them simultaneously
        queueAllPluginChecks();
    }

    function bindEvents() {
        // Checkbox events
        $('#kiss-sbi-select-all').on('change', toggleAllCheckboxes);
        $(document).on('change', '.kiss-sbi-repo-checkbox', handleRepoCheckboxChange);

        // Button events
        $('#kiss-sbi-refresh-repos').on('click', refreshRepositories);
        $('#kiss-sbi-clear-cache').on('click', clearCache);
        $('#kiss-sbi-batch-install').on('click', batchInstallPlugins);
        $(document).on('click', '.kiss-sbi-check-plugin', checkPlugin);
        $(document).on('click', '.kiss-sbi-install-single', installSinglePlugin);
        $(document).on('click', '.kiss-sbi-activate-plugin', activatePlugin);
        $(document).on('click', '.kiss-sbi-check-installed', checkInstalled);
        $(document).on('click', '.kiss-sbi-self-update', runSelfUpdate);
        checkSelfUpdateAvailability();

        function checkSelfUpdateAvailability() {
            const $row = $('tr[data-repo="KISS-Smart-Batch-Installer"]');
            if ($row.length === 0) return;
            const $btn = $row.find('.kiss-sbi-self-update');
            const $meta = $row.find('.kiss-sbi-self-update-meta');
            $.post(kissSbiAjax.ajaxUrl, {
                action: 'kiss_sbi_check_self_update',
                nonce: kissSbiAjax.nonce
            }, function(resp) {
                if (!resp || !resp.success) return;
                const data = resp.data || {};
                if (data.error) {
                    $meta.text('Update check failed: ' + data.error);
                    return;
                }
                if (data.status === 'newer') {
                    $btn.hide();
                    $meta.text('Current (v' + data.installed + ') is newer than GitHub (v' + data.remote + ')');
                } else if (data.status === 'older') {
                    $btn.show().text('Update to v' + data.remote);
                    $meta.text('(Installed v' + data.installed + ')');
                } else if (data.status === 'equal') {
                    $btn.hide();
                    $meta.text('Up to date (v' + data.installed + ')');
                } else {
                    $btn.hide();
                    $meta.text('Update status unknown');
                }
            });
        }

        function runSelfUpdate() {
            const $btn = $(this);
            const $row = $btn.closest('tr');
            const $meta = $row.find('.kiss-sbi-self-update-meta');
            if (!confirm('Update KISS Smart Batch Installer now?')) return;
            const original = $btn.text();
            $btn.prop('disabled', true).text('Updating...');
            $.post(kissSbiAjax.ajaxUrl, {
                action: 'kiss_sbi_run_self_update',
                nonce: kissSbiAjax.nonce
            }, function(resp) {
                if (resp && resp.success) {
                    $meta.text('Updated to v' + (resp.data && resp.data.installed ? resp.data.installed : 'latest'));
                    location.reload();
                } else {
                    const msg = resp && resp.data ? resp.data : 'Unknown error';
                    alert('Update failed: ' + msg);
                    $btn.prop('disabled', false).text(original);
                }
            }).fail(function(){
                alert('Update failed.');
                $btn.prop('disabled', false).text(original);
            });
        }

    }

    function queueAllPluginChecks() {
        // First check installation status for all repos
        $('.kiss-sbi-check-installed').each(function() {
            dbg('Queue install status check for', $(this).data('repo'));
            checkInstalledStatus(this);
        });

        // Then add all check buttons to queue
        $('.kiss-sbi-check-plugin').each(function() {
            dbg('Queue plugin check for', $(this).data('repo'));
            pluginCheckQueue.push(this);
        });

        // Start processing queue
        processPluginCheckQueue();
    }

    function processPluginCheckQueue() {
        if (isProcessingQueue || pluginCheckQueue.length === 0) {
            return;
        }

        isProcessingQueue = true;
        const button = pluginCheckQueue.shift();

        // Check this plugin
        dbg('Processing check for', $(button).data('repo'));
        checkPluginQueued(button, function() {
            isProcessingQueue = false;

            // Small delay to prevent overwhelming the server
            setTimeout(function() {
                processPluginCheckQueue();
            }, 250);
        });
    }

    function toggleAllCheckboxes() {
        const isChecked = $(this).prop('checked');
        $('.kiss-sbi-repo-checkbox').prop('checked', isChecked);
        updateCheckedPlugins();
        updateBatchInstallButton();
    }

    function handleRepoCheckboxChange() {
        updateCheckedPlugins();
        updateBatchInstallButton();

        // Update select all checkbox
        const totalCheckboxes = $('.kiss-sbi-repo-checkbox').length;
        const checkedCheckboxes = $('.kiss-sbi-repo-checkbox:checked').length;

        $('#kiss-sbi-select-all').prop('checked', totalCheckboxes === checkedCheckboxes);
        $('#kiss-sbi-select-all').prop('indeterminate', checkedCheckboxes > 0 && checkedCheckboxes < totalCheckboxes);
    }

    function updateCheckedPlugins() {
        checkedPlugins.clear();
        $('.kiss-sbi-repo-checkbox:checked').each(function() {
            const repoName = $(this).val();
            const row = $(this).closest('tr');
            const isPlugin = row.find('.kiss-sbi-plugin-status').hasClass('is-plugin');

            if (isPlugin) {
                checkedPlugins.add(repoName);
            }
        });
    }

    function updateBatchInstallButton() {
        const hasValidSelection = checkedPlugins.size > 0;
        $('#kiss-sbi-batch-install').prop('disabled', !hasValidSelection);
    }

    function refreshRepositories() {
        const $button = $('#kiss-sbi-refresh-repos');
        const originalText = $button.text();

        dbg('Refresh Repositories clicked');
        $button.prop('disabled', true).text(kissSbiAjax.strings.loading || 'Loading...');

        $.ajax({
            url: kissSbiAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'kiss_sbi_refresh_repos',
                nonce: kissSbiAjax.nonce
            },
            success: function(response) {
                dbg('Refresh success', response);
                if (response.success) {
                    location.reload();
                } else {
                    showError('Failed to refresh repositories: ' + response.data);
                }
            },
            error: function(xhr) {
                dbg('Refresh error', xhr);
                showError('Ajax request failed.');
            },
            complete: function() {
                $button.prop('disabled', false).text(originalText);
            }
        });
    }

    function clearCache() {
        const $btn = $('#kiss-sbi-clear-cache');
        const original = $btn.text();
        dbg('Clear Cache clicked');
        $btn.prop('disabled', true).text('Clearing...');
        $.ajax({
            url: kissSbiAjax.ajaxUrl,
            type: 'POST',
            data: { action: 'kiss_sbi_clear_cache', nonce: kissSbiAjax.nonce },
            success: function(resp){
                dbg('Clear Cache success', resp);
                if (resp.success) {
                    showSuccess('Cache cleared');
                    location.reload();
                } else {
                    showError('Failed to clear cache: ' + resp.data);
                }
            },
            error: function(xhr){ dbg('Clear Cache error', xhr); showError('Ajax request failed.'); },
            complete: function(){ $btn.prop('disabled', false).text(original); }
        });
    }

    function checkPlugin() {
        // For manual clicks, add to queue if not already processing
        if (!isProcessingQueue) {
            pluginCheckQueue.unshift(this); // Add to front of queue for immediate processing
            processPluginCheckQueue();
        }
    }

    function checkPluginQueued(button, callback) {
        const $button = $(button);
        const repoName = $button.data('repo');
        const $statusCell = $button.closest('td');
        const $row = $button.closest('tr');

        $button.prop('disabled', true).text(kissSbiAjax.strings.scanning);

        $.ajax({
            url: kissSbiAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'kiss_sbi_scan_plugins',
                nonce: kissSbiAjax.nonce,
                repo_name: repoName
            },
            success: function(response) {
                dbg('Scan response', response);
                if (response && response.success) {
                    if (response.data && response.data.is_plugin) {
                        // If we've already determined the plugin is installed, do not overwrite or re-show install controls
                        if (!$statusCell.hasClass('is-installed')) {
                            $statusCell
                                .html('<span class="kiss-sbi-plugin-yes">✓ WordPress Plugin</span>')
                                .addClass('is-plugin');

                            // Show and enable install button
                            $row.find('.kiss-sbi-install-single').show().prop('disabled', false);

                            // Ensure checkbox available for valid plugin rows
                            $row.find('.kiss-sbi-repo-checkbox').prop('disabled', false);

                            // Show plugin info if available
                            if (response.data.plugin_data && response.data.plugin_data.plugin_name) {
                                const pluginName = response.data.plugin_data.plugin_name;
                                const version = response.data.plugin_data.version;
                                let tooltip = 'Plugin: ' + pluginName;
                                if (version) {
                                    tooltip += ' (v' + version + ')';
                                }
                                $statusCell.find('span').attr('title', tooltip);
                            }
                        }
                    } else {
                        // Not a plugin or undetected; include details if provided
                        let detail = '';
                        if (response.data && response.data.plugin_data && response.data.plugin_data.message) {
                            detail = ' (' + response.data.plugin_data.message + ')';
                        }
                        $statusCell
                            .html('<span class="kiss-sbi-plugin-no">✗ Not a Plugin</span>')
                            .removeClass('is-plugin');

                        // Remove install/status controls and disable selection for non-plugins
                        $row.find('.kiss-sbi-install-single, .kiss-sbi-check-installed').remove();
                        $row.find('.kiss-sbi-repo-checkbox').prop('checked', false).prop('disabled', true);
                    }

                    updateCheckedPlugins();
                    updateBatchInstallButton();
                } else {
                    $statusCell.html('<span class="kiss-sbi-plugin-error">Error checking</span>'); dbg('Scan failed', response);
                }

                // Call callback when done
                if (callback) {
                    callback();
                }
            },
            error: function() {
                $statusCell.html('<span class="kiss-sbi-plugin-error">Error checking</span>');

                // Call callback even on error
                if (callback) {
                    callback();
                }
            }
        });
    }

    function installSinglePlugin() {
        const $button = $(this);
        const repoName = $button.data('repo');
        const activate = $('#kiss-sbi-activate-after-install').prop('checked');

        if (!confirm('Install plugin "' + repoName + '"?')) {
            return;
        }

        const originalText = $button.text();
        $button.prop('disabled', true).text(kissSbiAjax.strings.installing);

        $.ajax({
            url: kissSbiAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'kiss_sbi_install_plugin',
                nonce: kissSbiAjax.nonce,
                repo_name: repoName,
                activate: activate
            },
            success: function(response) {
                if (response.success) {
                    if (response.data.activated) {
                        $button.remove();
                        showSuccess('Plugin "' + repoName + '" installed and activated successfully.');
                    } else {
                        // Show activate button
                        // Replace install button with Activate →
                        $button.replaceWith(
                            '<button type="button" class="button button-primary kiss-sbi-activate-plugin" data-plugin-file="' +
                            response.data.plugin_file + '" data-repo="' + repoName + '">Activate →</button>'
                        );
                        showSuccess('Plugin "' + repoName + '" installed successfully. Click "Activate →" to activate it.');
                    }
                } else {
                    $button.prop('disabled', false).text(originalText);
                    var extra = (response && response.data && response.data.logs) ? '\nDetails: ' + (Array.isArray(response.data.logs) ? response.data.logs.join(' | ') : response.data.logs) : '';
                    showError('Failed to install "' + repoName + '": ' + response.data + extra);
                    if (extra) { try { console.error('[KISS SBI] Upgrader logs:', response.data.logs); } catch(e){} }
                }
            },
            error: function() {
                $button.prop('disabled', false).text(originalText);
                showError('Ajax request failed for "' + repoName + '".');
            }
        });
    }

    function batchInstallPlugins() {
        if (checkedPlugins.size === 0) {
            alert(kissSbiAjax.strings.noSelection);
            return;
        }

        const repoNames = Array.from(checkedPlugins);
        const activate = $('#kiss-sbi-activate-after-install').prop('checked');

        if (!confirm(kissSbiAjax.strings.confirmBatch + '\n\n' + repoNames.join(', '))) {
            return;
        }

        // Show progress area
        const $progressArea = $('#kiss-sbi-install-progress');
        const $progressList = $('#kiss-sbi-progress-list');

        $progressArea.show();
        $progressList.empty();

        // Add progress items
        repoNames.forEach(function(repoName) {
            $progressList.append(
                '<div class="kiss-sbi-progress-item" data-repo="' + repoName + '">' +
                '<span class="kiss-sbi-progress-repo">' + repoName + '</span>' +
                '<span class="kiss-sbi-progress-status">Waiting...</span>' +
                '</div>'
            );
        });

        // Disable batch install button
        $('#kiss-sbi-batch-install').prop('disabled', true);

        // Install plugins sequentially
        installPluginsSequentially(repoNames, activate, 0);
    }

    function installPluginsSequentially(repoNames, activate, index) {
        if (index >= repoNames.length) {
            // All done
            showSuccess('Batch installation completed!');
            $('#kiss-sbi-batch-install').prop('disabled', false);
            return;
        }

        const repoName = repoNames[index];
        const $progressItem = $('.kiss-sbi-progress-item[data-repo="' + repoName + '"]');
        const $statusSpan = $progressItem.find('.kiss-sbi-progress-status');

        $statusSpan.text('Installing...').removeClass('error success').addClass('installing');

        $.ajax({
            url: kissSbiAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'kiss_sbi_install_plugin',
                nonce: kissSbiAjax.nonce,
                repo_name: repoName,
                activate: activate
            },
            success: function(response) {
                if (response.success) {
                    if (response.data.activated) {
                        $statusSpan.text('Installed & Activated').removeClass('installing').addClass('success');
                    } else {
                        $statusSpan.html('Installed - <button type="button" class="button button-small kiss-sbi-activate-plugin" data-plugin-file="' +
                            response.data.plugin_file + '" data-repo="' + repoName + '">Activate →</button>').removeClass('installing').addClass('success');
                    }

                    // Update single install button if visible
                    const $singleButton = $('.kiss-sbi-install-single[data-repo="' + repoName + '"]');
                    if (response.data.activated) {
                        $singleButton.remove();
                    } else {
                        $singleButton.replaceWith(
                            '<button type="button" class="button button-primary kiss-sbi-activate-plugin" data-plugin-file="' +
                            response.data.plugin_file + '" data-repo="' + repoName + '">Activate →</button>'
                        );
                    }
                } else {
                    var extra = (response && response.data && response.data.logs) ? ' — details in console' : '';
                    $statusSpan.text('Error: ' + response.data + extra).removeClass('installing').addClass('error');
                    if (response && response.data && response.data.logs) { try { console.error('[KISS SBI] Upgrader logs:', response.data.logs); } catch(e){} }
                }
            },
            error: function() {
                $statusSpan.text('Ajax Error').removeClass('installing').addClass('error');
            },
            complete: function() {
                // Move to next plugin
                setTimeout(function() {
                    installPluginsSequentially(repoNames, activate, index + 1);
                }, 500);
            }
        });
    }

    function showSuccess(message) {
        showNotice(message, 'notice-success');
    }

    function showError(message) {
        showNotice(message, 'notice-error');
    }

    function showNotice(message, type) {
        const $notice = $('<div class="notice ' + type + ' is-dismissible"><p>' + message + '</p></div>');
        $('.wrap h1').after($notice);

        // Auto-dismiss after 5 seconds
        setTimeout(function() {
            $notice.fadeOut(function() {
                $(this).remove();
            });
        }, 5000);

        // Handle dismiss button
        $notice.on('click', '.notice-dismiss', function() {
            $notice.fadeOut(function() {
                $(this).remove();
            });
        });
    }

    function checkInstalled() {
        checkInstalledStatus(this);
    }

    function checkInstalledStatus(button) {
        const $button = $(button);
        const repoName = $button.data('repo');
        const $actionsCell = $button.closest('td');
        const $installButton = $actionsCell.find('.kiss-sbi-install-single');
        const $row = $button.closest('tr');
        const $statusCell = $row.find('.kiss-sbi-plugin-status');

        $button.prop('disabled', true).text('Checking...');

        $.ajax({
            url: kissSbiAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'kiss_sbi_check_installed',
                nonce: kissSbiAjax.nonce,
                repo_name: repoName
            },
            success: function(response) {
                if (response.success && response.data.installed) {
                    const pluginData = response.data.data;
                    // Reflect status uniformly in the status cell
                    $statusCell
                        .html('<span class="kiss-sbi-plugin-yes">\u2713 Installed ' + (pluginData.active ? '(Active)' : '(Inactive)') + '</span>')
                        .addClass('is-installed');

                    // Remove the check button and install button, then show Activate only if inactive
                    $button.remove();
                    $installButton.remove();
                    if (!pluginData.active) {
                        $actionsCell.prepend(
                            '<button type="button" class="button button-primary kiss-sbi-activate-plugin" data-plugin-file="' +
                            pluginData.plugin_file + '" data-repo="' + repoName + '">Activate →</button>'
                        );
                    }

                    // Disable selection for installed plugins
                    $row.find('.kiss-sbi-repo-checkbox').prop('checked', false).prop('disabled', true);
                } else {
                    // Not installed - show install button and hide other inactive controls
                    $button.remove();
                    $statusCell.removeClass('is-installed').empty();
                    $installButton.show().prop('disabled', false);

                    // Ensure selection is available if previously disabled
                    $row.find('.kiss-sbi-repo-checkbox').prop('disabled', false);
                }

                updateCheckedPlugins();
                updateBatchInstallButton();
            },
            error: function() {
                $button.prop('disabled', false).text('Check Status');
            }
        });
    }

    function activatePlugin() {
        const $button = $(this);
        const pluginFile = $button.data('plugin-file');
        const repoName = $button.data('repo');

        if (!confirm('Activate plugin "' + repoName + '"?')) {
            return;
        }

        const originalText = $button.text();
        $button.prop('disabled', true).text('Activating...');

        $.ajax({
            url: kissSbiAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'kiss_sbi_activate_plugin',
                nonce: kissSbiAjax.nonce,
                plugin_file: pluginFile
            },
            success: function(response) {
                if (response.success) {
                    $button.replaceWith('<span class="kiss-sbi-plugin-already-activated">Already Activated</span>');
                    showSuccess('Plugin "' + repoName + '" activated successfully.');
                } else {
                    $button.prop('disabled', false).text(originalText);
                    showError('Failed to activate "' + repoName + '": ' + response.data);
                }
            },
            error: function() {
                $button.prop('disabled', false).text(originalText);
                showError('Ajax request failed for "' + repoName + '".');
            }
        });
    }
});