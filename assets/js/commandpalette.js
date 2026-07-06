/**
 * Command palette - keyboard overlay (vanilla JS, no Bootstrap).
 */
(function () {
  'use strict';

  var FOCUSABLE = 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])';

  function readConfig() {
    var el = document.getElementById('cmdpalette-data');
    if (!el) {
      return null;
    }
    try {
      return JSON.parse(el.textContent || '{}');
    } catch (e) {
      return null;
    }
  }

  function normalize(str) {
    return (str || '').toLowerCase().trim();
  }

  function normalizeShortcutKey(key) {
    if (!key) {
      return '';
    }
    var k = String(key).toLowerCase();
    if (k === 'slash' || k === '/') {
      return '/';
    }
    return k.length === 1 ? k : k;
  }

  function isSlashShortcutKey(e) {
    var code = e.code || '';
    if (code === 'Slash' || code === 'NumpadDivide') {
      return true;
    }
    if (typeof e.keyCode === 'number' && (e.keyCode === 191 || e.keyCode === 111)) {
      return true;
    }
    var key = normalizeShortcutKey(e.key);
    return key === '/' || key === '?';
  }

  function shortcutMatchers(shortcuts) {
    var matchers = [];
    if (!shortcuts) {
      return matchers;
    }

    if (shortcuts.ctrl_k) {
      matchers.push(function (e) {
        return normalizeShortcutKey(e.key) === 'k' || e.code === 'KeyK';
      });
    }

    if (shortcuts.ctrl_slash) {
      matchers.push(function (e) {
        return isSlashShortcutKey(e);
      });
    }

    if (shortcuts.custom_enabled && shortcuts.custom_key) {
      var customKey = normalizeShortcutKey(shortcuts.custom_key);
      matchers.push(function (e) {
        return normalizeShortcutKey(e.key) === customKey;
      });
    }

    return matchers;
  }

  function formatShortcutLabel(key) {
    var display = key === '/' ? '/' : String(key).toUpperCase();
    return '<kbd>Ctrl</kbd><kbd>' + display + '</kbd>';
  }

  function renderShortcutHint(shortcuts) {
    var hint = document.getElementById('cmdpalette-shortcut-hint');
    if (!hint || !shortcuts) {
      return;
    }

    var parts = [];
    if (shortcuts.ctrl_k) {
      parts.push(formatShortcutLabel('k'));
    }
    if (shortcuts.ctrl_slash) {
      parts.push(formatShortcutLabel('/'));
    }
    if (shortcuts.custom_enabled && shortcuts.custom_key) {
      parts.push(formatShortcutLabel(shortcuts.custom_key));
    }

    if (!parts.length) {
      parts.push(formatShortcutLabel('k'));
    }

    hint.innerHTML = parts.join(' ') + ' toggle';
  }

  function isTypingTarget(target) {
    if (!target) {
      return false;
    }
    var tag = target.tagName ? target.tagName.toLowerCase() : '';
    return tag === 'input' || tag === 'textarea' || tag === 'select' || target.isContentEditable;
  }

  function matchScore(cmd, q) {
    if (!q) {
      return 1;
    }
    var label = normalize(cmd.label);
    var group = normalize(cmd.group);
    var hay = label + ' ' + group + ' ' + (cmd.keywords || []).join(' ');
    if (label.indexOf(q) === 0) {
      return 100;
    }
    if (label.indexOf(q) !== -1) {
      return 80;
    }
    if (hay.indexOf(q) !== -1) {
      return 50;
    }
    return 0;
  }

  function escapeRegExp(str) {
    return String(str).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  }

  function parseUserQuery(raw, searchPrefix) {
    var prefix = (searchPrefix || 'user:').toLowerCase();
    var s = String(raw || '');
    var re = new RegExp('^' + escapeRegExp(prefix), 'i');
    if (!re.test(s)) {
      return { active: false, query: '' };
    }
    return { active: true, query: s.replace(re, '').trim() };
  }

  function filterStaticCommands(commands, q) {
    var nq = normalize(q);
    if (!nq) {
      return commands.slice();
    }
    return commands
      .map(function (cmd) {
        return { cmd: cmd, score: matchScore(cmd, nq) };
      })
      .filter(function (row) {
        return row.score > 0;
      })
      .sort(function (a, b) {
        return b.score - a.score || a.cmd.label.localeCompare(b.cmd.label);
      })
      .map(function (row) {
        return row.cmd;
      });
  }

  function userResultToCommand(row, accountManagerRoot) {
    var username = row.username || '';
    var href = row.href || '';
    if (!href && accountManagerRoot) {
      var root = accountManagerRoot;
      if (/[?&]id=$/.test(root)) {
        href = root + String(row.id);
      } else {
        href = root + encodeURIComponent(String(username).toLowerCase()) + '/';
      }
    }
    return {
      id: 'user-' + row.id,
      label: row.label || username,
      href: href,
      group: 'Users',
      icon: 'fa-user',
      keywords: [username, String(row.id)],
      _userResult: true
    };
  }

  function init() {
    var cfg = readConfig();
    var root = document.getElementById('cmdpalette-root');
    var input = document.getElementById('cmdpalette-input');
    var list = document.getElementById('cmdpalette-list');
    var empty = document.getElementById('cmdpalette-empty');
    if (!cfg || !root || !input || !list) {
      return;
    }

    var shortcuts = cfg.shortcuts || {
      ctrl_k: true,
      ctrl_slash: true,
      custom_enabled: false,
      custom_key: 'p'
    };
    var openers = shortcutMatchers(shortcuts);
    var footer = root.querySelector('.cmdpalette__footer');
    if (footer && !showFooterHint) {
      footer.hidden = true;
    } else {
      renderShortcutHint(shortcuts);
    }

    var commands = Array.isArray(cfg.commands) ? cfg.commands.slice() : [];
    var searchUrl = cfg.searchUrl || '';
    var accountManagerRoot = cfg.accountManagerRoot || '';
    var csrf = cfg.csrf || '';
    var searchPrefix = (cfg.searchPrefix || 'user:').toLowerCase();
    var showFooterHint = cfg.showFooterHint !== false;
    var open = false;
    var filtered = commands.slice();
    var userResults = [];
    var userSearchLoading = false;
    var searchTimer = null;
    var searchSeq = 0;
    var activeIndex = 0;
    var lastActive = null;
    var defaultPlaceholder = input.getAttribute('placeholder') || 'Type a command or search...';

    function shouldFetchUsers(q) {
      var parsed = parseUserQuery(q, searchPrefix);
      return !!searchUrl && parsed.active && parsed.query.length >= 2;
    }

    function isUserPrefixHint(q) {
      var parsed = parseUserQuery(q, searchPrefix);
      return parsed.active && parsed.query.length < 2;
    }

    function renderUserPrefixHint() {
      list.innerHTML = '';
      if (empty) {
        empty.hidden = true;
      }

      var heading = document.createElement('li');
      heading.className = 'cmdpalette__group';
      heading.textContent = 'Users';
      heading.setAttribute('role', 'presentation');
      list.appendChild(heading);

      var hint = document.createElement('li');
      hint.className = 'cmdpalette__hint';
      hint.textContent = 'Type a username, email, or ID after ' + searchPrefix;
      hint.setAttribute('role', 'presentation');
      list.appendChild(hint);
    }

    function updateEmptyState(q) {
      if (!empty) {
        return;
      }
      var parsed = parseUserQuery(q != null ? q : input.value, searchPrefix);
      if (!filtered.length) {
        if (parsed.active && parsed.query.length < 2) {
          empty.hidden = true;
          return;
        }
        empty.hidden = false;
        if (parsed.active) {
          if (userSearchLoading) {
            empty.textContent = 'Searching users…';
          } else {
            empty.textContent = 'No users found';
          }
        } else {
          empty.textContent = 'No matching commands.';
        }
        return;
      }
      empty.hidden = true;
    }

    function renderList() {
      if (isUserPrefixHint(input.value)) {
        renderUserPrefixHint();
        return;
      }

      list.innerHTML = '';
      updateEmptyState(input.value);
      if (!filtered.length) {
        return;
      }

      var lastGroup = null;
      filtered.forEach(function (cmd, idx) {
        if (cmd.group && cmd.group !== lastGroup) {
          lastGroup = cmd.group;
          var heading = document.createElement('li');
          heading.className = 'cmdpalette__group';
          heading.textContent = lastGroup;
          heading.setAttribute('role', 'presentation');
          list.appendChild(heading);
        }

        var li = document.createElement('li');
        li.className = 'cmdpalette__item' + (idx === activeIndex ? ' is-active' : '');
        li.setAttribute('role', 'option');
        li.setAttribute('aria-selected', idx === activeIndex ? 'true' : 'false');
        li.dataset.href = cmd.href;
        li.dataset.index = String(idx);

        if (cmd.icon) {
          var icon = document.createElement('span');
          icon.className = 'cmdpalette__item-icon';
          icon.innerHTML = '<i class="fa ' + cmd.icon + '" aria-hidden="true"></i>';
          li.appendChild(icon);
        }

        var label = document.createElement('span');
        label.className = 'cmdpalette__item-label';
        label.textContent = cmd.label;
        li.appendChild(label);

        list.appendChild(li);
      });

      var activeEl = list.querySelector('.cmdpalette__item.is-active');
      if (activeEl && typeof activeEl.scrollIntoView === 'function') {
        activeEl.scrollIntoView({ block: 'nearest' });
      }
    }

    function fetchUserSearch(q) {
      var parsed = parseUserQuery(q, searchPrefix);
      if (!shouldFetchUsers(q)) {
        userResults = [];
        userSearchLoading = false;
        return Promise.resolve();
      }

      var seq = ++searchSeq;
      if (!userSearchLoading) {
        userSearchLoading = true;
        updateEmptyState(q);
      }

      var url = searchUrl +
        (searchUrl.indexOf('?') >= 0 ? '&' : '?') +
        'token=' + encodeURIComponent(csrf) +
        '&q=' + encodeURIComponent(parsed.query);

      return fetch(url, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' }
      })
        .then(function (res) {
          return res.json();
        })
        .then(function (data) {
          if (seq !== searchSeq) {
            return;
          }
          userSearchLoading = false;
          if (data && data.state && Array.isArray(data.results)) {
            userResults = data.results.map(function (row) {
              return userResultToCommand(row, accountManagerRoot);
            });
          } else {
            userResults = [];
          }
        })
        .catch(function () {
          if (seq !== searchSeq) {
            return;
          }
          userSearchLoading = false;
          userResults = [];
        });
    }

    function applyFilter(q) {
      var parsed = parseUserQuery(q, searchPrefix);
      if (parsed.active) {
        filtered = userResults.slice();
      } else {
        filtered = filterStaticCommands(commands, q);
      }
      activeIndex = 0;
      renderList();
    }

    function filterCommands(q) {
      var nq = normalize(q);
      var parsed = parseUserQuery(q, searchPrefix);

      if (!nq) {
        userResults = [];
        userSearchLoading = false;
        searchSeq++;
        if (searchTimer) {
          clearTimeout(searchTimer);
          searchTimer = null;
        }
        filtered = commands.slice();
        activeIndex = 0;
        renderList();
        return;
      }

      if (parsed.active) {
        if (parsed.query.length < 2) {
          userResults = [];
          userSearchLoading = false;
          searchSeq++;
          if (searchTimer) {
            clearTimeout(searchTimer);
            searchTimer = null;
          }
          filtered = [];
          activeIndex = 0;
          renderList();
          return;
        }

        userSearchLoading = true;
        filtered = userResults.slice();
        activeIndex = 0;
        renderList();

        if (searchTimer) {
          clearTimeout(searchTimer);
        }
        searchTimer = setTimeout(function () {
          fetchUserSearch(q).then(function () {
            applyFilter(input.value);
          });
        }, 200);
        return;
      }

      userResults = [];
      userSearchLoading = false;
      searchSeq++;
      if (searchTimer) {
        clearTimeout(searchTimer);
        searchTimer = null;
      }
      filtered = filterStaticCommands(commands, q);
      activeIndex = 0;
      renderList();
    }

    function navigate(href) {
      if (!href || href === '#' || href === '#user-search') {
        return;
      }
      window.location.href = href;
    }

    function beginUserSearchMode() {
      input.value = searchPrefix;
      filterCommands(searchPrefix);
      input.focus();
      var len = input.value.length;
      if (typeof input.setSelectionRange === 'function') {
        input.setSelectionRange(len, len);
      }
    }

    function openPalette() {
      if (open) {
        return;
      }
      open = true;
      lastActive = document.activeElement;
      root.hidden = false;
      root.setAttribute('aria-hidden', 'false');
      requestAnimationFrame(function () {
        root.classList.add('is-open');
      });
      input.value = '';
      input.placeholder = defaultPlaceholder;
      filterCommands('');
      input.focus();
      document.addEventListener('keydown', onKeydown, true);
    }

    function closePalette() {
      if (!open) {
        return;
      }
      open = false;
      root.classList.remove('is-open');
      root.setAttribute('aria-hidden', 'true');
      document.removeEventListener('keydown', onKeydown, true);
      var done = false;
      function finish() {
        if (done) {
          return;
        }
        done = true;
        root.hidden = true;
        if (lastActive && typeof lastActive.focus === 'function') {
          lastActive.focus();
        }
      }
      root.addEventListener('transitionend', finish, { once: true });
      setTimeout(finish, 200);
    }

    function togglePalette() {
      if (open) {
        closePalette();
      } else {
        openPalette();
      }
    }

    function moveActive(delta) {
      if (!filtered.length) {
        return;
      }
      activeIndex = (activeIndex + delta + filtered.length) % filtered.length;
      renderList();
    }

    function activateCurrent() {
      if (!filtered.length) {
        return;
      }
      var cmd = filtered[activeIndex];
      if (cmd && (cmd.id === 'go-user-search' || cmd.href === '#user-search')) {
        beginUserSearchMode();
        return;
      }
      navigate(cmd.href);
    }

    function onKeydown(e) {
      if (!open) {
        return;
      }

      if (e.key === 'Escape') {
        e.preventDefault();
        closePalette();
        return;
      }

      if (e.key === 'ArrowDown') {
        e.preventDefault();
        moveActive(1);
        return;
      }

      if (e.key === 'ArrowUp') {
        e.preventDefault();
        moveActive(-1);
        return;
      }

      if (e.key === 'Enter') {
        e.preventDefault();
        activateCurrent();
        return;
      }

      if (e.key === 'Tab') {
        var panel = root.querySelector('.cmdpalette__panel');
        if (!panel) {
          return;
        }
        var nodes = Array.prototype.slice.call(panel.querySelectorAll(FOCUSABLE));
        if (!nodes.length) {
          return;
        }
        var first = nodes[0];
        var last = nodes[nodes.length - 1];
        if (e.shiftKey && document.activeElement === first) {
          e.preventDefault();
          last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
          e.preventDefault();
          first.focus();
        }
      }
    }

    function hasOpenModifier(e) {
      if (e.altKey) {
        return false;
      }
      // Accept Ctrl or Cmd on every platform so Cmd+/ works on Mac and Ctrl+/ on Windows.
      return e.ctrlKey || e.metaKey;
    }

    function matchesOpenShortcut(e) {
      if (!openers.length || !hasOpenModifier(e)) {
        return false;
      }

      for (var i = 0; i < openers.length; i++) {
        if (openers[i](e)) {
          return true;
        }
      }

      return false;
    }

    function onGlobalOpenShortcut(e) {
      if (!matchesOpenShortcut(e)) {
        return;
      }

      if (!open && isTypingTarget(e.target)) {
        return;
      }

      e.preventDefault();
      if (typeof e.stopImmediatePropagation === 'function') {
        e.stopImmediatePropagation();
      }
      e.stopPropagation();
      togglePalette();
    }

    // Capture on window so we run before browser defaults (Ctrl+/) and page scripts.
    window.addEventListener('keydown', onGlobalOpenShortcut, true);

    input.addEventListener('input', function () {
      filterCommands(input.value);
    });

    list.addEventListener('mousemove', function (e) {
      var item = e.target.closest ? e.target.closest('.cmdpalette__item') : null;
      if (!item) {
        return;
      }
      var idx = parseInt(item.dataset.index, 10);
      if (!isNaN(idx) && idx !== activeIndex) {
        activeIndex = idx;
        renderList();
      }
    });

    list.addEventListener('click', function (e) {
      var item = e.target.closest ? e.target.closest('.cmdpalette__item') : null;
      if (!item) {
        return;
      }
      var idx = parseInt(item.dataset.index, 10);
      if (isNaN(idx) || !filtered[idx]) {
        return;
      }
      var cmd = filtered[idx];
      if (cmd.id === 'go-user-search' || cmd.href === '#user-search') {
        beginUserSearchMode();
        return;
      }
      navigate(item.dataset.href);
    });

    root.querySelectorAll('[data-cmdpalette-close]').forEach(function (el) {
      el.addEventListener('click', closePalette);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
