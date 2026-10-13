<?php
/**
 * File: includes/footer.php
 * Stage: 3 (shared page bottom) + Stage 8 ($extraScripts for Chart.js).
 * Pages needing extra JS set $extraScripts = ['assets/js/chart.umd.min.js'];
 * BEFORE including this file; paths are prefixed with $rootPrefix.
 */

declare(strict_types=1);

$rootPrefix = $rootPrefix ?? '';
$extraScripts = $extraScripts ?? [];
?>
</main>
<footer class="border-top py-3 mt-auto">
    <div class="container text-muted small">
        Elderly Household Welfare Check-In System (academic prototype).
        Demonstration data is synthetic. Not a substitute for emergency services.
    </div>
</footer>
<script src="<?= e($rootPrefix) ?>assets/js/bootstrap.bundle.min.js"></script>
<?php foreach ($extraScripts as $script): ?>
<script src="<?= e($rootPrefix . $script) ?>"></script>
<?php endforeach; ?>
<script src="<?= e($rootPrefix) ?>assets/js/app.js"></script>
</body>
</html>
