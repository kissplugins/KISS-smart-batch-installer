(function(){
  'use strict';
  // KISS SBI — In-page Quick Search overlay (opened from Cmd/Ctrl+Shift+P on SBI page)
  // Data source: the rendered SBI table rows (server-provided GitHub repos)

  if (window.KissSbiQuickSearch) return;

  function $(sel, ctx){ return (ctx||document).querySelector(sel); }
  function $all(sel, ctx){ return Array.from((ctx||document).querySelectorAll(sel)); }

  // Normalize: lower, remove non-alnum and hyphens/underscores; also replace hyphens with spaces for matching
  function norm(s){
    const t = (s||'').toString().toLowerCase();
    return {
      raw: t,
      nosym: t.replace(/[^a-z0-9]+/g,' '),
      compact: t.replace(/[-_\s]+/g,'')
    };
  }

  function buildDataset(){
    const rows = $all('.wp-list-table tbody tr[data-repo]');
    return rows.map(function(tr){
      const name = (tr.getAttribute('data-repo')||'').trim();
      const descCell = tr.querySelector('td:nth-child(3)');
      const desc = descCell ? descCell.textContent.trim() : '';
      const langCell = tr.querySelector('td:nth-child(4)');
      const lang = langCell ? langCell.textContent.trim() : '';
      const n = norm(name);
      const d = norm(desc);
      return {
        key: name,
        name: name,
        desc: desc,
        lang: lang,
        tokens: [n.raw, n.nosym, n.compact, d.raw, d.nosym].join(' ')
      };
    });
  }

  function scoreItem(item, q){
    // Simple scoring: startsWith (name) > includes (name) > includes (desc)
    const n = item.name.toLowerCase();
    const ql = q.toLowerCase();
    if (n.startsWith(ql)) return 100 - n.length; // prefer shorter startsWith
    if (n.indexOf(ql) !== -1) return 80 - (n.indexOf(ql));
    const t = item.tokens;
    if (t.indexOf(ql) !== -1) return 60 - (t.indexOf(ql));
    // compact match (ignore hyphens/underscores/spaces)
    const nc = n.replace(/[-_\s]+/g,'');
    const qc = ql.replace(/[-_\s]+/g,'');
    if (nc.indexOf(qc) !== -1) return 70 - (nc.indexOf(qc));
    return -1;
  }

  function createOverlay(){
    const el = document.createElement('div');
    el.id = 'kiss-sbi-quick-search';
    el.innerHTML = (
      '<div class="kiss-sbi-qs-backdrop" role="presentation"></div>'+
      '<div class="kiss-sbi-qs-modal" role="dialog" aria-modal="true" aria-labelledby="kiss-sbi-qs-title">'+
        '<div class="kiss-sbi-qs-header">'+
          '<span id="kiss-sbi-qs-title">Search Repositories</span>'+
          '<button type="button" class="kiss-sbi-qs-close" aria-label="Close">×</button>'+
        '</div>'+
        '<div class="kiss-sbi-qs-input-row">'+
          '<span class="dashicons dashicons-search" aria-hidden="true"></span>'+
          '<input type="text" id="kiss-sbi-qs-input" placeholder="Type to search repos…" autocomplete="off" />'+
        '</div>'+
        '<div class="kiss-sbi-qs-results" id="kiss-sbi-qs-results" role="listbox" aria-label="Matches"></div>'+
        '<div class="kiss-sbi-qs-hint">Enter: highlight row • Esc: close • ↑/↓: navigate</div>'+
      '</div>'
    );
    document.body.appendChild(el);
    return el;
  }

  function ensureStyles(){
    if (document.getElementById('kiss-sbi-qs-styles')) return;
    const s = document.createElement('style');
    s.id = 'kiss-sbi-qs-styles';
    s.textContent = (
      '.kiss-sbi-qs-backdrop{position:fixed;inset:0;background:rgba(0,0,0,0.35);z-index:100000;}'+
      '.kiss-sbi-qs-modal{position:fixed;top:12%;left:50%;transform:translateX(-50%);width:min(720px,92vw);'+
        'background:#fff;border-radius:8px;box-shadow:0 10px 30px rgba(0,0,0,.3);z-index:100001;overflow:hidden;font:14px/1.4 -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,Helvetica,Arial,sans-serif;}'+
      '.kiss-sbi-qs-header{display:flex;align-items:center;justify-content:space-between;padding:10px 12px;border-bottom:1px solid #e2e4e7;background:#f6f7f7;font-weight:600;}'+
      '.kiss-sbi-qs-close{background:none;border:0;font-size:22px;line-height:1;cursor:pointer;color:#555;}'+
      '.kiss-sbi-qs-input-row{display:flex;gap:8px;align-items:center;padding:12px;border-bottom:1px solid #f0f0f1;}'+
      '.kiss-sbi-qs-input-row input{flex:1;padding:10px 12px;border:1px solid #ccd0d4;border-radius:4px;font-size:14px;}'+
      '.kiss-sbi-qs-results{max-height:420px;overflow:auto;}'+
      '.kiss-sbi-qs-item{display:flex;gap:10px;align-items:center;padding:10px 12px;border-bottom:1px solid #f0f0f1;cursor:pointer;}'+
      '.kiss-sbi-qs-item:hover,.kiss-sbi-qs-item.is-active{background:#f6f7f7;}'+
      '.kiss-sbi-qs-item .name{font-weight:600;}'+
      '.kiss-sbi-qs-item .desc{color:#646970;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}'+
      '.kiss-sbi-qs-hint{padding:8px 12px;color:#646970;background:#fcfcfd;border-top:1px solid #f0f0f1;font-size:12px;}'
    );
    document.head.appendChild(s);
  }

  let state = {
    open: false,
    dataset: [],
    index: -1
  };

  function renderList(items){
    const list = $('#kiss-sbi-qs-results');
    if (!list) return;
    list.innerHTML = '';
    items.slice(0, 20).forEach(function(item, i){
      const div = document.createElement('div');
      div.className = 'kiss-sbi-qs-item'+(i===0?' is-active':'');
      div.setAttribute('role','option');
      div.setAttribute('data-key', item.key);
      div.innerHTML = '<div class="name">'+escapeHtml(item.name)+'</div>'+
                      '<div class="desc">'+escapeHtml(item.desc||'')+'</div>';
      div.addEventListener('mouseover', function(){ selectIndex(i); });
      div.addEventListener('click', function(){ choose(i); });
      list.appendChild(div);
    });
    state.index = items.length ? 0 : -1;
  }

  function selectIndex(i){
    const list = $('#kiss-sbi-qs-results');
    const items = $all('.kiss-sbi-qs-item', list);
    if (!items.length) return;
    state.index = Math.max(0, Math.min(i, items.length-1));
    items.forEach((el, idx)=> el.classList.toggle('is-active', idx===state.index));
    const active = items[state.index];
    if (active) active.scrollIntoView({block:'nearest'});
  }

  function choose(i){
    const list = $('#kiss-sbi-qs-results');
    const items = $all('.kiss-sbi-qs-item', list);
    if (!items.length) return;
    const idx = (typeof i === 'number') ? i : state.index;
    if (idx < 0 || idx >= items.length) return;
    const key = items[idx].getAttribute('data-key');
    try {
      // Prefer red highlight per spec
      if (typeof window.kissSbiFocusRowRed === 'function') {
        window.kissSbiFocusRowRed(key);
      } else if (typeof window.kissSbiFocusRowByKey === 'function') {
        window.kissSbiFocusRowByKey(key);
      }
    } catch(_){}
    close();
  }

  function escapeHtml(s){
    return (s||'').toString()
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
  }

  function bindEvents(root){
    const input = $('#kiss-sbi-qs-input', root);
    const closeBtn = $('.kiss-sbi-qs-close', root);
    const backdrop = $('.kiss-sbi-qs-backdrop', root);

    function onKey(e){
      if (!state.open) return;
      switch(e.key){
        case 'Escape': e.preventDefault(); close(); break;
        case 'ArrowDown': e.preventDefault(); selectIndex(state.index+1); break;
        case 'ArrowUp': e.preventDefault(); selectIndex(state.index-1); break;
        case 'Enter': e.preventDefault(); choose(state.index); break;
        default: break;
      }
    }

    input.addEventListener('input', function(){
      const q = this.value.trim();
      if (!q){ renderList(state.dataset.slice(0,20)); return; }
      const ranked = state.dataset.map(it => ({it, s: scoreItem(it, q)}))
        .filter(x => x.s >= 0)
        .sort((a,b)=> b.s - a.s)
        .map(x => x.it);
      renderList(ranked);
    });

    closeBtn.addEventListener('click', close);
    backdrop.addEventListener('click', close);
    document.addEventListener('keydown', onKey);

    // Focus trap light
    setTimeout(function(){ input.focus(); input.select(); }, 10);
  }

  function open(){
    try {
      if (state.open) return;
      ensureStyles();
      const root = createOverlay();
      state.dataset = buildDataset();
      state.open = true;
      renderList(state.dataset);
      bindEvents(root);
    } catch(e) { try { console.warn('KISS SBI: Quick Search failed to open', e); } catch(_){} }
  }

  function close(){
    const el = document.getElementById('kiss-sbi-quick-search');
    if (el && el.parentNode) el.parentNode.removeChild(el);
    state.open = false;
  }

  window.KissSbiQuickSearch = { open: open, close: close };
})();

