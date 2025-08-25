(function(global){
  'use strict';

  const STATES = Object.freeze({
    UNKNOWN: 'UNKNOWN',
    CHECKING: 'CHECKING',
    NOT_PLUGIN: 'NOT_PLUGIN',
    INSTALLABLE: 'INSTALLABLE',
    INSTALLING: 'INSTALLING',
    DOWNLOADED_INACTIVE: 'DOWNLOADED_INACTIVE',
    ACTIVATING: 'ACTIVATING',
    DOWNLOADED_ACTIVE: 'DOWNLOADED_ACTIVE',
    ERROR: 'ERROR'
  });

  const EVENTS = Object.freeze({
    INIT: 'INIT',
    CHECK_START: 'CHECK_START',
    CHECK_SUCCESS_PLUGIN: 'CHECK_SUCCESS_PLUGIN',
    CHECK_SUCCESS_NOT_PLUGIN: 'CHECK_SUCCESS_NOT_PLUGIN',
    CHECK_FAIL: 'CHECK_FAIL',
    STATUS_REFRESH: 'STATUS_REFRESH',
    INSTALL_START: 'INSTALL_START',
    INSTALL_SUCCESS: 'INSTALL_SUCCESS',
    INSTALL_FAIL: 'INSTALL_FAIL',
    ACTIVATE_START: 'ACTIVATE_START',
    ACTIVATE_SUCCESS: 'ACTIVATE_SUCCESS',
    ACTIVATE_FAIL: 'ACTIVATE_FAIL',
    CLEAR_ERROR: 'CLEAR_ERROR'
  });

  function deriveStateFromPayload(payload){
    try {
      const isInstalled = !!payload.isInstalled;
      const isActive = payload.isActive === true;
      const isPlugin = payload.isPlugin === true || isInstalled;
      if (isInstalled && isActive) return STATES.DOWNLOADED_ACTIVE;
      if (isInstalled && !isActive) return STATES.DOWNLOADED_INACTIVE;
      if (isPlugin === true) return STATES.INSTALLABLE;
      if (payload.isPlugin === false) return STATES.NOT_PLUGIN;
      return STATES.UNKNOWN;
    } catch(_) { return STATES.UNKNOWN; }
  }

  class RepoFSM {
    constructor(initialState = STATES.UNKNOWN, ctx = {}){
      this.state = initialState;
      this.ctx = Object.assign({ pluginFile: null, settingsUrl: null, error: null }, ctx);
    }

    handle(event, data){
      switch(event){
        case EVENTS.INIT:
          this.state = STATES.UNKNOWN; this.ctx.error = null; return this;
        case EVENTS.CHECK_START:
          this.state = STATES.CHECKING; this.ctx.error = null; return this;
        case EVENTS.CHECK_SUCCESS_PLUGIN: {
          this.state = STATES.INSTALLABLE; this.ctx.error = null; return this;
        }
        case EVENTS.CHECK_SUCCESS_NOT_PLUGIN:
          this.state = STATES.NOT_PLUGIN; this.ctx.error = null; return this;
        case EVENTS.CHECK_FAIL:
          this.state = STATES.ERROR; this.ctx.error = (data && data.error) || 'check_failed'; return this;
        case EVENTS.STATUS_REFRESH: {
          const next = deriveStateFromPayload(data||{});
          this.state = next;
          if (data && 'pluginFile' in data) this.ctx.pluginFile = data.pluginFile;
          if (data && 'settingsUrl' in data) this.ctx.settingsUrl = data.settingsUrl;
          if (data && 'error' in data) this.ctx.error = data.error || null; else this.ctx.error = null;
          // Maintain an isActive hint in ctx for mapping
          if (data && 'isActive' in data) this.ctx.isActive = !!data.isActive; else delete this.ctx.isActive;
          return this;
        }
        case EVENTS.INSTALL_START:
          this.state = STATES.INSTALLING; this.ctx.error = null; return this;
        case EVENTS.INSTALL_SUCCESS: {
          const activated = !!(data && (data.activated === true || data.isActive === true));
          this.ctx.pluginFile = (data && (data.plugin_file || data.pluginFile)) || this.ctx.pluginFile || null;
          this.state = activated ? STATES.DOWNLOADED_ACTIVE : STATES.DOWNLOADED_INACTIVE;
          this.ctx.error = null; return this;
        }
        case EVENTS.INSTALL_FAIL:
          this.state = STATES.ERROR; this.ctx.error = (data && (data.error || data.message)) || 'install_failed'; return this;
        case EVENTS.ACTIVATE_START:
          this.state = STATES.ACTIVATING; this.ctx.error = null; return this;
        case EVENTS.ACTIVATE_SUCCESS:
          this.state = STATES.DOWNLOADED_ACTIVE; this.ctx.error = null; return this;
        case EVENTS.ACTIVATE_FAIL:
          this.state = STATES.ERROR; this.ctx.error = (data && (data.error || data.message)) || 'activate_failed'; return this;
        case EVENTS.CLEAR_ERROR:
          this.ctx.error = null; this.state = STATES.UNKNOWN; return this;
      }
      return this;
    }

    snapshot(repoName){
      // Map FSM to legacy state shape used by admin.js renderer
      const installed = (this.state === STATES.DOWNLOADED_ACTIVE || this.state === STATES.DOWNLOADED_INACTIVE);
      const active = (this.state === STATES.DOWNLOADED_ACTIVE);
      const isPlugin = (this.state === STATES.INSTALLABLE) || installed;
      const checking = this.state === STATES.CHECKING;
      const installing = this.state === STATES.INSTALLING;
      const error = this.state === STATES.ERROR ? (this.ctx.error || 'error') : null;
      return {
        repoName: repoName,
        isPlugin: isPlugin,
        isInstalled: installed,
        isActive: active,
        pluginFile: this.ctx.pluginFile || null,
        settingsUrl: this.ctx.settingsUrl || null,
        checking: checking,
        installing: installing,
        error: error,
        fsmState: this.state
      };
    }
  }

  function createMachine(initialState, ctx){
    return new RepoFSM(initialState, ctx);
  }

  global.KissSbiFSM = { STATES, EVENTS, createMachine };
})(window);

