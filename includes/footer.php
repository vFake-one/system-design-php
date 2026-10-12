<?php
/**
 * File: includes/footer.php
 * Stage: 3 - shared page bottom. Closes <main>, prints the footer bar,
 * loads Bootstrap JS + the app's small script. Every page ends with:
 *
 *   require_once __DIR__ . '/../includes/footer.php';   (path varies by folder)
 */

declare(strict_types=1);

$rootPrefix = $rootPrefix ?? '';
?>
</main>
<footer class="border-top py-3 mt-auto">
    <div class="container text-muted small">
        Elderly Household Welfare Check-In System (academic prototype).
        Demonstration data is synthetic. Not a substitute for emergency services.
    </div>
</footer>
<script src="<?= e($rootPrefix) ?>assets/js/bootstrap.bundle.min.js"></script>
<script src="<?= e($rootPrefix) ?>assets/js/app.js"></script>
</body>
</html>
