jQuery(document).ready(function($) {
    'use strict';

    // PQS Cache Integration
    const PQSCacheIntegration = {
        init: function() {
            if (typeof kissSbiAjax !== 'undefined' && kissSbiAjax && kissSbiAjax.hasPQS && typeof window.pqsCacheStatus === 'function') {
                this.integrateWithPQSCache();
            }
        },

        integrateWithPQSCache: function() {
            try {
                const status = window.pqsCacheStatus();
                if (status === 'fresh') {
                    console.log('KISS SBI: PQS cache available, pre-scanning installed plugins');
                    this.scanInstalledPlugins();
                }

                // Listen for PQS cache updates
                document.addEventListener('pqs-cache-rebuilt', () => {
                    console.log('KISS SBI: PQS cache rebuilt, rescanning installed plugins');
                    this.scanInstalledPlugins();
                });
            } catch (e) {
                console.warn('KISS SBI: PQS cache status unavailable:', e);
            }
        },

        scanInstalledPlugins: function() {
            try {
                const raw = localStorage.getItem('pqs_plugin_cache') || '[]';
                const pluginData = JSON.parse(raw);
                const installedPlugins = new Map();

                pluginData.forEach(plugin => {
                    // Create several slug variants for matching
                    const nameLower = (plugin.nameLower || plugin.name || '').toLowerCase();
                    const variants = [
                        nameLower.replace(/\s+/g, '-'),
                        nameLower.replace(/[^a-z0-9]/g, '-'),
                        nameLower.replace(/[-_]/g, '')
                    ];

                    variants.forEach(slug => {
                        installedPlugins.set(slug, {
                            name: plugin.name,
                            isActive: !!plugin.isActive,
                            settingsUrl: plugin.settingsUrl || ''
                        });
                    });
                });

                this.updateRepositoryTable(installedPlugins);
            } catch (error) {
                console.warn('KISS SBI: Failed to read PQS cache:', error);
            }
        },

        updateRepositoryTable: function(installedPlugins) {
            $('.wp-list-table tbody tr').each(function() {
                const $row = $(this);
                const repoName = ($row.data('repo') || '').toString();
                if (!repoName) return;

                // Generate possible plugin slugs from repo name
                const lower = repoName.toLowerCase();
                const possibleSlugs = [
                    lower,
                    lower.replace(/[^a-z0-9]/g, '-'),
                    lower.replace(/[-_]/g, '')
                ];

                let match = null;
                for (const slug of possibleSlugs) {
                    if (installedPlugins.has(slug)) {
                        match = installedPlugins.get(slug);
                        break;
                    }
                }

                if (match) {
                    const $statusCell = $row.find('.kiss-sbi-plugin-status');
                    const $installButton = $row.find('.kiss-sbi-install-single');

                    $statusCell
                        .html('<span class="kiss-sbi-plugin-yes">\u2713 Installed ' + (match.isActive ? '(Active)' : '(Inactive)') + '</span>')
                        .addClass('is-installed');

                    // Disable install actions
                    $installButton.text('Installed').addClass('button-disabled').prop('disabled', true).show();

                    // Add settings link if available
                    if (match.settingsUrl) {
                        $installButton.after(' <a href="' + match.settingsUrl + '" class="button button-small">Settings</a>');
                    }

                    // Disable checkbox to prevent batch install selection
                    $row.find('.kiss-sbi-repo-checkbox').prop('disabled', true);
                }
            });

            // Update batch install button state if helper is present
            if (typeof window.updateBatchInstallButton === 'function') {
                window.updateBatchInstallButton();
            }
        }
    };

    // Initialize PQS integration
    PQSCacheIntegration.init();

    // Expose globally
    window.KissSbiPQSIntegration = PQSCacheIntegration;
});

