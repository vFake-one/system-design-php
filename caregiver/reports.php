<?php
/**
 * File: caregiver/reports.php
 * Stage: 8 - MY-households reports. Identical UI to the admin's; every query
 * is confined to assigned ids through $scope.
 */

declare(strict_types=1);

ob_start(); // lets the CSV branch discard page output and send a file instead
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/reports_lib.php';

$user = require_role('caregiver', '../login.php');
$scope = assigned_elderly_ids((int) $user['id']);

$pageTitle  = 'My reports';
$rootPrefix = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<h1>My reports</h1>
<?php require_once __DIR__ . '/../includes/reports_page.php'; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
