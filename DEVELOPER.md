# KISS Smart Batch Installer (SBI) — Developer Guide

This document explains how SBI works under the hood so other plugins (notably Plugin Quick Search — PQS) can integrate with the SBI admin page to provide fast keyboard search, highlight, and cache-assisted status hints.

If you are building an integration, the most useful sections are:
- DOM structure and selectors (where to hook)
- Initialization lifecycle (what happens, when)
- JavaScript API and state model (RowStateManager)
- AJAX endpoints and response contracts
- PQS integration model (read-only cache usage + events)
- Keyboard search + highlight recommendations


## High-level Overview

SBI lets an admin view the org’s most recently updated GitHub repositories and install/activate WordPress plugins directly from those repos.

- PHP renders a table of repositories. Each row is keyed by the repo name via data-repo.
- JavaScript coordinates detection and actions (check status, scan plugin headers, install, activate) and keeps the UI in sync through a small state manager.
- PQS integration is read-only: SBI consumes PQS cache (localStorage and/or PQS helper functions) to pre-mark rows that are already installed and to enable quick UX.


## Server-side architecture

Namespaces live under KissSmartBatchInstaller\.

- src/Admin/AdminInterface.php
  - Adds the SBI admin pages (main list, Settings, Self Tests)
  - Enqueues assets: assets/admin.js (main UI) and assets/pqs-integration.js (PQS glue)
  - Localizes `kissSbiAjax` with ajaxUrl, nonce, flags, strings
  - Renders the repository table (see DOM section)

- src/Core/GitHubScraper.php
  - Scrapes GitHub org repos and caches the result (transients)
  - AJAX: `kiss_sbi_refresh_repos`, `kiss_sbi_clear_cache`, `kiss_sbi_scan_plugins`
    - `kiss_sbi_scan_plugins` inspects the repo for WP plugin headers (no install)

- src/Core/PluginInstaller.php
  - Installs/activates plugins using WordPress core Plugin_Upgrader
  - AJAX: `kiss_sbi_install_plugin`, `kiss_sbi_batch_install`, `kiss_sbi_activate_plugin`, `kiss_sbi_check_installed`

- src/Core/SelfUpdater.php
  - Updates SBI itself from main.zip
  - AJAX: `kiss_sbi_check_self_update`, `kiss_sbi_run_self_update`


## DOM structure (main list)

AdminInterface renders a standard `.wp-list-table` with rows keyed by repo name.

- Row
  - `<tr data-repo="{RepoName}">`
- Checkbox
  - `.kiss-sbi-repo-checkbox` (batch selection)
  - `#kiss-sbi-select-all` (select all)
- Status cell
  - `.kiss-sbi-plugin-status`
  - SBI adds legacy flags for compatibility: `.is-plugin` and `.is-installed`
- Action cell (last column)
  - Buttons rendered by JS depending on state:
    - `.kiss-sbi-check-plugin`
    - `.kiss-sbi-check-installed`
    - `.kiss-sbi-install-single`
    - `.kiss-sbi-activate-plugin`
- Batch install
  - `#kiss-sbi-batch-install`
- Optional indicator (header area)
  - `#kiss-sbi-pqs-indicator` (text updates like “PQS: Using Cache ✓”)

Tip: To detect the SBI page, check for `page=kiss-smart-batch-installer` in the URL or presence of `.kiss-sbi-plugin-status` in the DOM.


## Client-side architecture

- assets/admin.js
  - Small debug helper: `dbg(...)`
  - RowStateManager: a tiny state store + renderer for each row
    - `updateRow(repoName, partialState)` merges state and re-renders
    - State shape: `{ repoName, isPlugin, isInstalled, isActive, pluginFile, settingsUrl, checking, installing, error }`
    - Rendering keeps legacy CSS hooks on the status cell: `.is-plugin`, `.is-installed`
  - Initialization lifecycle (`initializeUnifiedRows`):
    1) Stage 1 — Seed all rows with `checking: true`
    2) Stage 2 — If PQS is present or a PQS cache is found in localStorage, mark installed rows optimistically
    3) Stage 3 — Serialized server checks (`kiss_sbi_check_installed`) per row
    4) Stage 4 — Queue “plugin header” scans (`kiss_sbi_scan_plugins`) for unknown rows
  - Queue for “Check” actions to throttle network load
  - Single and batch install flows (`kiss_sbi_install_plugin`), plus activate

