jQuery(document).ready(function($) {
    'use strict';

    let checkedPlugins = new Set();
    let pluginCheckQueue = [];
    let isProcessingQueue = false;

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
        $('#kiss-sbi-batch-install').on('click', batchInstallPlugins);
        $(document).on('click', '.kiss-sbi-check-plugin', checkPlugin);
        $(document).on('click', '.kiss-sbi-install-single', installSinglePlugin);
    }

    function queueAllPluginChecks() {
        // Add all check buttons to queue
        $('.kiss-sbi-check-plugin').each(function() {
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

        $button.prop('disabled', true).text(kissSbiAjax.strings.loading || 'Loading...');

        $.ajax({
            url: kissSbiAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'kiss_sbi_refresh_repos',
                nonce: kissSbiAjax.nonce
            },
            success: function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    showError('Failed to refresh repositories: ' + response.data);
                }
            },
            error: function() {
                showError('Ajax request failed.');
            },
            complete: function() {
                $button.prop('disabled', false).text(originalText);
            }
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
                if (response.success) {
                    if (response.data.is_plugin) {
                        $statusCell.html('<span class="kiss-sbi-plugin-yes">✓ WordPress Plugin</span>')
                                  .addClass('is-plugin');

                        // Enable install button
                        $row.find('.kiss-sbi-install-single').prop('disabled', false);

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
                    } else {
                        $statusCell.html('<span class="kiss-sbi-plugin-no">✗ Not a Plugin</span>')
                                  .removeClass('is-plugin');
                    }

                    updateCheckedPlugins();
                    updateBatchInstallButton();
                } else {
                    $statusCell.html('<span class="kiss-sbi-plugin-error">Error checking</span>');
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
                    $button.text(kissSbiAjax.strings.installed).addClass('button-disabled');
                    showSuccess('Plugin "' + repoName + '" installed successfully.');
                } else {
                    $button.prop('disabled', false).text(originalText);
                    showError('Failed to install "' + repoName + '": ' + response.data);
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
                    $statusSpan.text('Installed').removeClass('installing').addClass('success');

                    // Update single install button if visible
                    const $singleButton = $('.kiss-sbi-install-single[data-repo="' + repoName + '"]');
                    $singleButton.text(kissSbiAjax.strings.installed).addClass('button-disabled').prop('disabled', true);
                } else {
                    $statusSpan.text('Error: ' + response.data).removeClass('installing').addClass('error');
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
});