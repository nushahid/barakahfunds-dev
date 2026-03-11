<?php
require_once __DIR__ . '/includes/functions.php';
startSecureSession();
logoutUser();
startSecureSession();
setFlash('success', 'You have been logged out.');
header('Location: login.php');
exit;
