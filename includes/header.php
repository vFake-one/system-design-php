<?php
/**
 * File: includes/header.php
 * Stage: 3 - shared page top (Bootstrap layout, navbar, flash messages).
 *
 * Each page sets two variables BEFORE including this file:
 *   $pageTitle  - shown in <title> and the navbar brand area, e.g. 'Log in'
 *   $rootPrefix - '' for root pages (index/login), '../' for admin/, caregiver/, elderly/
 *
 * Pages MUST run require_login()/require_role() BEFORE including this file,
 * because redirects ("Location:" headers) cannot be sent after output starts.
 */

declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/csrf.php';

$pageTitle  = $pageTitle ?? 'Elderly Welfare System';
$rootPrefix = $rootPrefix ?? '';
$user       = current_user(); // null for guests (also enforces idle timeout)
$homeUrl    = $rootPrefix . ($user ? role_home($user['role']) : 'index.php');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> - Elderly Welfare Check-In System</title>
    <link rel="stylesheet" href="<?= e($rootPrefix) ?>assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= e($rootPrefix) ?>assets/css/app.css">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary mb-4">
    <div class="container">
        <a class="navbar-brand" href="<?= e($homeUrl) ?>">Elderly Welfare Check-In</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="nav">
            <ul class="navbar-nav me-auto">
                <?php if ($user && $user['role'] === 'admin'): ?>
                    <li class="nav-item"><a class="nav-link" href="<?= e($rootPrefix) ?>admin/dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= e($rootPrefix) ?>admin/elderly.php">Households</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= e($rootPrefix) ?>admin/schedules.php">Schedules</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= e($rootPrefix) ?>caregiver/alerts.php">Alerts</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= e($rootPrefix) ?>caregiver/review.php">Review</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= e($rootPrefix) ?>admin/caregivers.php">Caregivers</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= e($rootPrefix) ?>admin/assignments.php">Assignments</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= e($rootPrefix) ?>admin/settings.php">Settings</a></li>
                <?php elseif ($user && $user['role'] === 'caregiver'): ?>
                    <li class="nav-item"><a class="nav-link" href="<?= e($rootPrefix) ?>caregiver/dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= e($rootPrefix) ?>caregiver/checkins.php">Check-ins</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= e($rootPrefix) ?>caregiver/alerts.php">Alerts</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= e($rootPrefix) ?>caregiver/review.php">Review</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= e($rootPrefix) ?>caregiver/visits.php">Visits</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= e($rootPrefix) ?>caregiver/followups.php">Follow-ups</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= e($rootPrefix) ?>caregiver/households.php">My households</a></li>
                <?php elseif ($user): ?>
                    <li class="nav-item"><a class="nav-link" href="<?= e($rootPrefix) ?>elderly/checkin.php">My check-ins</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= e($rootPrefix) ?>elderly/profile.php">My profile</a></li>
                <?php endif; ?>
                <?php /* Alerts, reports, ... links are added in later stages. */ ?>
            </ul>
            <ul class="navbar-nav">
                <?php if ($user): ?>
                    <li class="nav-item navbar-text me-3">
                        <?= e($user['full_name']) ?>
                        <span class="badge bg-light text-dark"><?= e($user['role']) ?></span>
                    </li>
                    <li class="nav-item">
                        <?php /* Logout is a POST form (CSRF-protected), styled as a button. */ ?>
                        <form method="post" action="<?= e($rootPrefix) ?>logout.php" class="d-inline">
                            <?= csrf_field() ?>
                            <button type="submit" class="btn btn-outline-light btn-sm">Log out</button>
                        </form>
                    </li>
                <?php else: ?>
                    <li class="nav-item"><a class="nav-link" href="<?= e($rootPrefix) ?>login.php">Log in</a></li>
                <?php endif; ?>
            </ul>
        </div>
    </div>
</nav>
<main class="container mb-5">
    <?php foreach (flashes() as $flash): ?>
        <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert">
            <?= e($flash['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endforeach; ?>