- assets/pqs-integration.js
  - Detects PQS presence (functions like `pqsCacheStatus`, `pqsRebuildCache`) or falls back to localStorage (`pqs_plugin_cache`)
  - Listens for `document` event `pqs-cache-rebuilt` and rescans cache
  - Updates SBI rows with installed hints and header indicator


## AJAX endpoints and contracts

All endpoints require the localized `kissSbiAjax.nonce` and standard WP capabilities.

- POST `action=kiss_sbi_check_installed&repo_name=RepoName`
  - Response: `{ success: true, data: { installed: bool, plugin_file: string, active: bool, plugin_data: object } | false, installed: bool }`
  - Note: The PHP always `wp_send_json_success(...)`; check `installed` field inside `data` or at top level.

- POST `action=kiss_sbi_scan_plugins&repo_name=RepoName`
  - Detects if the repo looks like a WP plugin by reading headers
  - Response: `{ success: true, data: { is_plugin: bool, plugin_data: {... or message/code on error} } }`

- POST `action=kiss_sbi_install_plugin&repo_name=RepoName&activate=0|1`
  - Installs from `https://github.com/{org}/{repo}/archive/refs/heads/main.zip` via Plugin_Upgrader
  - Response on success: `{ success: true, data: { success: true, plugin_dir, plugin_file, activated: bool, logs: [] } }`
  - Response on error: `{ success: false, data: "message (may include Upgrader logs)" }`

- POST `action=kiss_sbi_activate_plugin&plugin_file=dir/main.php`
  - Response: `{ success: true, data: { activated: true, plugin_file } }`

- POST `action=kiss_sbi_refresh_repos`
  - Clears the GitHub repo list cache and signals the UI to reload

- POST `action=kiss_sbi_clear_cache`
  - Clears repo cache and related detection transients

- Self update (SBI only): `kiss_sbi_check_self_update`, `kiss_sbi_run_self_update`


## RowStateManager rendering rules (what the user sees)

- If `state.checking === true`
  - Status shows “Checking…”
- If `state.isInstalled === true`
  - Status: “✓ Installed (Active)” or “✓ Installed (Inactive)”
  - Actions: if inactive and `pluginFile`, show “Activate →”; else show “Already Activated”
- If `state.isInstalled !== true` and `state.isPlugin === true`
  - Actions: show “Install” button
- If unknown
  - Actions: show “Check Status”/“Check” buttons

Remember: The status cell receives `.is-installed` and `.is-plugin` classes for compatibility.


## PQS integration model (read-only)

SBI expects PQS (if installed) to provide any of the following at runtime:
- Optional functions: `window.pqsCacheStatus()` and `window.pqsRebuildCache()`
- Local storage key: `localStorage['pqs_plugin_cache']` — JSON array where each entry can include:
  - `slug` (preferred), `name` or `nameLower`, `isActive` (bool), `settingsUrl` (string)
- Document event: `pqs-cache-rebuilt` with `{ detail: { pluginCount: number } }`

How SBI consumes PQS (in assets/pqs-integration.js):
- If `pqsCacheStatus()` returns `fresh` or `stale`, read from localStorage and map entries to SBI rows by variants:
  - slug, slug with non-alnum replaced by `-`, slug with `-`/`_` stripped
  - repo name lowercased, plus the same variants
- For matched repos, SBI updates rows as “Installed (Active/Inactive)” and disables their batch checkboxes. If `settingsUrl` exists, SBI adds a Settings button.

