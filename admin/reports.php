<?php
/**
 * File: admin/reports.php
 * Stage: 8 - system-wide reports (scope null). Rendering is shared with the
 * caregiver page through includes/reports_page.php - only the scope differs.
 */

declare(strict_types=1);

ob_start(); // lets the CSV branch discard page output and send a file instead
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/reports_lib.php';

$user = require_role('admin', '../login.php');
$scope = null; // null = whole system

$pageTitle  = 'Reports';
$rootPrefix = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>Reports</h1>
<?php require_once __DIR__ . '/../includes/reports_page.php'; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
