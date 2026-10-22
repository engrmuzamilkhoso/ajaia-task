/* Dashboard: loads document lists, search, delete, and import validation. */
(function () {
  'use strict';

  var api = Ajaia.api;
  var lists = { owned: [], shared: [] };
  var searchInput = document.getElementById('doc-search');
  var errorBox = document.getElementById('list-error');
  var pendingDelete = null;

  var ICONS = {
    doc: document.querySelector('.side-link[data-filter="owned"] svg').outerHTML,
    trash: '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/></svg>'
  };

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  }

  async function load() {
    errorBox.hidden = true;
    try {
      lists = await api('api/documents.php');
      render();
    } catch (err) {
      if (err.status === 401) {
        window.location.href = Ajaia.url('login.php');
        return;
      }
      document.getElementById('list-error-text').textContent = 'Could not load your documents: ' + err.message;
      errorBox.hidden = false;
      document.querySelectorAll('.doc-table').forEach(function (t) { t.setAttribute('aria-busy', 'false'); });
      document.querySelectorAll('.doc-rows').forEach(function (r) { r.innerHTML = ''; });
    }
  }

  function matches(doc, query) {
    if (!query) return true;
    var haystack = (doc.title + ' ' + (doc.owner_name || 'me') + ' ' + (doc.snippet || '')).toLowerCase();
    return haystack.indexOf(query) !== -1;
  }

  function render() {
    var query = searchInput.value.trim().toLowerCase();
    renderList('owned', lists.owned.filter(function (d) { return matches(d, query); }), query);
    renderList('shared', lists.shared.filter(function (d) { return matches(d, query); }), query);
    document.querySelector('[data-count="owned"]').textContent = lists.owned.length;
    document.querySelector('[data-count="shared"]').textContent = lists.shared.length;
  }

  function renderList(kind, docs, query) {
    var table = document.querySelector('[data-list="' + kind + '"]');
    var rows = table.querySelector('.doc-rows');
    table.setAttribute('aria-busy', 'false');
    rows.innerHTML = '';

    if (docs.length === 0) {
      var empty = el('div', 'empty-state');
      if (query) {
        empty.appendChild(el('strong', '', 'No matches'));
        empty.appendChild(el('span', 'muted', 'No documents match “' + query + '”.'));
      } else if (kind === 'owned') {
        empty.appendChild(el('strong', '', 'No documents yet'));
        empty.appendChild(el('span', 'muted', 'Create a new document or import a .txt, .md or .docx file to get started.'));
      } else {
        empty.appendChild(el('strong', '', 'Nothing shared with you yet'));
        empty.appendChild(el('span', 'muted', 'When someone shares a document with you, it will appear here.'));
      }
      rows.appendChild(empty);
      return;
    }

    docs.forEach(function (doc) { rows.appendChild(renderRow(doc)); });
  }

  function renderRow(doc) {
    var href = Ajaia.url('editor.php?id=' + doc.id);
    var row = el('div', 'doc-row');
    row.dataset.href = href;

    var titleCell = el('span', 'doc-title-cell');
    var iconWrap = el('span', 'doc-icon');
    iconWrap.innerHTML = ICONS.doc;
    var text = el('span', 'doc-title-text');
    var link = el('a', 'doc-link', doc.title);
    link.href = href;
    text.appendChild(link);
    text.appendChild(el('span', 'doc-snippet', doc.snippet || 'Empty document'));
    titleCell.appendChild(iconWrap);
    titleCell.appendChild(text);

    var owner = el('span', 'doc-owner', doc.role === 'owner' ? 'me' : doc.owner_name);
    if (doc.owner_email) owner.title = doc.owner_email;

    var modified = el('span', 'doc-modified', Ajaia.relativeTime(doc.updated_at));
    modified.title = Ajaia.fullTime(doc.updated_at);

    var sharing = el('span', 'doc-sharing');
    var badge;
    if (doc.role === 'owner') {
      badge = doc.share_count > 0
        ? el('span', 'badge badge-shared', 'Shared · ' + doc.share_count)
        : el('span', 'badge badge-private', 'Private');
    } else {
      badge = el('span', 'badge badge-editor', 'Can edit');
    }
    sharing.appendChild(badge);

    var actions = el('span', 'doc-actions');
    if (doc.role === 'owner') {
      var del = el('button', 'icon-btn icon-btn-danger');
      del.type = 'button';
      del.innerHTML = ICONS.trash;
      del.setAttribute('aria-label', 'Delete ' + doc.title);
      del.title = 'Delete';
      del.addEventListener('click', function (event) {
        event.stopPropagation();
        askDelete(doc);
      });
      actions.appendChild(del);
    }

    [titleCell, owner, modified, sharing, actions].forEach(function (c) { row.appendChild(c); });
    row.addEventListener('click', function (event) {
      if (event.target.closest('a, button')) return;
      window.location.href = href;
    });
    return row;
  }

  // ----- Delete -----
  var deleteDialog = document.getElementById('delete-dialog');
  var deleteConfirm = document.getElementById('delete-confirm');

  function askDelete(doc) {
    pendingDelete = doc;
    document.getElementById('delete-doc-title').textContent = doc.title;
    deleteDialog.showModal();
  }

  deleteConfirm.addEventListener('click', async function () {
    if (!pendingDelete) return;
    deleteConfirm.disabled = true;
    deleteConfirm.textContent = 'Deleting…';
    try {
      await api('api/delete-document.php', { method: 'POST', body: { id: pendingDelete.id } });
      Ajaia.toast('Deleted “' + pendingDelete.title + '”', 'success');
      deleteDialog.close();
      await load();
    } catch (err) {
      Ajaia.toast(err.message, 'error');
    } finally {
      deleteConfirm.disabled = false;
      deleteConfirm.textContent = 'Delete';
      pendingDelete = null;
    }
  });

  // ----- Import (client-side checks for fast feedback; the server re-validates) -----
  var MAX_BYTES = 2 * 1024 * 1024;
  var importForm = document.getElementById('import-form');
  var fileInput = document.getElementById('import-file');
  var importError = document.getElementById('import-error');
  var dropzone = document.getElementById('dropzone');

  function checkFile() {
    var file = fileInput.files[0];
    importError.hidden = true;
    if (!file) return false;
    document.getElementById('dropzone-label').textContent = file.name;
    var ext = (file.name.split('.').pop() || '').toLowerCase();
    var problem = null;
    if (['txt', 'md', 'markdown', 'docx'].indexOf(ext) === -1) problem = 'Unsupported file type. Choose a .txt, .md or .docx file.';
    else if (file.size === 0) problem = 'That file is empty.';
    else if (file.size > MAX_BYTES) problem = 'That file is larger than the 2 MB limit.';
    if (problem) {
      importError.textContent = problem;
      importError.hidden = false;
      return false;
    }
    return true;
  }

  fileInput.addEventListener('change', checkFile);
  ['dragenter', 'dragover'].forEach(function (type) {
    dropzone.addEventListener(type, function () { dropzone.classList.add('is-dragging'); });
  });
  ['dragleave', 'drop'].forEach(function (type) {
    dropzone.addEventListener(type, function () { dropzone.classList.remove('is-dragging'); });
  });
  importForm.addEventListener('submit', function (event) {
    if (!checkFile()) {
      event.preventDefault();
      if (!fileInput.files[0]) {
        importError.textContent = 'Choose a file to import.';
        importError.hidden = false;
      }
      return;
    }
    var submit = document.getElementById('import-submit');
    submit.disabled = true;
    submit.textContent = 'Importing…';
  });

  searchInput.addEventListener('input', render);
  document.getElementById('list-retry').addEventListener('click', load);
  load();
})();
