'use strict';
// Lightweight in-browser tests for RowStateManager, reported via Self Tests page.
// No external deps; runs only on the Self Tests admin page.
(function(){
  function runTests(report){
    var results = { passed: 0, failed: 0, details: [] };

    function assert(name, cond){
      if (cond) { results.passed++; } else { results.failed++; results.details.push('FAIL: ' + name); }
    }

    try {
      // Sanity: RowStateManager exists
      assert('RowStateManager exists', !!(window.RowStateManager && typeof window.RowStateManager.updateRow === 'function'));

      if (window.RowStateManager){
        // Reset state map if available
        try { window.RowStateManager.states = new Map(); } catch(_){}

        // Default state and merge
        var repo = 'test-repo';
        window.RowStateManager.updateRow(repo, { checking: true });
        var st1 = window.RowStateManager.states.get(repo);
        assert('Default state seeded', !!st1 && st1.repoName === repo && st1.checking === true);
        assert('Default fields present', 'isPlugin' in st1 && 'isInstalled' in st1 && 'isActive' in st1 && 'pluginFile' in st1 && 'settingsUrl' in st1 && 'installing' in st1 && 'error' in st1);

        // Merge preserves and updates
        window.RowStateManager.updateRow(repo, { isPlugin: true });
        var st2 = window.RowStateManager.states.get(repo);
        assert('Merge preserves previous flags', st2.checking === true);
        assert('Merge applies updates', st2.isPlugin === true);

        // Installed + active update
        window.RowStateManager.updateRow(repo, { isInstalled: true, isActive: true, checking: false, pluginFile: 'foo/bar.php' });
        var st3 = window.RowStateManager.states.get(repo);
        assert('Installed set', st3.isInstalled === true);
        assert('Active set', st3.isActive === true);
        assert('Checking cleared', st3.checking === false);
        assert('Plugin file set', st3.pluginFile === 'foo/bar.php');

        // Error flag
        window.RowStateManager.updateRow(repo, { error: 'status_failed' });
        var st4 = window.RowStateManager.states.get(repo);
        assert('Error set', !!st4.error);
      }

    } catch (e) {
      results.failed++;
      results.details.push('Exception during tests: ' + (e && e.message ? e.message : e));
    }

    var summary = 'Passed: ' + results.passed + ', Failed: ' + results.failed + (results.details.length ? (' | ' + results.details.join(' | ')) : '');
    try {
      if (report && typeof report.addOrUpdateRow === 'function'){
        report.addOrUpdateRow('row_state_manager_tests', results.failed === 0 ? 'OK' : 'FAIL', summary);
      } else {
        console.log('[KISS SBI Tests] RowStateManager:', summary);
      }
    } catch(_){}
  }

  // Wait for Self Tests page helper
  document.addEventListener('kiss-sbi-self-tests-ready', function(ev){
    try { runTests(ev && ev.detail); } catch(e){ console.error('[KISS SBI Tests] error', e); }
  });

})();

