<?php
/**
 * =====================================================================
 * SPPO-IS SHARED PUBLIC PAGE FOOTER
 * =====================================================================
 * Purpose: Closes the public layout: ends <main>, renders the parish
 *          footer line, and loads the site-wide JavaScript last so the
 *          DOM is ready (no defer/async bookkeeping needed).
 * =====================================================================
 */
// Reuse the subfolder-safe prefix computed in header.php when present.
$basePath = $basePath ?? base_path();
?>
</main>
<!-- Parish contact / attribution line -->
<footer class="site-footer">
    <p>&copy; <?= date('Y') ?> St. Patrick's Parish Office, Dingalan, Aurora.</p>
</footer>
<?php $mainJsVersion = file_exists(__DIR__ . '/../assets/js/main.js') ? filemtime(__DIR__ . '/../assets/js/main.js') : time(); ?>
<!-- Site-wide scripts (vanilla JS helpers only; loaded last) -->
<script src="<?= $basePath ?>/assets/js/main.js?v=<?= (int) $mainJsVersion ?>"></script>
</body>
</html>
