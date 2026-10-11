<?php

/**
 * Paradise Studios — owner layout (closing).
 *
 * Closes the page container and renders the scripts the page needs.
 *
 * Expected variables (optional):
 *   $pageVendorScripts  array  vendor files from public/vendor
 *   $pageScripts        array  page modules from public/js
 *   $pageInlineScript   string raw JS executed last, receives page bootstrap
 *                       data via window.PARADISE_PAGE
 */

$pageVendorScripts = $pageVendorScripts ?? [];
$pageScripts       = $pageScripts ?? [];
$pageInlineScript  = $pageInlineScript ?? '';

/* Scripts shared by every owner page, in dependency order. */
$psSharedScripts = [
    'js/theme.js',
    'js/api.js',
    'js/ui.js',
    'js/charts.js',
    'js/shell.js'
];

?>
            </div><!-- /.ps-page -->
        </main>

        <!-- ======================= BOTTOM NAVIGATION ======================= -->
        <nav class="ps-bottomnav" aria-label="Primary">
            <?php foreach ($ownerNav as $item): ?>
                <?php $isActive = ($item['key'] === ($ownerPage ?? '')); ?>
                <a class="ps-bottomnav__link<?= $isActive ? ' is-active' : '' ?>"
                   href="<?= e($item['href']) ?>"
                    <?= $isActive ? 'aria-current="page"' : '' ?>>
                    <?= ps_icon($item['icon'], 19) ?>
                    <span class="ps-bottomnav__label"><?= e($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

    </div><!-- /.ps-main -->
</div><!-- /.ps-shell -->

<div class="ps-toasts" data-ps-toasts role="status" aria-live="polite"></div>

<?php foreach ($pageVendorScripts as $vendor): ?>
    <script src="<?= e(ps_asset($vendor)) ?>"></script>
<?php endforeach; ?>

<?php foreach (array_merge($psSharedScripts, $pageScripts) as $script): ?>
    <script src="<?= e(ps_asset($script)) ?>"></script>
<?php endforeach; ?>

<?php if ($pageInlineScript !== ''): ?>
    <script><?= $pageInlineScript ?></script>
<?php endif; ?>

</body>
</html>