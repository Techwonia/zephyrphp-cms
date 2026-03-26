/*! ZephyrUI v1.0 — Core interactions for ZephyrPHP CMS */

(function () {
  'use strict';

  /* ============================================================
     1. SIDEBAR PERSISTENCE (prevents flicker on page change)
     ============================================================ */
  var SIDEBAR_KEY = 'zui-panel';
  var SIDEBAR_SECTION_KEY = 'zui-panel-section';

  function getSidebarState() {
    return localStorage.getItem(SIDEBAR_KEY) || 'closed';
  }

  function getSidebarSection() {
    return localStorage.getItem(SIDEBAR_SECTION_KEY) || '';
  }

  function setSidebarState(state, section) {
    localStorage.setItem(SIDEBAR_KEY, state);
    if (section !== undefined) {
      localStorage.setItem(SIDEBAR_SECTION_KEY, section || '');
      document.documentElement.setAttribute('data-panel-section', section || '');
    }
    document.documentElement.setAttribute('data-panel', state);
  }

  /* ============================================================
     2. TOAST NOTIFICATION SYSTEM
     ============================================================ */
  var ZuiToast = {
    container: null,

    init: function () {
      this.container = document.querySelector('.zui-toast-container');
      if (!this.container) {
        this.container = document.createElement('div');
        this.container.className = 'zui-toast-container';
        document.body.appendChild(this.container);
      }
    },

    show: function (message, type, duration) {
      if (!this.container) this.init();
      type = type || 'info';
      duration = duration || 5000;

      var icons = {
        success: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
        error: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
        warning: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        info: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>'
      };

      var toast = document.createElement('div');
      toast.className = 'zui-toast ' + type;
      toast.innerHTML =
        '<span class="zui-toast-icon">' + (icons[type] || icons.info) + '</span>' +
        '<span class="zui-toast-body">' + message + '</span>' +
        '<button class="zui-toast-close" aria-label="Close">' +
        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>' +
        '</button>';

      this.container.appendChild(toast);

      var closeBtn = toast.querySelector('.zui-toast-close');
      var self = this;
      closeBtn.addEventListener('click', function () { self.dismiss(toast); });

      if (duration > 0) {
        setTimeout(function () { self.dismiss(toast); }, duration);
      }

      return toast;
    },

    dismiss: function (toast) {
      if (!toast || toast.classList.contains('removing')) return;
      toast.classList.add('removing');
      setTimeout(function () { toast.remove(); }, 250);
    }
  };

  /* ============================================================
     3. SKELETON LOADERS
     ============================================================ */
  function revealContent() {
    // Hide skeletons, show real content
    var skeletons = document.querySelectorAll('[data-skeleton]');
    skeletons.forEach(function (el) { el.remove(); });

    var ready = document.querySelectorAll('[data-content-ready]');
    ready.forEach(function (el) { el.style.display = ''; });
  }

  /* ============================================================
     4. DROPDOWN SYSTEM
     ============================================================ */
  function initDropdowns() {
    document.addEventListener('click', function (e) {
      var trigger = e.target.closest('[data-dropdown]');

      // Close all open dropdowns first
      if (!trigger) {
        document.querySelectorAll('.dropdown-menu.open').forEach(function (m) { m.classList.remove('open'); });
        return;
      }

      e.preventDefault();
      e.stopPropagation();
      var targetId = trigger.getAttribute('data-dropdown');
      var menu = document.getElementById(targetId);
      if (!menu) return;

      var wasOpen = menu.classList.contains('open');

      // Close all
      document.querySelectorAll('.dropdown-menu.open').forEach(function (m) { m.classList.remove('open'); });

      if (!wasOpen) {
        menu.classList.add('open');
      }
    });
  }

  /* ============================================================
     5. TAB SYSTEM
     ============================================================ */
  function initTabs() {
    document.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-tab]');
      if (!btn) return;

      var group = btn.closest('[data-tab-group]');
      if (!group) return;

      var tabId = btn.getAttribute('data-tab');

      // Deactivate all tabs in group
      group.querySelectorAll('[data-tab]').forEach(function (t) { t.classList.remove('active'); });
      btn.classList.add('active');

      // Show target panel
      var panels = group.querySelectorAll('[data-tab-panel]');
      panels.forEach(function (p) {
        p.classList.toggle('active', p.getAttribute('data-tab-panel') === tabId);
      });
    });
  }

  /* ============================================================
     6. PANEL / SIDEBAR TOGGLE
     ============================================================ */
  function initSidebar() {
    var panel = document.getElementById('sidebarPanel');
    var activityBar = document.getElementById('activityBar');
    if (!panel || !activityBar) return;

    // Restore state — data-panel already set by inline <head> script, sidebar already visible via CSS
    var savedState = getSidebarState();
    var savedSection = document.documentElement.getAttribute('data-panel-section') || getSidebarSection();

    if (savedState === 'open' && savedSection) {
      // Nav list + header already visible via CSS [data-panel-section] rules
      // Just mark the activity bar item active
      var abItem = activityBar.querySelector('[data-section="' + savedSection + '"]');
      if (abItem) abItem.classList.add('active');
    }

    // Activity bar clicks
    activityBar.addEventListener('click', function (e) {
      var item = e.target.closest('[data-section]');
      if (!item) return;

      e.preventDefault();
      var section = item.getAttribute('data-section');

      // If clicking the same section while open, close panel
      var isOpen = document.documentElement.getAttribute('data-panel') === 'open';
      if (isOpen && savedSection === section) {
        setSidebarState('closed', '');
        activityBar.querySelectorAll('.ab-item').forEach(function (i) { i.classList.remove('active'); });
        panel.querySelectorAll('.sidebar-nav-list').forEach(function (l) { l.classList.remove('active'); });
        savedSection = '';
        return;
      }

      // Open panel with this section
      setSidebarState('open', section);
      savedSection = section;

      // Update active states
      activityBar.querySelectorAll('.ab-item').forEach(function (i) { i.classList.remove('active'); });
      item.classList.add('active');

      // Show corresponding nav list
      panel.querySelectorAll('.sidebar-nav-list').forEach(function (l) { l.classList.remove('active'); });
      var navList = panel.querySelector('[data-nav="' + section + '"]');
      if (navList) navList.classList.add('active');

      // Update title
      var title = panel.querySelector('.sidebar-panel-title');
      if (title) title.textContent = item.getAttribute('data-tooltip') || section;
    });

    // Close buttons (one per section header)
    panel.querySelectorAll('.sidebar-panel-close').forEach(function (closeBtn) {
      closeBtn.addEventListener('click', function () {
        setSidebarState('closed', '');
        activityBar.querySelectorAll('.ab-item').forEach(function (i) { i.classList.remove('active'); });
        panel.querySelectorAll('.sidebar-nav-list').forEach(function (l) { l.classList.remove('active'); });
      });
    });

    // Check if current page is a direct-link section (Home, Media)
    // If so, close sidebar and only highlight the direct link icon
    var currentPath = window.location.pathname;
    var directLinks = activityBar.querySelectorAll('a.ab-item');
    var isDirectLinkPage = false;
    directLinks.forEach(function (link) {
      var href = link.getAttribute('href');
      if (href && href.length > 1 && currentPath.startsWith(href)) {
        isDirectLinkPage = true;
        // Close sidebar when on a direct-link page
        setSidebarState('closed', '');
        activityBar.querySelectorAll('.ab-item').forEach(function (i) { i.classList.remove('active'); });
        panel.querySelectorAll('.sidebar-nav-list').forEach(function (l) { l.classList.remove('active'); });
        link.classList.add('active');
      }
    });

    // Auto-detect current section from URL (only if not on a direct-link page and sidebar not already open)
    if (!isDirectLinkPage && savedState !== 'open') {
      var navItems = panel.querySelectorAll('.sidebar-nav-item');
      navItems.forEach(function (item) {
        var href = item.getAttribute('href');
        if (href && currentPath.startsWith(href) && href.length > 1) {
          item.classList.add('active');
          var navList = item.closest('.sidebar-nav-list');
          if (navList) {
            var sectionName = navList.getAttribute('data-nav');
            if (sectionName) {
              navList.classList.add('active');
              setSidebarState('open', sectionName);

              var abItem = activityBar.querySelector('[data-section="' + sectionName + '"]');
              if (abItem) abItem.classList.add('active');

              var title = panel.querySelector('.sidebar-panel-title');
              if (title && abItem) title.textContent = abItem.getAttribute('data-tooltip') || sectionName;
            }
          }
        }
      });
    }
  }

  /* ============================================================
     7. MOBILE TOGGLE
     ============================================================ */
  function initMobile() {
    var toggle = document.querySelector('.mobile-toggle');
    var activityBar = document.getElementById('activityBar');
    var overlay = document.querySelector('.cms-overlay');
    if (!toggle || !activityBar) return;

    toggle.addEventListener('click', function () {
      activityBar.classList.toggle('open');
      if (overlay) overlay.classList.toggle('visible', activityBar.classList.contains('open'));
    });

    if (overlay) {
      overlay.addEventListener('click', function () {
        activityBar.classList.remove('open');
        var panel = document.getElementById('sidebarPanel');
        if (panel) panel.classList.remove('open');
        overlay.classList.remove('visible');
      });
    }
  }

  /* ============================================================
     8. PROFILE DROPDOWN
     ============================================================ */
  function initProfileDropdown() {
    var trigger = document.querySelector('.topbar-profile');
    var dropdown = document.querySelector('.profile-dropdown');
    if (!trigger || !dropdown) return;

    trigger.addEventListener('click', function (e) {
      e.stopPropagation();
      dropdown.classList.toggle('open');
    });

    document.addEventListener('click', function (e) {
      if (!dropdown.contains(e.target) && !trigger.contains(e.target)) {
        dropdown.classList.remove('open');
      }
    });
  }

  /* ============================================================
     9. GLOBAL SEARCH
     ============================================================ */
  function initSearch() {
    var input = document.getElementById('globalSearchInput');
    var results = document.getElementById('globalSearchResults');
    if (!input || !results) return;

    var debounceTimer;

    input.addEventListener('input', function () {
      var query = input.value.trim();
      clearTimeout(debounceTimer);

      if (query.length < 2) {
        results.classList.remove('open');
        results.innerHTML = '';
        return;
      }

      debounceTimer = setTimeout(function () {
        var adminPath = document.body.getAttribute('data-admin-path') || '/admin';
        fetch(adminPath + '/search?q=' + encodeURIComponent(query))
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (!data.results || data.results.length === 0) {
              results.innerHTML = '<div class="search-result-item text-secondary">No results found</div>';
              results.classList.add('open');
              return;
            }

            var html = '';
            data.results.forEach(function (item) {
              html += '<a href="' + item.url + '" class="search-result-item">';
              html += '<span class="text-sm font-medium">' + item.title + '</span>';
              if (item.type) html += '<span class="badge badge-default" style="margin-left:auto">' + item.type + '</span>';
              html += '</a>';
            });
            results.innerHTML = html;
            results.classList.add('open');
          })
          .catch(function () { results.classList.remove('open'); });
      }, 250);
    });

    // Close on click outside
    document.addEventListener('click', function (e) {
      if (!input.contains(e.target) && !results.contains(e.target)) {
        results.classList.remove('open');
      }
    });

    // Ctrl+K shortcut
    document.addEventListener('keydown', function (e) {
      if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
        e.preventDefault();
        input.focus();
        input.select();
      }
      // Escape closes search
      if (e.key === 'Escape') {
        results.classList.remove('open');
        input.blur();
      }
    });
  }

  /* ============================================================
     10. KEYBOARD SHORTCUTS
     ============================================================ */
  function initShortcuts() {
    var lastKey = '';
    var lastKeyTime = 0;

    document.addEventListener('keydown', function (e) {
      // Skip if inside input/textarea/select
      var tag = e.target.tagName;
      if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || e.target.isContentEditable) return;

      var now = Date.now();

      // Ctrl+S = Save form
      if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        var form = document.querySelector('form[data-save]');
        if (form) form.submit();
        return;
      }

      // G-key sequences (press G then another key within 500ms)
      if (e.key === 'g' || e.key === 'G') {
        lastKey = 'g';
        lastKeyTime = now;
        return;
      }

      if (lastKey === 'g' && now - lastKeyTime < 500) {
        var adminPath = document.body.getAttribute('data-admin-path') || '/admin';
        var routes = {
          d: adminPath,
          c: adminPath + '/collections',
          m: adminPath + '/media',
          u: adminPath + '/users',
          s: adminPath + '/settings'
        };

        var route = routes[e.key];
        if (route) {
          e.preventDefault();
          window.location.href = route;
        }
      }

      lastKey = e.key;
      lastKeyTime = now;
    });
  }

  /* ============================================================
     11. CONFIRM DIALOGS
     ============================================================ */
  function initConfirms() {
    document.addEventListener('click', function (e) {
      var btn = e.target.closest('[data-confirm]');
      if (!btn) return;

      var message = btn.getAttribute('data-confirm');
      if (!confirm(message)) {
        e.preventDefault();
        e.stopImmediatePropagation();
      }
    });
  }

  /* ============================================================
     12. BULK SELECT (Tables)
     ============================================================ */
  function initBulkSelect() {
    var selectAll = document.getElementById('selectAll');
    if (!selectAll) return;

    var checkboxes = document.querySelectorAll('.row-checkbox');
    var bulkBar = document.querySelector('.bulk-bar');
    var countEl = bulkBar ? bulkBar.querySelector('.bulk-count') : null;

    selectAll.addEventListener('change', function () {
      checkboxes.forEach(function (cb) { cb.checked = selectAll.checked; });
      updateBulkBar();
    });

    checkboxes.forEach(function (cb) {
      cb.addEventListener('change', updateBulkBar);
    });

    function updateBulkBar() {
      var checked = document.querySelectorAll('.row-checkbox:checked');
      if (bulkBar) {
        bulkBar.classList.toggle('visible', checked.length > 0);
        if (countEl) countEl.textContent = checked.length + ' selected';
      }
    }
  }

  /* ============================================================
     13. FLASH → TOAST CONVERTER
     ============================================================ */
  function convertFlashToToast() {
    var flashes = document.querySelectorAll('[data-flash]');
    flashes.forEach(function (el) {
      var type = el.getAttribute('data-flash');
      var message = el.textContent.trim();
      if (message) {
        ZuiToast.show(message, type);
      }
      el.remove();
    });
  }

  /* ============================================================
     INIT ON DOM READY
     ============================================================ */
  function init() {
    initSidebar();
    initMobile();
    initProfileDropdown();
    initSearch();
    initDropdowns();
    initTabs();
    initShortcuts();
    initConfirms();
    initBulkSelect();
    revealContent();
    convertFlashToToast();

    // Mark ready (no longer used for transition gating, kept for plugins)
    document.documentElement.classList.add('zui-ready');
  }

  // Run when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }

  // Expose toast API globally
  window.ZuiToast = ZuiToast;

})();
