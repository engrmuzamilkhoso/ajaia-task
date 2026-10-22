/* Shared helpers: JSON API calls, toasts, dialogs, time formatting. */
(function () {
  'use strict';

  var base = document.querySelector('meta[name="app-base"]').content;
  var csrfToken = document.querySelector('meta[name="csrf-token"]').content;

  function ApiError(message, status) {
    this.message = message;
    this.status = status;
  }
  ApiError.prototype = Object.create(Error.prototype);

  /** Call a JSON endpoint. Resolves with `data`, rejects with ApiError. */
  async function api(path, options) {
    options = options || {};
    var headers = { Accept: 'application/json', 'X-CSRF-Token': csrfToken };
    if (options.body !== undefined) headers['Content-Type'] = 'application/json';

    var response;
    try {
      response = await fetch(base + '/' + path, {
        method: options.method || 'GET',
        headers: headers,
        body: options.body !== undefined ? JSON.stringify(options.body) : undefined,
        credentials: 'same-origin',
        cache: 'no-store'
      });
    } catch (e) {
      throw new ApiError('Network error — check your connection.', 0);
    }

    var payload = null;
    try { payload = await response.json(); } catch (e) { /* non-JSON error page */ }
    if (!response.ok || !payload || !payload.ok) {
      var message = payload && payload.error ? payload.error.message : 'Request failed (' + response.status + ').';
      throw new ApiError(message, response.status);
    }
    return payload.data;
  }

  function url(path) {
    return base + '/' + path;
  }

  function toast(message, type) {
    var region = document.getElementById('toast-region');
    if (!region) return;
    var el = document.createElement('div');
    el.className = 'toast toast-' + (type || 'info');
    el.textContent = message;
    region.appendChild(el);
    setTimeout(function () { el.classList.add('toast-leave'); }, 3200);
    setTimeout(function () { el.remove(); }, 3600);
  }

  function relativeTime(iso) {
    if (!iso) return '';
    var date = new Date(iso);
    var seconds = Math.round((Date.now() - date.getTime()) / 1000);
    if (seconds < 45) return 'Just now';
    var minutes = Math.round(seconds / 60);
    if (minutes < 60) return minutes + ' min ago';
    var hours = Math.round(minutes / 60);
    if (hours < 24) return hours + (hours === 1 ? ' hour ago' : ' hours ago');
    var days = Math.round(hours / 24);
    if (days < 7) return days === 1 ? 'Yesterday' : days + ' days ago';
    return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: date.getFullYear() === new Date().getFullYear() ? undefined : 'numeric' });
  }

  function fullTime(iso) {
    return iso ? new Date(iso).toLocaleString() : '';
  }

  // Dialogs: [data-open-dialog="id"] opens, [data-close-dialog] closes, backdrop click closes.
  document.addEventListener('click', function (event) {
    var opener = event.target.closest('[data-open-dialog]');
    if (opener) {
      var dialog = document.getElementById(opener.getAttribute('data-open-dialog'));
      if (dialog) dialog.showModal();
      return;
    }
    var closer = event.target.closest('[data-close-dialog]');
    if (closer) {
      closer.closest('dialog').close();
      return;
    }
    if (event.target.tagName === 'DIALOG') {
      var rect = event.target.getBoundingClientRect();
      var inside = event.clientX >= rect.left && event.clientX <= rect.right && event.clientY >= rect.top && event.clientY <= rect.bottom;
      if (!inside) event.target.close();
    }
    // Close the account menu when clicking elsewhere.
    document.querySelectorAll('details.account-menu[open]').forEach(function (menu) {
      if (!menu.contains(event.target)) menu.removeAttribute('open');
    });
  });

  window.Ajaia = { api: api, url: url, toast: toast, relativeTime: relativeTime, fullTime: fullTime, ApiError: ApiError };
})();
