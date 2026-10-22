/* Share dialog: list people with access, grant and revoke (owner only, enforced server-side). */
(function () {
  'use strict';

  var app = document.getElementById('editor-app');
  var docId = parseInt(app.dataset.docId, 10);
  var currentUserId = parseInt(app.dataset.userId, 10);
  var dialog = document.getElementById('share-dialog');
  var form = document.getElementById('share-form');
  var select = document.getElementById('share-user');
  var submit = document.getElementById('share-submit');
  var list = document.getElementById('people-list');
  var errorEl = document.getElementById('share-error');

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  }

  function initials(name) {
    return name.split(/\s+/).slice(0, 2).map(function (p) { return p.charAt(0).toUpperCase(); }).join('');
  }

  function showError(message) {
    errorEl.textContent = message;
    errorEl.hidden = !message;
  }

  function personRow(person, roleLabel, canRevoke) {
    var li = el('li', 'person');
    li.appendChild(el('span', 'avatar', initials(person.name)));
    var text = el('span', 'person-text');
    text.appendChild(el('strong', '', person.name + (person.user_id === currentUserId ? ' (you)' : '')));
    text.appendChild(el('span', 'muted', person.email));
    li.appendChild(text);
    li.appendChild(el('span', 'person-role', roleLabel));
    if (canRevoke) {
      var revoke = el('button', 'btn btn-link btn-danger-text', 'Remove');
      revoke.type = 'button';
      revoke.setAttribute('aria-label', 'Remove access for ' + person.name);
      revoke.addEventListener('click', function () { revokeAccess(person, revoke); });
      li.appendChild(revoke);
    }
    return li;
  }

  function render(info) {
    list.setAttribute('aria-busy', 'false');
    list.innerHTML = '';
    list.appendChild(personRow(info.owner, 'Owner', false));
    info.shares.forEach(function (share) {
      list.appendChild(personRow(share, 'Can edit', info.can_manage));
    });
    if (info.shares.length === 0) {
      list.appendChild(el('li', 'people-empty muted', 'Not shared with anyone yet.'));
    }

    select.innerHTML = '';
    if (info.candidates.length === 0) {
      var none = el('option', '', 'Everyone already has access');
      none.value = '';
      select.appendChild(none);
      select.disabled = true;
      submit.disabled = true;
    } else {
      info.candidates.forEach(function (user) {
        var option = el('option', '', user.name + ' — ' + user.email);
        option.value = user.user_id;
        select.appendChild(option);
      });
      select.disabled = false;
      submit.disabled = false;
    }
  }

  async function load() {
    showError('');
    list.setAttribute('aria-busy', 'true');
    try {
      render(await Ajaia.api('api/shares.php?id=' + docId));
    } catch (err) {
      list.innerHTML = '';
      showError('Could not load sharing settings: ' + err.message);
    }
  }

  form.addEventListener('submit', async function (event) {
    event.preventDefault();
    var userId = parseInt(select.value, 10);
    if (!userId) return;
    var name = select.options[select.selectedIndex].textContent.split(' — ')[0];
    showError('');
    submit.disabled = true;
    submit.textContent = 'Sharing…';
    try {
      render(await Ajaia.api('api/share-document.php', { method: 'POST', body: { id: docId, user_id: userId } }));
      Ajaia.toast('Shared with ' + name, 'success');
    } catch (err) {
      showError(err.message);
      submit.disabled = false;
    } finally {
      submit.textContent = 'Share';
    }
  });

  async function revokeAccess(person, button) {
    showError('');
    button.disabled = true;
    try {
      render(await Ajaia.api('api/revoke-share.php', { method: 'POST', body: { id: docId, user_id: person.user_id } }));
      Ajaia.toast('Removed access for ' + person.name, 'success');
    } catch (err) {
      showError(err.message);
      button.disabled = false;
    }
  }

  document.getElementById('share-button').addEventListener('click', function () {
    dialog.showModal();
    load();
  });
})();