Recommended integration surface for PQS going forward:
- Call the SBI state manager rather than editing the DOM directly:
  - `window.RowStateManager.updateRow(repoName, { isInstalled: true, isActive: <bool>, isPlugin: true, settingsUrl: <url> })`
- Keep emitting the `pqs-cache-rebuilt` event. SBI already listens and rescans.


## Keyboard search + highlight on the SBI page

Goal: Make the same hotkey that opens PQS’s quick finder on the Plugins screen also work on the SBI page and highlight the matching repo row.

Suggested approach:
1) Detect you are on the SBI page
   - URL contains `page=kiss-smart-batch-installer` or `document.querySelector('.kiss-sbi-plugin-status')`
2) When user confirms a match (Enter), scroll + highlight:
   - Find row by exact repo name: `tr[data-repo="{RepoName}"]`
   - Or map plugin slug back to repo name via the variant rules above
3) Visual feedback (non-invasive):
   - Add a CSS class (e.g., `.kiss-sbi-highlight`) to the row and remove after a timeout
   - Focus the row’s checkbox or primary action button to afford keyboard flow

Example snippet (PQS side):
```js
function focusSbiRowBySlugOrName(key){
  const variants = [key, key.toLowerCase(), key.toLowerCase().replace(/[^a-z0-9]/g,'-'), key.toLowerCase().replace(/[-_]/g,'')];
  const rows = Array.from(document.querySelectorAll('.wp-list-table tbody tr[data-repo]'));
  function repoVariants(repo){
    const lower = repo.toLowerCase();
    return [lower, lower.replace(/[^a-z0-9]/g,'-'), lower.replace(/[-_]/g,'')];
  }
  for (const tr of rows){
    const repo = tr.getAttribute('data-repo') || '';
    const rvars = repoVariants(repo);
    if (variants.some(v => rvars.includes(v))){
      tr.classList.add('kiss-sbi-highlight');
      tr.scrollIntoView({behavior:'smooth', block:'center'});
      setTimeout(() => tr.classList.remove('kiss-sbi-highlight'), 1600);
      const cb = tr.querySelector('.kiss-sbi-repo-checkbox');
      if (cb) cb.focus();
      return true;
    }
  }
  return false;
}
```

Optional: If you prefer an API, you can call the state manager and SBI will render, then you can focus the action button:
```js
if (window.RowStateManager){
  RowStateManager.updateRow('My-Plugin', { checking:false });
  focusSbiRowBySlugOrName('my-plugin');
}
```


## Performance notes

- SBI serializes server-side `check installed` API calls during init to avoid bursts
- “Check plugin” scans are queued with a small delay (250ms) between requests
- PQS cache use is entirely client-side; reading from localStorage is fast and safe


## Error handling expectations

- All SBI AJAX endpoints use `wp_send_json_*` and include meaningful messages
- Install endpoint returns verbose Upgrader logs (concatenated) on error; we surface that in the UI and print the raw logs to the console for debugging


## Compatibility notes

- SBI uses WordPress core APIs (Plugin_Upgrader, WP_Filesystem, activate_plugin, get_plugins)
- For DOM compatibility, SBI preserves `.is-plugin` and `.is-installed` classes on the status cell even when using RowStateManager
- PQS doesn’t need to be enqueued on the SBI page; SBI can still detect and use `localStorage['pqs_plugin_cache']`


## Where things are

- PHP
  - AdminInterface: `src/Admin/AdminInterface.php`
  - PluginInstaller: `src/Core/PluginInstaller.php`
  - GitHubScraper: `src/Core/GitHubScraper.php`
  - SelfUpdater: `src/Core/SelfUpdater.php`
- JavaScript
  - Main UI: `assets/admin.js`
  - PQS glue: `assets/pqs-integration.js`
  - Styles: `assets/admin.css`


## Questions?

Open an issue with a console log excerpt, the SBI page URL (including `page=`), and a short description of what your integration attempted to do (scan cache, highlight row, etc.).

