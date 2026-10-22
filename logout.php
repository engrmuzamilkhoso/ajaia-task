<?php
require __DIR__ . '/includes/bootstrap.php';

require_method('POST');
verify_csrf();

$switching = !empty($_POST['switch']);
logout_user();
flash('info', $switching ? 'Choose another demo account to continue.' : 'You have been signed out.');
redirect('login.php');
