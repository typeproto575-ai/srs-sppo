<?php
/**
 * =====================================================================
 * SPPO-IS SHARED ADMIN/STAFF PAGE FOOTER
 * =====================================================================
 * Purpose: Closes the admin layout HTML and renders the footer for
 *          all admin and priest pages.
 * =====================================================================
 */

// Reuse the subfolder-safe prefix computed by the page / admin-header.php.
$basePath = $basePath ?? base_path();
?>
</main>
</div>
<footer class="site-footer">
    <p>&copy; <?= date('Y') ?> St. Patrick's Parish Office — Parish System Administration.</p>
</footer>
<!-- Site-wide scripts (vanilla JS helpers; loaded last, after the DOM is ready) -->
<?php $mainJsVersion = file_exists(__DIR__ . '/../assets/js/main.js') ? filemtime(__DIR__ . '/../assets/js/main.js') : time(); ?>
<script src="<?= $basePath ?>/assets/js/main.js?v=<?= (int) $mainJsVersion ?>"></script>
</body>
</html>