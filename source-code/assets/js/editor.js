/*
 * Editor: contenteditable formatting toolbar, debounced autosave and title rename.
 *
 * Autosave rules:
 *  - one request in flight at a time; edits made meanwhile trigger a follow-up save
 *  - each save sends the version it was based on; the server answers 409 if a newer
 *    save exists, so a stale request can never overwrite newer content
 *  - failures keep the edits, show "Save failed" with Retry, and retry automatically
 */
(function () {
  'use strict';

  var SAVE_DELAY_MS = 1000;
  var app = document.getElementById('editor-app');
  var editor = document.getElementById('doc-editor');
  var titleInput = document.getElementById('doc-title');
  var statusEl = document.getElementById('save-status');
  var statusText = statusEl.querySelector('.save-text');
  var retryButton = document.getElementById('save-retry');
  var docId = parseInt(app.dataset.docId, 10);
  var isOwner = app.dataset.role === 'owner';

  var version = parseInt(app.dataset.version, 10);
  var changeSeq = 0;     // increments on every edit
  var savedSeq = 0;      // last edit sequence the server has stored
  var inFlight = false;
  var saveTimer = null;
  var retryTimer = null;
  var retryDelay = 4000;
  var conflict = false;
  var leaving = false;

  document.execCommand('defaultParagraphSeparator', false, 'p');

  // ----- Save status -----
  function setStatus(state, message) {
    statusEl.dataset.state = state;
    retryButton.hidden = state !== 'error';
    var text = {
      saved: 'All changes saved',
      dirty: 'Unsaved changes',
      saving: 'Saving…',
      error: 'Save failed',
      conflict: 'Not saved — document changed elsewhere'
    }[state];
    statusText.textContent = message ? text + ' — ' + message : text;
  }

  function hasUnsaved() {
    var titlePending = isOwner && titleInput.value.trim() !== '' && titleInput.value.trim() !== savedTitle;
    return changeSeq !== savedSeq || inFlight || titlePending;
  }

  function markDirty() {
    if (conflict) return;
    changeSeq++;
    updateEmptyState();
    setStatus('dirty');
    clearTimeout(saveTimer);
    saveTimer = setTimeout(save, SAVE_DELAY_MS);
  }

  async function save() {
    clearTimeout(saveTimer);
    clearTimeout(retryTimer);
    if (conflict || inFlight) return;
    if (changeSeq === savedSeq) {
      setStatus('saved');
      return;
    }

    var seq = changeSeq;
    inFlight = true;
    setStatus('saving');
    var failed = false;

    try {
      var data = await Ajaia.api('api/save-document.php', {
        method: 'POST',
        body: { id: docId, content: editor.innerHTML, base_version: version }
      });
      version = data.version;
      savedSeq = seq;
      retryDelay = 4000;
    } catch (err) {
      failed = true;
      if (err.status === 409) {
        conflict = true;
        setStatus('conflict');
        document.getElementById('conflict-banner').hidden = false;
      } else if (err.status === 401) {
        setStatus('error', 'your session expired. Copy any unsaved text, then sign in again.');
      } else if (err.status === 404) {
        setStatus('error', 'you no longer have access to this document.');
      } else {
        setStatus('error', err.message);
        retryTimer = setTimeout(save, retryDelay);
        retryDelay = Math.min(retryDelay * 2, 30000);
      }
    } finally {
      inFlight = false;
    }

    if (failed) return;
    if (changeSeq !== savedSeq) {
      save(); // edits arrived while this request was in flight
    } else {
      setStatus('saved');
    }
  }

  retryButton.addEventListener('click', function () {
    retryDelay = 4000;
    save();
  });

  document.getElementById('conflict-reload').addEventListener('click', function () {
    leaving = true;
    window.location.reload();
  });

  window.addEventListener('beforeunload', function (event) {
    if (!leaving && hasUnsaved()) {
      event.preventDefault();
      event.returnValue = '';
    }
  });

  // Finish saving before following the Back link instead of showing a browser prompt.
  document.querySelector('.editor-topbar a.icon-btn').addEventListener('click', async function (event) {
    if (!hasUnsaved() || conflict) return;
    event.preventDefault();
    var href = this.href;
    await save();
    if (!hasUnsaved()) {
      leaving = true;
      window.location.href = href;
    }
  });

  // ----- Editing -----
  function updateEmptyState() {
    var empty = editor.textContent.trim() === '' && !editor.querySelector('li');
    editor.classList.toggle('is-empty', empty);
  }

  editor.addEventListener('input', function () {
    if (editor.querySelector('span, font')) unwrapStyledSpans();
    markDirty();
  });

  editor.addEventListener('focus', function () {
    if (editor.innerHTML.trim() === '') editor.innerHTML = '<p><br></p>';
  });

  // Paste as plain text so foreign styles and markup never enter the document.
  editor.addEventListener('paste', function (event) {
    var text = (event.clipboardData || window.clipboardData).getData('text/plain');
    event.preventDefault();
    document.execCommand('insertText', false, text);
  });
  editor.addEventListener('drop', function (event) {
    event.preventDefault();
  });

  editor.addEventListener('keydown', function (event) {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
      event.preventDefault();
      save();
    }
  });

  // ----- Toolbar -----
  var toolbar = document.querySelector('.toolbar');
  var blockSelect = document.getElementById('block-style');

  toolbar.addEventListener('mousedown', function (event) {
    // Keep the editor selection when clicking buttons.
    if (event.target.closest('button')) event.preventDefault();
  });

  toolbar.addEventListener('click', function (event) {
    var button = event.target.closest('[data-command]');
    if (!button) return;
    editor.focus();
    document.execCommand(button.dataset.command, false, null);
    unwrapStyledSpans();
    markDirty();
    refreshToolbar();
  });

  blockSelect.addEventListener('change', function () {
    editor.focus();
    document.execCommand('formatBlock', false, '<' + blockSelect.value + '>');
    unwrapStyledSpans();
    markDirty();
    refreshToolbar();
  });

  // Browsers sometimes add <span style="…"> while formatting; the server strips
  // them on save, so remove them here too and keep the screen matching the saved document.
  function unwrapStyledSpans() {
    editor.querySelectorAll('span, font').forEach(function (span) {
      var parent = span.parentNode;
      while (span.firstChild) parent.insertBefore(span.firstChild, span);
      parent.removeChild(span);
    });
  }

  function currentBlockTag() {
    var selection = window.getSelection();
    if (!selection.rangeCount) return 'p';
    var node = selection.anchorNode;
    while (node && node !== editor) {
      if (node.nodeType === 1 && /^(H1|H2|H3|BLOCKQUOTE|P|LI)$/.test(node.nodeName)) {
        var tag = node.nodeName.toLowerCase();
        if (tag !== 'p' && tag !== 'li') return tag;
      }
      node = node.parentNode;
    }
    return 'p';
  }

  function refreshToolbar() {
    var selection = window.getSelection();
    if (!selection.rangeCount || !editor.contains(selection.anchorNode)) return;
    toolbar.querySelectorAll('[aria-pressed]').forEach(function (button) {
      var on = false;
      try { on = document.queryCommandState(button.dataset.command); } catch (e) { /* unsupported */ }
      button.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    blockSelect.value = currentBlockTag();
  }

  document.addEventListener('selectionchange', refreshToolbar);

  // ----- Title (owner only; the server enforces this too) -----
  var savedTitle = titleInput.value;
  var titleTimer = null;
  var titleSaving = null;

  // Saves while typing (debounced) and on blur/Enter. `final` reverts an empty title.
  async function saveTitle(final) {
    clearTimeout(titleTimer);
    var title = titleInput.value.trim().replace(/\s+/g, ' ');
    if (!isOwner) return;
    if (title === '') {
      if (final) {
        Ajaia.toast('Title cannot be empty.', 'error');
        titleInput.value = savedTitle;
      }
      return;
    }
    if (title === savedTitle) return;
    if (titleSaving) await titleSaving;
    if (title === savedTitle) return;

    titleSaving = Ajaia.api('api/rename-document.php', { method: 'POST', body: { id: docId, title: title } });
    try {
      var data = await titleSaving;
      savedTitle = data.title;
      if (document.activeElement !== titleInput) titleInput.value = data.title;
      document.title = data.title + ' · Ajaia Docs';
      var shareTitle = document.getElementById('share-doc-title');
      if (shareTitle) shareTitle.textContent = data.title;
    } catch (err) {
      if (final) titleInput.value = savedTitle;
      Ajaia.toast('Rename failed: ' + err.message, 'error');
    } finally {
      titleSaving = null;
    }
  }

  if (isOwner) {
    titleInput.addEventListener('input', function () {
      clearTimeout(titleTimer);
      titleTimer = setTimeout(function () { saveTitle(false); }, SAVE_DELAY_MS);
    });
    titleInput.addEventListener('blur', function () { saveTitle(true); });
    titleInput.addEventListener('keydown', function (event) {
      if (event.key === 'Enter') {
        event.preventDefault();
        titleInput.blur();
      } else if (event.key === 'Escape') {
        titleInput.value = savedTitle;
        titleInput.blur();
      }
    });
  }

  updateEmptyState();
  setStatus('saved');
  if (editor.innerHTML.trim() === '') editor.focus();
})();
