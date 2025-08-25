jQuery(document).ready(function($) {
    'use strict';

    // Ensure unified-hide class is present early to hide Actions column via CSS
    try {
        if (window.kissSbiAjax && kissSbiAjax.unifiedCell) {
            document.body.classList.add('kiss-sbi-unified-hide-actions');
        }
    } catch (_) {}

    let checkedPlugins = new Set();
    let pluginCheckQueue = [];
    let isProcessingQueue = false;

    // Debug helper
    function dbg() {
        if (typeof kissSbiAjax !== 'undefined' && kissSbiAjax && kissSbiAjax.debug) {
            try { console.debug.apply(console, ['[KISS SBI]'].concat([].slice.call(arguments))); } catch (e) {}
        }
    }
    // Light client cache for row states scoped by org
    const STATE_CACHE_KEY = (function(){ try { return 'kiss_sbi_row_states_' + (kissSbiAjax && kissSbiAjax.org ? String(kissSbiAjax.org).toLowerCase() : 'default'); } catch(_) { return 'kiss_sbi_row_states_default'; } })();
    const RowStateCache = {
        load(){ try { return JSON.parse(localStorage.getItem(STATE_CACHE_KEY) || '{}'); } catch(_) { return {}; } },
        save(states){ try { localStorage.setItem(STATE_CACHE_KEY, JSON.stringify(states||{})); } catch(_){} },
        clear(){ try { localStorage.removeItem(STATE_CACHE_KEY); } catch(_){} }
    };

    // Unified row state manager with FSM
    window.RowStateManager = window.RowStateManager || (function(){
        const FSM = (window.KissSbiFSM || {});
        const STATES = FSM.STATES || {};
        const EVENTS = FSM.EVENTS || {};
        const api = {
            machines: new Map(),
            states: new Map(), // legacy snapshot cache for renderer
            getMachine(repoName){
                let m = this.machines.get(repoName);
                if (!m && FSM.createMachine){
                    m = FSM.createMachine(STATES.UNKNOWN, {});
                    this.machines.set(repoName, m);
                }
                return m;
            },
            updateFromSnapshot(repoName, snap){
                this.states.set(repoName, snap);
                try { const obj = {}; this.states.forEach((v,k)=>{ obj[k]=v; }); RowStateCache.save(obj); } catch(_){ }
                this.renderRow(repoName, snap);
            },
            updateRow(repoName, updates){
                // preserve backward compatibility: merge into last snapshot then refresh FSM
                const current = this.states.get(repoName) || this.getDefaultState(repoName);
                const next = Object.assign({}, current, updates);
                const m = this.getMachine(repoName);
                // Status refresh drives the machine to a consistent state
                if (FSM && m && EVENTS.STATUS_REFRESH){ m.handle(EVENTS.STATUS_REFRESH, next); }
                const snap = m && m.snapshot ? m.snapshot(repoName) : next;
                this.updateFromSnapshot(repoName, snap);
            },
            getDefaultState(repoName){
                return { repoName, isPlugin: null, isInstalled: null, isActive: null, pluginFile: null, settingsUrl: null, checking: false, installing: false, error: null, fsmState: (STATES && STATES.UNKNOWN) || 'UNKNOWN' };
            },
            renderRow(repoName, state){
                const $row = $('tr[data-repo="' + repoName + '"]');
                if (!$row.length) return;
                const $statusCell = $row.find('.kiss-sbi-plugin-status');
                const $actionsCell = $row.find('td').last();
                $statusCell.html(this.renderStatusCell(state));
                // Keep legacy CSS hooks for other code paths
                $statusCell.toggleClass('is-plugin', state.isPlugin === true);
                $statusCell.toggleClass('is-installed', state.isInstalled === true);

                if (kissSbiAjax && kissSbiAjax.unifiedCell) {
                    // Unified mode: hide/empty legacy Actions column to avoid duplication
                    $actionsCell.empty();
                } else {
                    $actionsCell.html(this.renderActionsCell(state));
                }
                $row.toggleClass('plugin-installed', state.isInstalled === true);
                $row.toggleClass('plugin-checking', !!state.checking);
                $row.toggleClass('plugin-error', !!state.error);
                // Keep batch checkbox in sync
                $row.find('.kiss-sbi-repo-checkbox').prop('disabled', state.isInstalled === true);
            },
            renderStatusCell(state){
                // Unified cell rendering when feature flag is on
                if (kissSbiAjax && kissSbiAjax.unifiedCell) {
                    const parts = [];
                    // Status pill
                    if (state.checking) {
                        parts.push('<div class="kiss-sbi-status-pill" aria-live="polite"><span class="dashicons dashicons-update spin"></span> Checking…</div>');
                    } else if (state && state.error) {
                        parts.push('<div class="kiss-sbi-status-pill is-error kiss-sbi-tooltip" title="' + String(state.error).replace(/"/g,'&quot;') + '"><span class="dashicons dashicons-warning"></span> Error</div>');
                    } else if (state.isInstalled === true) {
                        const act = state.isActive ? '(Active)' : '(Inactive)';
                        parts.push('<div class="kiss-sbi-status-pill is-installed"><span class="dashicons dashicons-yes"></span> Installed ' + act + '</div>');
                    } else if (state.isPlugin === true) {
                        parts.push('<div class="kiss-sbi-status-pill is-plugin"><span class="dashicons dashicons-plugins-checked"></span> WordPress Plugin</div>');
                    } else if (state.isPlugin === false) {
                        parts.push('<div class="kiss-sbi-status-pill is-not-plugin"><span class="dashicons dashicons-dismiss"></span> Not a Plugin</div>');
                    } else {
                        parts.push('<div class="kiss-sbi-status-pill is-unknown"><span class="dashicons dashicons-info"></span> Unknown</div>');
                    }
                    // Actions row
                    let actions = '';
                    if (state.installing) {
                        actions = '<button class="button button-small" disabled><span class="dashicons dashicons-update spin"></span> Installing…</button>';
                    } else if (state.isInstalled === true) {
                        let effectiveSettingsUrl = state.settingsUrl || '';
                        const isPqs = !!(state.repoName && /^(kiss-)?plugin(-)?quick(-)?search$/i.test(String(state.repoName).replace(/^kiss[- ]/i,'kiss-')));
                        if (isPqs && !effectiveSettingsUrl){
                            try {
                                const baseAjax = (typeof kissSbiAjax !== 'undefined' && kissSbiAjax && kissSbiAjax.ajaxUrl) ? kissSbiAjax.ajaxUrl : (typeof ajaxurl === 'string' ? ajaxurl : '');
                                effectiveSettingsUrl = baseAjax && baseAjax.indexOf('admin-ajax.php') !== -1 ? baseAjax.replace('admin-ajax.php','plugins.php?page=pqs-cache-status') : (window.location.origin + '/wp-admin/plugins.php?page=pqs-cache-status');
                            } catch(_) { effectiveSettingsUrl = '/wp-admin/plugins.php?page=pqs-cache-status'; }
                        }
                        if (state.isActive === false && state.pluginFile) {
                            actions = '<button type="button" class="button button-primary kiss-sbi-activate-plugin" data-plugin-file="' + state.pluginFile + '" data-repo="' + state.repoName + '"><span class="dashicons dashicons-yes"></span> Activate →</button>';
                        } else {
                            if (effectiveSettingsUrl) {
                                actions = ' <a href="' + effectiveSettingsUrl + '" class="button button-small"><span class="dashicons dashicons-admin-generic"></span> Settings</a>';
                            } else {
                                actions = '';
                            }
                        }
                    } else if (state.isPlugin === true) {
                        actions = '<button type="button" class="button button-small kiss-sbi-install-single" data-repo="' + state.repoName + '"><span class="dashicons dashicons-download"></span> Install</button>';
                    } else if (state && state.error) {
                        actions = '<button type="button" class="button button-small kiss-sbi-check-installed" data-repo="' + state.repoName + '">Retry</button>';
                    } else {
                        actions = '<button type="button" class="button button-small kiss-sbi-check-plugin" data-repo="' + state.repoName + '"><span class="dashicons dashicons-search"></span> Check</button>';
                    }
                    parts.push('<div class="kiss-sbi-actions">' + actions + '</div>');
                    return '<div class="kiss-sbi-unified">' + parts.join('') + '</div>';
                }

                // Legacy: separate columns rendering (default when feature flag is off)
                if (state.checking) {
                    return '<span class="kiss-sbi-plugin-checking" title="Checking status…"><span class="dashicons dashicons-update spin" aria-hidden="true"></span> Checking…</span>';
                }
                if (state && state.error) {
                    return '<span class="kiss-sbi-plugin-error kiss-sbi-tooltip" title="' + String(state.error).replace(/"/g,'&quot;') + '"><span class="dashicons dashicons-warning" aria-hidden="true"></span> Error</span>' +
                           ' <button type="button" class="button button-small kiss-sbi-check-installed" data-repo="' + state.repoName + '">Retry</button>';
                }
                if (state.isInstalled === true){
                    return '<span class="kiss-sbi-plugin-yes" title="Installed"><span class="dashicons dashicons-yes" aria-hidden="true"></span> Installed ' + (state.isActive ? '(Active)' : '(Inactive)') + '</span>';
                }
                if (state.isPlugin === true){
                    return '<span class="kiss-sbi-plugin-yes" title="WordPress Plugin"><span class="dashicons dashicons-plugins-checked" aria-hidden="true"></span> WordPress Plugin</span>';
                }
                if (state.isPlugin === false){
                    return '<span class="kiss-sbi-plugin-no" title="Not a WordPress plugin"><span class="dashicons dashicons-dismiss" aria-hidden="true"></span> Not a Plugin</span>';
                }
                return '<button type="button" class="button button-small kiss-sbi-check-plugin" data-repo="' + state.repoName + '"><span class="dashicons dashicons-search" aria-hidden="true"></span> Check</button>';
            },
            renderActionsCell(state){
                // Note: SBI-own row is handled separately by PHP; do not interfere
                if (state.installing) return '<button class="button button-small" disabled><span class="dashicons dashicons-update spin" aria-hidden="true"></span> Installing…</button>';
                if (state.isInstalled === true){
                    let html = '';
                    // Compute effective Settings URL (hardwire PQS settings if applicable)
                    let effectiveSettingsUrl = state.settingsUrl || '';
                    const isPqs = !!(state.repoName && /^(kiss-)?plugin(-)?quick(-)?search$/i.test(String(state.repoName).replace(/^kiss[- ]/i,'kiss-')));
                    if (isPqs && !effectiveSettingsUrl){
                        try {
                            const baseAjax = (typeof kissSbiAjax !== 'undefined' && kissSbiAjax && kissSbiAjax.ajaxUrl) ? kissSbiAjax.ajaxUrl : (typeof ajaxurl === 'string' ? ajaxurl : '');
                            effectiveSettingsUrl = baseAjax && baseAjax.indexOf('admin-ajax.php') !== -1 ? baseAjax.replace('admin-ajax.php','plugins.php?page=pqs-cache-status') : (window.location.origin + '/wp-admin/plugins.php?page=pqs-cache-status');
                        } catch(_) {
                            effectiveSettingsUrl = '/wp-admin/plugins.php?page=pqs-cache-status';
                        }
                    }
                    if (state.isActive === false && state.pluginFile){
                        html += '<button type="button" class="button button-primary kiss-sbi-activate-plugin" data-plugin-file="' + state.pluginFile + '" data-repo="' + state.repoName + '"><span class="dashicons dashicons-yes" aria-hidden="true"></span> Activate →</button>';
                    } else {
                        if (effectiveSettingsUrl){
                            // No 'Already Activated' label to keep UI clean
                        }
                    }
                    if (effectiveSettingsUrl){
                        html += ' <a href="' + effectiveSettingsUrl + '" class="button button-small"><span class="dashicons dashicons-admin-generic" aria-hidden="true"></span> Settings</a>';
                    }
                    return html;
                }
                if (state.isPlugin === true){
                    return '<button type="button" class="button button-small kiss-sbi-install-single" data-repo="' + state.repoName + '"><span class="dashicons dashicons-download" aria-hidden="true"></span> Install</button>';
                }
                return '<button type="button" class="button button-small kiss-sbi-check-installed" data-repo="' + state.repoName + '"><span class="dashicons dashicons-search" aria-hidden="true"></span> Check Status</button>';
            }
        };
        return api;
    })();


    // Initialize
    init();

    function init() {
        bindEvents();
        updateBatchInstallButton();

        // Unified staged init per PROJECT-UNIFY: seed states, then check
        try { initializeUnifiedRows(); } catch(e) { dbg('Unified init failed', e); }
    }
        function initializeUnifiedRows(){
            // Stage 0: hydrate from client cache (instant render)
            try {
                const cached = RowStateCache.load();
                if (cached && typeof cached === 'object'){
                    Object.keys(cached).forEach(function(repo){ RowStateManager.updateRow(repo, cached[repo]); });
                }
            } catch(e){ dbg('Cache hydrate failed', e); }

            // Stage 1: defaults + checking flag for visible rows not in cache
            $('.wp-list-table tbody tr').each(function(){
                const repo = $(this).data('repo'); if (!repo) return;
                if (!RowStateManager.states.has(repo)) {
                    RowStateManager.updateRow(repo, { checking: true });
                }
            });

            // Stage 2: PQS hints (if integration is present)
            try {
                if (window.KissSbiPQSIntegration && typeof window.KissSbiPQSIntegration.scanInstalledPlugins === 'function'){
                    // Reuse PQS cache directly
                    const raw = localStorage.getItem('pqs_plugin_cache') || '[]';
                    let arr = []; try { arr = JSON.parse(raw); } catch(_){}
                    if (Array.isArray(arr)){
                        arr.forEach(function(plugin){
                            const slug = (plugin.slug || '').toLowerCase();
                            const name = (plugin.nameLower || plugin.name || '').toLowerCase();
                            const variants = [];
                            if (slug) variants.push(slug, slug.replace(/[^a-z0-9]/g,'-'), slug.replace(/[-_]/g,''));
                            if (name) variants.push(name, name.replace(/[^a-z0-9]/g,'-'), name.replace(/[-_]/g,''));
                            // Map variants back to row by matching row repoName variants
                            $('.wp-list-table tbody tr').each(function(){
                                const repo = ($(this).data('repo')||'').toString(); if (!repo) return;
                                const lower = repo.toLowerCase();
                                const repoVariants = [lower, lower.replace(/[^a-z0-9]/g,'-'), lower.replace(/[-_]/g,'')];
                                for (let v of repoVariants){
                                    if (variants.includes(v)){
                                        RowStateManager.updateRow(repo, { isInstalled: true, isActive: !!plugin.isActive, isPlugin: true, settingsUrl: plugin.settingsUrl || null, checking: false });
                                        break;
                                    }
                                }
                            });
                        });
                    }
                }
            } catch(e) { dbg('PQS stage failed', e); }

            // Stage 3: server-side install checks for each row, serialized to reduce load
            const rows = $('.wp-list-table tbody tr').map(function(){ return ($(this).data('repo')||'').toString(); }).get();
            let idx = 0;
            function next(){ if (idx >= rows.length) return stage4(); checkInstalledFor(rows[idx++], next); }
            next();

            function stage4(){
                // Stage 4: fall back to manual checks for any unknowns (still using unified endpoint)
                queueAllPluginChecks();
            }
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

        // Clear client cache so a full rescan happens after refresh
        try { RowStateCache.clear(); } catch(_){ }

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
        try { RowStateCache.clear(); } catch(_){ }
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

    // Helper used by unified init to check install state and unify rendering
    function checkInstalledFor(repoName, done){
        const $row = $('tr[data-repo="' + repoName + '"]');
        if (!$row.length){ if (done) done(); return; }
        const m = RowStateManager.getMachine(repoName);
        if (m && window.KissSbiFSM && KissSbiFSM.EVENTS && KissSbiFSM.EVENTS.CHECK_START) m.handle(KissSbiFSM.EVENTS.CHECK_START);
        $.ajax({
            url: kissSbiAjax.ajaxUrl,
            type: 'POST',
            data: { action: 'kiss_sbi_get_row_status', nonce: kissSbiAjax.nonce, repo_name: repoName },
            success: function(response){
                if (response && response.success && response.data){
                    const payload = response.data;
                    payload.checking = false;
                    const m = RowStateManager.getMachine(repoName);
                    if (m && window.KissSbiFSM && KissSbiFSM.EVENTS && KissSbiFSM.EVENTS.STATUS_REFRESH){ m.handle(KissSbiFSM.EVENTS.STATUS_REFRESH, payload); }
                    const snap = m && m.snapshot ? m.snapshot(repoName) : payload;
                    RowStateManager.updateFromSnapshot(repoName, snap);
                } else {
                    const m = RowStateManager.getMachine(repoName);
                    if (m && window.KissSbiFSM && KissSbiFSM.EVENTS && KissSbiFSM.EVENTS.CHECK_FAIL){ m.handle(KissSbiFSM.EVENTS.CHECK_FAIL, { error: 'status_failed' }); }
                    const snap = m && m.snapshot ? m.snapshot(repoName) : { checking:false, error:'status_failed' };
                    RowStateManager.updateFromSnapshot(repoName, snap);
                }
            },
            error: function(){
                const m = RowStateManager.getMachine(repoName);
                if (m && window.KissSbiFSM && KissSbiFSM.EVENTS && KissSbiFSM.EVENTS.CHECK_FAIL){ m.handle(KissSbiFSM.EVENTS.CHECK_FAIL, { error: 'status_failed' }); }
                const snap = m && m.snapshot ? m.snapshot(repoName) : { checking:false, error:'status_failed' };
                RowStateManager.updateFromSnapshot(repoName, snap);
            },
            complete: function(){ if (done) done(); }
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
                action: 'kiss_sbi_get_row_status',
                nonce: kissSbiAjax.nonce,
                repo_name: repoName
            },
            success: function(response) {
                dbg('Status response', response);
                if (response && response.success && response.data) {
                    const payload = response.data;
                    payload.checking = false;
                    RowStateManager.updateRow(repoName, payload);
                    updateCheckedPlugins();
                    updateBatchInstallButton();
                } else {
                    RowStateManager.updateRow(repoName, { checking: false, error: 'status_failed' });
                    dbg('Status failed', response);
                }

                // Call callback when done
                if (callback) {
                    callback();
                }
            },
            error: function() {
                RowStateManager.updateRow(repoName, { checking: false, error: 'status_failed' });

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

        // FSM: INSTALL_START
        try { const m = RowStateManager.getMachine(repoName); if (m && KissSbiFSM && KissSbiFSM.EVENTS && KissSbiFSM.EVENTS.INSTALL_START) m.handle(KissSbiFSM.EVENTS.INSTALL_START); } catch(_){ }

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
                const m = RowStateManager.getMachine(repoName);
                if (response.success) {
                    if (m && KissSbiFSM && KissSbiFSM.EVENTS && KissSbiFSM.EVENTS.INSTALL_SUCCESS) m.handle(KissSbiFSM.EVENTS.INSTALL_SUCCESS, { activated: !!response.data.activated, plugin_file: response.data.plugin_file });
                    const snap = m && m.snapshot ? m.snapshot(repoName) : { isInstalled:true, isActive:!!response.data.activated, pluginFile: response.data.plugin_file || null, isPlugin:true, checking:false, installing:false };
                    RowStateManager.updateFromSnapshot(repoName, snap);
                    if (response.data.activated) {
                        showSuccess('Plugin "' + repoName + '" installed and activated successfully.');
                    } else {
                        showSuccess('Plugin "' + repoName + '" installed successfully. Click "Activate →" to activate it.');
                    }
                } else {
                    if (m && KissSbiFSM && KissSbiFSM.EVENTS && KissSbiFSM.EVENTS.INSTALL_FAIL) m.handle(KissSbiFSM.EVENTS.INSTALL_FAIL, { error: response.data });
                    const snap = m && m.snapshot ? m.snapshot(repoName) : { checking:false, installing:false, error: String(response && response.data || 'install_failed') };
                    RowStateManager.updateFromSnapshot(repoName, snap);
                    $button.prop('disabled', false).text(originalText);
                    var extra = (response && response.data && response.data.logs) ? '\nDetails: ' + (Array.isArray(response.data.logs) ? response.data.logs.join(' | ') : response.data.logs) : '';
                    showError('Failed to install "' + repoName + '": ' + response.data + extra);
                    if (extra) { try { console.error('[KISS SBI] Upgrader logs:', response.data.logs); } catch(e){} }
                }
            },
            error: function() {
                const m = RowStateManager.getMachine(repoName);
                if (m && KissSbiFSM && KissSbiFSM.EVENTS && KissSbiFSM.EVENTS.INSTALL_FAIL) m.handle(KissSbiFSM.EVENTS.INSTALL_FAIL, { error: 'ajax_error' });
                const snap = m && m.snapshot ? m.snapshot(repoName) : { checking:false, installing:false, error: 'ajax_error' };
                RowStateManager.updateFromSnapshot(repoName, snap);
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
                        RowStateManager.updateRow(repoName, { isInstalled: true, isActive: true, pluginFile: response.data.plugin_file || null, installing: false, checking: false });
                        $statusSpan.text('Installed & Activated').removeClass('installing').addClass('success');
                    } else {
                        RowStateManager.updateRow(repoName, { isInstalled: true, isActive: false, pluginFile: response.data.plugin_file || null, installing: false, checking: false });
                        $statusSpan.text('Installed').removeClass('installing').addClass('success');
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
                action: 'kiss_sbi_get_row_status',
                nonce: kissSbiAjax.nonce,
                repo_name: repoName
            },
            success: function(response) {
                if (response && response.success && response.data) {
                    const payload = response.data;
                    payload.checking = false;
                    RowStateManager.updateRow(repoName, payload);
                } else {
                    RowStateManager.updateRow(repoName, { checking: false, error: 'status_failed' });
                }
            },
            error: function() {
                $button.prop('disabled', false).text('Check Status');
                RowStateManager.updateRow(repoName, { checking: false, error: 'status_failed' });
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

        // FSM: ACTIVATE_START
        try { const m = RowStateManager.getMachine(repoName); if (m && KissSbiFSM && KissSbiFSM.EVENTS && KissSbiFSM.EVENTS.ACTIVATE_START) m.handle(KissSbiFSM.EVENTS.ACTIVATE_START); } catch(_){ }

        $.ajax({
            url: kissSbiAjax.ajaxUrl,
            type: 'POST',
            data: {
                action: 'kiss_sbi_activate_plugin',
                nonce: kissSbiAjax.nonce,
                plugin_file: pluginFile
            },
            success: function(response) {
                const m = RowStateManager.getMachine(repoName);
                if (response.success) {
                    if (m && KissSbiFSM && KissSbiFSM.EVENTS && KissSbiFSM.EVENTS.ACTIVATE_SUCCESS) m.handle(KissSbiFSM.EVENTS.ACTIVATE_SUCCESS);
                    const snap = m && m.snapshot ? m.snapshot(repoName) : { isActive:true, isInstalled:true, pluginFile: pluginFile, checking:false };
                    RowStateManager.updateFromSnapshot(repoName, snap);
                    showSuccess('Plugin "' + repoName + '" activated successfully.');
                } else {
                    if (m && KissSbiFSM && KissSbiFSM.EVENTS && KissSbiFSM.EVENTS.ACTIVATE_FAIL) m.handle(KissSbiFSM.EVENTS.ACTIVATE_FAIL, { error: response.data });
                    const snap = m && m.snapshot ? m.snapshot(repoName) : { checking:false, error: String(response && response.data || 'activate_failed') };
                    RowStateManager.updateFromSnapshot(repoName, snap);
                    $button.prop('disabled', false).text(originalText);
                    showError('Failed to activate "' + repoName + '": ' + response.data);
                }
            },
            error: function() {
                const m = RowStateManager.getMachine(repoName);
                if (m && KissSbiFSM && KissSbiFSM.EVENTS && KissSbiFSM.EVENTS.ACTIVATE_FAIL) m.handle(KissSbiFSM.EVENTS.ACTIVATE_FAIL, { error: 'ajax_error' });
                const snap = m && m.snapshot ? m.snapshot(repoName) : { checking:false, error: 'ajax_error' };
                RowStateManager.updateFromSnapshot(repoName, snap);
                $button.prop('disabled', false).text(originalText);
                showError('Ajax request failed for "' + repoName + '".');
            }
        });
    }

    // Expose a tiny API for integrations (e.g., PQS) to focus/highlight a row
    // Usage: window.kissSbiFocusRowByKey('my-plugin')
    // - key can be a repo name or plugin slug; we match by common variants
    window.kissSbiFocusRowByKey = function(key){
        try {
            if (!key) return false;
            const k = String(key).toLowerCase();
            const variants = [k, k.replace(/[^a-z0-9]/g,'-'), k.replace(/[-_]/g,'')];
            const rows = Array.from(document.querySelectorAll('.wp-list-table tbody tr[data-repo]'));
            function repoVariants(repo){
                const lower = String(repo||'').toLowerCase();
                return [lower, lower.replace(/[^a-z0-9]/g,'-'), lower.replace(/[-_]/g,'')];
            }
            for (const tr of rows){
                const repo = tr.getAttribute('data-repo');
                const rvars = repoVariants(repo);
                if (variants.some(v => rvars.includes(v))){
                    tr.classList.add('kiss-sbi-highlight');
                    tr.scrollIntoView({ behavior:'smooth', block:'center' });
                    // Focus checkbox or primary action for accessibility
                    const cb = tr.querySelector('.kiss-sbi-repo-checkbox');
                    if (cb) cb.focus();
                    setTimeout(() => tr.classList.remove('kiss-sbi-highlight'), 1600);
                    return true;
                }
            }
            return false;
        } catch(e){ try{ console.warn('[KISS SBI] focus API failed', e); }catch(_){} return false; }
    };

    // Variant: red highlight box preferred by Quick Search selection
    window.kissSbiFocusRowRed = function(key){
        try {
            if (!key) return false;
            const k = String(key).toLowerCase();
            const variants = [k, k.replace(/[^a-z0-9]/g,'-'), k.replace(/[-_]/g,'')];
            const rows = Array.from(document.querySelectorAll('.wp-list-table tbody tr[data-repo]'));
            function repoVariants(repo){
                const lower = String(repo||'').toLowerCase();
                return [lower, lower.replace(/[^a-z0-9]/g,'-'), lower.replace(/[-_]/g,'')];
            }
            for (const tr of rows){
                const repo = tr.getAttribute('data-repo');
                const rvars = repoVariants(repo);
                if (variants.some(v => rvars.includes(v))){
                    tr.classList.add('kiss-sbi-red-highlight');
                    tr.scrollIntoView({ behavior:'smooth', block:'center' });
                    const cb = tr.querySelector('.kiss-sbi-repo-checkbox');
                    if (cb) cb.focus();
                    setTimeout(() => tr.classList.remove('kiss-sbi-red-highlight'), 1500);
                    return true;
                }
            }
            return false;
        } catch(e){ try{ console.warn('[KISS SBI] focus API (red) failed', e); }catch(_){} return false; }
    };

    // Optional: announce that the API is ready for late listeners
    try { document.dispatchEvent(new CustomEvent('kiss-sbi-focus-ready')); } catch(_){}


});