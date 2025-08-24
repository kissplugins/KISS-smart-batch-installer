jQuery(document).ready(function($) {
// Updates the on-screen PQS indicator in the header function bar
function kissSbiUpdatePqsIndicator(state) {
    try {
        var el = document.getElementById('kiss-sbi-pqs-indicator');
        if (!el) return;
        el.classList.remove('is-using', 'is-not-using', 'is-loading', 'is-stale');
        if (state === 'using') {
            el.classList.add('is-using');
            el.textContent = 'PQS: Using Cache';
        } else if (state === 'loading') {
            el.classList.add('is-loading');
            el.textContent = 'PQS: Building Cache…';
        } else if (state === 'stale') {
            el.classList.add('is-stale');
            el.textContent = 'PQS: Using Stale Cache';
        } else if (state === 'not-using') {
            el.classList.add('is-not-using');
            el.textContent = 'PQS: Not Using Cache';
        } else {
            el.textContent = 'PQS: Checking…';
        }
    } catch (e) {}
}

    'use strict';

    // PQS Cache Integration
    const PQSCacheIntegration = {
        init: function() {
            // Always attempt runtime detection at runtime
            kissSbiUpdatePqsIndicator('checking');
            this.integrateWithPQSCache();

            // Wire up Rebuild PQS button if present
            var rebuildBtn = document.getElementById('kiss-sbi-rebuild-pqs');
            if (rebuildBtn) {
                rebuildBtn.addEventListener('click', async function() {
                    if (typeof window.pqsRebuildCache !== 'function') {
                        console.warn('KISS SBI: pqsRebuildCache not available');
                        return;
                    }
                    try {
                        kissSbiUpdatePqsIndicator('loading');
                        rebuildBtn.disabled = true;
                        await window.pqsRebuildCache();
                        // pqs-cache-rebuilt will fire; we still ensure indicator updates
                        kissSbiUpdatePqsIndicator('using');
                    } catch (e) {
                        console.warn('KISS SBI: PQS rebuild failed', e);
                        kissSbiUpdatePqsIndicator('not-using');
                    } finally {
                        rebuildBtn.disabled = false;
                    }
                });
            }
        },

        integrateWithPQSCache: function() {
            try {
                if (typeof window.pqsCacheStatus === 'function') {
                    const status = window.pqsCacheStatus();
                    if (status === 'fresh') {
                        console.log('KISS SBI: PQS cache available, pre-scanning installed plugins');
                        this.scanInstalledPlugins();
                        kissSbiUpdatePqsIndicator('using');
                    } else if (status === 'loading') {
                        kissSbiUpdatePqsIndicator('loading');
                    } else if (status === 'stale') {
                        // Show stale state and still scan to provide partial UX benefits
                        console.log('KISS SBI: PQS cache is stale; scanning anyway for hints');
                        this.scanInstalledPlugins();
                        kissSbiUpdatePqsIndicator('stale');
                    } else if (status === 'error') {
                        kissSbiUpdatePqsIndicator('not-using');
                    } else {
                        kissSbiUpdatePqsIndicator('not-using');
                    }
                } else {
                    // Fallback: detect via localStorage without PQS JS on the page
                    const raw = localStorage.getItem('pqs_plugin_cache') || '[]';
                    let arr = [];
                    try { arr = JSON.parse(raw); } catch (_) {}
                    if (Array.isArray(arr) && arr.length > 0) {
                        console.log('KISS SBI: PQS cache detected via localStorage (no PQS JS on page)');
                        this.scanInstalledPlugins();
                        kissSbiUpdatePqsIndicator('using');
                    } else {
                        kissSbiUpdatePqsIndicator('not-using');
                    }
                }

                // Listen for PQS cache updates
                document.addEventListener('pqs-cache-rebuilt', (event) => {
                    var count = event && event.detail && typeof event.detail.pluginCount === 'number' ? event.detail.pluginCount : 'unknown';
                    console.log('KISS SBI: PQS cache rebuilt, plugins in cache:', count);
                    this.scanInstalledPlugins();
                    kissSbiUpdatePqsIndicator('using');
                });
            } catch (e) {
                console.warn('KISS SBI: PQS cache status unavailable:', e);
                kissSbiUpdatePqsIndicator('not-using');
            }
        },

        scanInstalledPlugins: function() {
            try {
                const raw = localStorage.getItem('pqs_plugin_cache') || '[]';
                const pluginData = JSON.parse(raw);
                const installedPlugins = new Map();

                pluginData.forEach(plugin => {
                    // Accept fields: slug, name, isActive, settingsUrl
                    const slug = (plugin.slug || '').toLowerCase();
                    const nameLower = (plugin.nameLower || plugin.name || '').toLowerCase();
                    const variants = [];
                    if (slug) variants.push(slug, slug.replace(/[^a-z0-9]/g, '-'), slug.replace(/[-_]/g, ''));
                    if (nameLower) variants.push(
                        nameLower,
                        nameLower.replace(/\s+/g, '-'),
                        nameLower.replace(/[^a-z0-9]/g, '-'),
                        nameLower.replace(/[-_]/g, '')
                    );

                    variants.forEach(s => {
                        if (!s) return;
                        installedPlugins.set(s, {
                            name: plugin.name || plugin.slug || s,
                            isActive: !!plugin.isActive,
                            settingsUrl: plugin.settingsUrl || ''
                        });
                    });
                });

                this.updateRepositoryTable(installedPlugins);
            } catch (error) {
                console.warn('KISS SBI: Failed to read PQS cache:', error);
                kissSbiUpdatePqsIndicator('not-using');
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
                    // Use centralized RowStateManager to keep UI consistent
                    if (window.RowStateManager && typeof window.RowStateManager.updateRow === 'function'){
                        window.RowStateManager.updateRow(repoName, {
                            isInstalled: true,
                            isActive: !!match.isActive,
                            isPlugin: true,
                            settingsUrl: match.settingsUrl || ''
                        });
                    }
                    // PROJECT-UNIFY: Avoid legacy direct DOM mutations to prevent desync
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

