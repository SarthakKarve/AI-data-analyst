<?php
/**
 * Authentication guard + bootstrap.
 * Every protected page must: require __DIR__ . '/includes/auth_check.php';
 * After this file runs the following are available:
 *   $pdo      – PDO instance
 *   $userId   – int, authenticated user ID
 *   h(), fetchOne(), fetchAll(), fetchValue(), logActivity(), csrfToken(), csrfVerify(), …
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/helpers.php';

$userId = (int)$_SESSION['user_id'];
