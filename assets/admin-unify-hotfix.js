'use strict';
// This file defines checkInstalledFor in a guaranteed global scope and can be enqueued after admin.js if needed.
window.kissSbiEnsureCheckInstalledFor = window.kissSbiEnsureCheckInstalledFor || function(){
  if (typeof window.checkInstalledFor === 'function') return;
  window.checkInstalledFor = function(repoName, done){
    try {
      var $ = window.jQuery;
      const $row = $('tr[data-repo="' + repoName + '"]');
      if (!$row.length){ if (done) done(); return; }
      $.ajax({
        url: kissSbiAjax.ajaxUrl,
        type: 'POST',
        data: { action: 'kiss_sbi_check_installed', nonce: kissSbiAjax.nonce, repo_name: repoName },
        success: function(response){
          if (response && response.success && response.data && response.data.installed && response.data.data){
            const d = response.data.data;
            window.RowStateManager && window.RowStateManager.updateRow(repoName, { isInstalled: true, isActive: !!d.active, pluginFile: d.plugin_file || null, isPlugin: true, checking: false });
          } else {
            window.RowStateManager && window.RowStateManager.updateRow(repoName, { isInstalled: false, checking: false });
          }
        },
        error: function(){ window.RowStateManager && window.RowStateManager.updateRow(repoName, { error: 'check_failed', checking: false }); },
        complete: function(){ if (done) done(); }
      });
    } catch(e) { try { console.error('[KISS SBI] unify hotfix error', e); } catch(_){} if (done) done(); }
  };
};

