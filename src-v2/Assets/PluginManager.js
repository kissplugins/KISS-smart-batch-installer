/**
 * Plugin Manager JavaScript for KISS Smart Batch Installer v2
 * 
 * Modern JavaScript for handling plugin operations with proper
 * event delegation and error handling.
 */

class PluginManagerV2 {
    constructor() {
        this.init();
    }
    
    init() {
        this.bindEvents();
        this.initializeBulkActions();
        this.showPhase1Message();
    }
    
    bindEvents() {
        // Single event delegation for all actions
        document.addEventListener('click', (e) => {
            const target = e.target.closest('[data-action]');
            if (!target) return;
            
            const action = target.dataset.action;
            const repo = target.dataset.repo;
            
            if (!repo) return;
            
            switch(action) {
                case 'check':
                    this.checkPlugin(repo, target);
                    break;
                case 'install':
                    this.installPlugin(repo, target);
                    break;
                case 'activate':
                    this.activatePlugin(repo, target);
                    break;
                case 'retry':
                    this.retryPlugin(repo, target);
                    break;
            }
        });
    }
    
    async checkPlugin(repo, button) {
        const originalText = button.textContent;
        button.disabled = true;
        button.textContent = kissSbiV2Ajax.strings.checking;
        
        try {
            const response = await this.apiCall('kiss_sbi_v2_check_plugin', { repo_name: repo });
            
            if (response.success) {
                this.updatePluginRow(repo, response.data);
            } else {
                this.showError(kissSbiV2Ajax.strings.checkError.replace('%s', repo).replace('%s', response.data));
                button.disabled = false;
                button.textContent = originalText;
            }
        } catch (error) {
            this.showError(kissSbiV2Ajax.strings.checkError.replace('%s', repo).replace('%s', error.message));
            button.disabled = false;
            button.textContent = originalText;
        }
    }
    
    async installPlugin(repo, button) {
        if (!confirm(kissSbiV2Ajax.strings.confirmInstall.replace('%s', repo))) return;
        
        const originalText = button.textContent;
        button.disabled = true;
        button.textContent = kissSbiV2Ajax.strings.installing;
        
        try {
            const response = await this.apiCall('kiss_sbi_v2_install_plugin', {
                repo_name: repo,
                activate: document.getElementById('activate-after-install')?.checked || false
            });
            
            if (response.success) {
                this.updatePluginRow(repo, response.data);
                this.showSuccess(kissSbiV2Ajax.strings.installSuccess.replace('%s', repo));
            } else {
                this.showError(kissSbiV2Ajax.strings.installError.replace('%s', repo).replace('%s', response.data));
                button.disabled = false;
                button.textContent = originalText;
            }
        } catch (error) {
            this.showError(kissSbiV2Ajax.strings.installError.replace('%s', repo).replace('%s', error.message));
            button.disabled = false;
            button.textContent = originalText;
        }
    }
    
    async activatePlugin(repo, button) {
        // Implementation for Phase 2
        this.showError('Activate functionality will be available in Phase 2');
    }
    
    async retryPlugin(repo, button) {
        // Retry is essentially the same as check
        this.checkPlugin(repo, button);
    }
    
    updatePluginRow(repo, pluginData) {
        // Implementation for Phase 2
        console.log('Update plugin row:', repo, pluginData);
    }
    
    async apiCall(action, data = {}) {
        const formData = new FormData();
        formData.append('action', action);
        formData.append('nonce', kissSbiV2Ajax.nonce);
        
        Object.entries(data).forEach(([key, value]) => {
            formData.append(key, value);
        });
        
        const response = await fetch(kissSbiV2Ajax.ajaxUrl, {
            method: 'POST',
            body: formData
        });
        
        return await response.json();
    }
    
    showSuccess(message) {
        this.showNotice(message, 'notice-success');
    }
    
    showError(message) {
        this.showNotice(message, 'notice-error');
    }
    
    showNotice(message, type) {
        const notice = document.createElement('div');
        notice.className = `notice ${type} is-dismissible`;
        notice.innerHTML = `<p>${message}</p>`;
        
        const wrap = document.querySelector('.wrap h1');
        if (wrap) {
            wrap.insertAdjacentElement('afterend', notice);
            
            // Auto-dismiss after 5 seconds
            setTimeout(() => notice.remove(), 5000);
        }
    }
    
    initializeBulkActions() {
        // Implementation for Phase 2
        console.log('Bulk actions will be available in Phase 2');
    }
    
    showPhase1Message() {
        console.log('KISS Smart Batch Installer v2 - Phase 1 Active');
        console.log('Full functionality will be available in Phase 2');
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    new PluginManagerV2();
});
