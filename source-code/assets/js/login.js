/* Demo account buttons fill the real sign-in form; the server still verifies the password. */
(function () {
  'use strict';
  var form = document.getElementById('login-form');
  document.querySelectorAll('.demo-user').forEach(function (button) {
    button.addEventListener('click', function () {
      document.getElementById('login-email').value = button.getAttribute('data-email');
      document.getElementById('login-password').value = 'demo1234';
      form.submit();
    });
  });
})();
