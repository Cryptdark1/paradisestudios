<?php

/**
 * Paradise Studios — artist layout (closing).
 *
 * Closes the page container and renders the scripts the page needs. The shared
 * script list is identical to the Owner Frontend, which is what guarantees a
 * single theme implementation and a single API helper across the product.
 *
 * Expected variables (optional):
 *   $pageVendorScripts  array  vendor files from public/vendor
 *   $pageScripts        array  page modules from public/js
 */

$pageVendorScripts = $pageVendorScripts ?? [];
$pageScripts       = $pageScripts ?? [];

/*
 * Scripts shared by every artist page, in dependency order.
 *
 * artist-api.js is a thin layer over api.js that adds the artist endpoints.
 * It is listed here (not per page) so no artist page can load it without its
 * base helper. theme.js / api.js / ui.js / charts.js / shell.js are the exact
 * same files the Owner Frontend uses — one theme implementation, one API
 * helper, one component kit across the product.
 */
$psSharedScripts = [
    'js/theme.js',
    'js/api.js',
    'js/ui.js',
    'js/charts.js',
    'js/artist-api.js',
    'js/shell.js'
];

?>
            </div><!-- /.ps-page -->
        </main>

        <!-- ======================= BOTTOM NAVIGATION =======================
             Five slots with a raised circular centre action, as in the
             prototype. `bottom` in ps_artist_nav() decides which pages appear
             here and which one gets the centre button.
             ============================================================== -->
        <?php $bottomNav = array_values(array_filter($artistNav, static function (array $i): bool {
            return !empty($i['bottom']);
        })); ?>
        <nav class="ps-bottomnav" aria-label="Primary">
            <?php foreach ($bottomNav as $item): ?>
                <?php
                $isActive = ($item['key'] === ($artistPage ?? ''));
                $isFab    = ($item['bottom'] === 'fab');
                ?>
                <a class="ps-bottomnav__link<?= $isActive ? ' is-active' : '' ?><?= $isFab ? ' ps-bottomnav__link--fab' : '' ?>"
                   href="<?= e($item['href']) ?>"
                    <?= $isActive ? 'aria-current="page"' : '' ?>
                    <?= $isFab ? 'aria-label="' . e($item['label']) . '"' : '' ?>>
                    <span class="ps-bottomnav__icon"><?= ps_icon($item['icon'], $isFab ? 22 : 19) ?></span>
                    <span class="ps-bottomnav__label"><?= e($item['short']) ?></span>
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

</body>
</html>