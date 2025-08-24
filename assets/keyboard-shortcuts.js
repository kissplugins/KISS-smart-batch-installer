(function(){
  // KISS Smart Batch Installer — Global keyboard shortcuts
  // Cmd/Ctrl+Shift+P to open PQS on Plugins page, or route to SBI page elsewhere
  if (window.kissSbiKeyboardActive) {
    try { if (window.kissSbiShortcuts && window.kissSbiShortcuts.debug) console.log('KISS SBI: Keyboard shortcuts already active; skipping re-init'); } catch(_) {}
    return;
  }
  window.kissSbiKeyboardActive = true;

  function isModifierCombo(e){
    // Match Cmd (meta) or Ctrl, plus Shift, with the letter P (case-insensitive)
    var key = (e.key || '').toString();
    return (e && (e.metaKey || e.ctrlKey) && e.shiftKey && key.toLowerCase() === 'p');
  }

  function isTypingContext(e){
    try {
      var t = e.target;
      if (!t) return false;
      var tag = (t.tagName || '').toUpperCase();
      if (t.isContentEditable) return true;
      if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return true;
      // Inputs within Gutenberg/code editors etc.
      if (t.closest && (t.closest('.CodeMirror, .ace_editor, .editor-styles-wrapper'))) return true;
    } catch(_) {}
    return false;
  }

  function onKeyDown(e){
    try {
      if (!isModifierCombo(e)) return;
      if (isTypingContext(e)) return; // don't hijack when typing

      var href = String(window.location.href || '');
      var onPluginsList = href.indexOf('plugins.php') !== -1 && href.indexOf('page=') === -1;
      var onSbiPage = href.indexOf('page=kiss-smart-batch-installer') !== -1;
      var onKissPage = href.indexOf('page=kiss-') !== -1; // any KISS admin screen

      // If we're on the Plugins list and PQS is active, let PQS handle the pop-up
      if (onPluginsList) {
        if (window.pqsKeyboardHandlerActive) {
          // Do not preventDefault; allow PQS listener to receive the event
          if (window.kissSbiShortcuts && window.kissSbiShortcuts.debug) console.log('KISS SBI: Deferring to PQS keyboard handler on Plugins page');
          return;
        }
        // If PQS exposes an opener API, try that gracefully; otherwise fall through
        try {
          if (window.PQS && typeof window.PQS.open === 'function') {
            e.preventDefault();
            window.PQS.open();
            if (window.kissSbiShortcuts && window.kissSbiShortcuts.debug) console.log('KISS SBI: Invoked PQS.open()');
            return;
          }
        } catch(_) {}
        // No PQS JS detected on Plugins page; do not redirect (avoid bounce)
        e.preventDefault();
        if (window.kissSbiShortcuts && window.kissSbiShortcuts.debug) console.warn('KISS SBI: PQS not detected on Plugins page; not redirecting');
        return;
      }

      // If we're on the SBI page, open SBI Quick Search overlay (do not redirect)
      if (onSbiPage) {
        e.preventDefault();
        try {
          if (window.KissSbiQuickSearch && typeof window.KissSbiQuickSearch.open === 'function') {
            window.KissSbiQuickSearch.open();
            if (window.kissSbiShortcuts && window.kissSbiShortcuts.debug) console.log('KISS SBI: Opened SBI Quick Search overlay');
            return;
          }
        } catch(_) {}
        if (window.kissSbiShortcuts && window.kissSbiShortcuts.debug) console.warn('KISS SBI: Quick Search not available on SBI page');
        return;

      }

      // Navigate to Smart Batch Installer only when not already on a KISS screen
      if (onKissPage) {
        // We're on a KISS admin screen but not Plugins; avoid bouncing
        e.preventDefault();
        if (window.kissSbiShortcuts && window.kissSbiShortcuts.debug) console.log('KISS SBI: On KISS screen without PQS; doing nothing');
        return;
      }
      var dest = (window.kissSbiShortcuts && window.kissSbiShortcuts.installerUrl) ? String(window.kissSbiShortcuts.installerUrl) : '/wp-admin/plugins.php?page=kiss-smart-batch-installer';
      e.preventDefault();
      if (window.kissSbiShortcuts && window.kissSbiShortcuts.debug) console.log('KISS SBI: Keyboard shortcut navigating to SBI', dest);
      window.location.href = dest;
    } catch(err) {
      try { if (window.kissSbiShortcuts && window.kissSbiShortcuts.debug) console.warn('KISS SBI: Keyboard handler error', err); } catch(_) {}
    }
  }

  function init(){
    try {
      document.addEventListener('keydown', onKeyDown, false);
      maybeTriggerPQSOnLoad();
      if (window.kissSbiShortcuts && window.kissSbiShortcuts.debug) console.log('KISS SBI: Keyboard shortcuts initialized');
    } catch(err) {
      try { if (window.kissSbiShortcuts && window.kissSbiShortcuts.debug) console.warn('KISS SBI: Failed to initialize keyboard shortcuts', err); } catch(_) {}
    }
  }

  if (document.readyState === 'complete' || document.readyState === 'interactive') {
    init();
  } else {
    document.addEventListener('DOMContentLoaded', init);
  }
})();


  function maybeTriggerPQSOnLoad(){
    try {
      var flag = sessionStorage.getItem('kissSbiOpenPqsOnce');
      if (!flag) return;
      sessionStorage.removeItem('kissSbiOpenPqsOnce');
      // Only auto-open on the Plugins list to avoid surprises elsewhere
      var href = String(window.location.href||'');
      var onPluginsList = href.indexOf('plugins.php') !== -1 && href.indexOf('page=') === -1;
      if (!onPluginsList) return;

      var attempts = 0;
      var maxAttempts = 20; // ~2s total
      var timer = setInterval(function(){
        attempts++;
        try {
          // Preferred: direct API
          if (window.PQS && typeof window.PQS.open === 'function') {
            window.PQS.open();
            clearInterval(timer);
            if (window.kissSbiShortcuts && window.kissSbiShortcuts.debug) console.log('KISS SBI: Auto-opened PQS via API after redirect');
            return;
          }
          // Fallback: if PQS keyboard handler is active, synthesize the keystroke
          if (window.pqsKeyboardHandlerActive) {
            var ev = new KeyboardEvent('keydown', { key: 'P', shiftKey: true, metaKey: true, ctrlKey: false, bubbles: true });
            document.dispatchEvent(ev);
            clearInterval(timer);
            if (window.kissSbiShortcuts && window.kissSbiShortcuts.debug) console.log('KISS SBI: Auto-opened PQS via synthesized keystroke');
            return;
          }
        } catch(_) {}
        if (attempts >= maxAttempts) {
          clearInterval(timer);
          if (window.kissSbiShortcuts && window.kissSbiShortcuts.debug) console.warn('KISS SBI: Timed out waiting for PQS to initialize on Plugins page');
        }
      }, 100);
    } catch(_) {}
  }
