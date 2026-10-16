<?php
require __DIR__ . '/../app/bootstrap.php';
// Logout is POST-only with a CSRF token, so another website cannot log users out.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/');
}
Csrf::enforce();
Auth::logout();
session_start();
flash('success', 'You have been logged out.');
redirect('login.php');
