<?php
/**
 * File: index.php (PROJECT ROOT)
 * Stage: 3 - public landing page. Logged-in users bounce straight to
 * their role home; guests see what the system does + a Log in button.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

if (is_logged_in()) {
    redirect(role_home(current_user()['role']));
}

$pageTitle  = 'Welcome';
$rootPrefix = '';
require_once __DIR__ . '/includes/header.php';
?>
<div class="p-5 mb-4 bg-light rounded-3">
    <div class="container-fluid py-3">
        <h1 class="display-6 fw-bold">Elderly Household Welfare Check-In System</h1>
        <p class="fs-5">A central place to schedule welfare check-ins, notice missed
            contact early, alert caregivers, and keep visit records - running on
            PHP + MySQL.</p>
        <a class="btn btn-primary btn-lg" href="login.php">Log in to continue</a>
    </div>
</div>

<div class="row g-4">
    <div class="col-md-4">
        <div class="card h-100"><div class="card-body">
            <h2 class="h5 card-title">Administrators</h2>
            <p class="card-text">Register elderly households, manage caregiver
                accounts and assignments, configure schedules, and view reports.</p>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card h-100"><div class="card-body">
            <h2 class="h5 card-title">Caregivers</h2>
            <p class="card-text">See assigned households, record check-ins and
                visits, acknowledge alerts, and document follow-up actions.</p>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="card h-100"><div class="card-body">
            <h2 class="h5 card-title">Elderly &amp; family</h2>
            <p class="card-text">A simple large-text page to view schedules,
                submit check-ins, and ask for assistance.</p>
        </div></div>
    </div>
</div>

<div class="alert alert-warning mt-4">
    <strong>Academic prototype.</strong> Demonstration data is synthetic. This
    system assists human caregivers - it is not a substitute for emergency
    services or medical monitoring.
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
