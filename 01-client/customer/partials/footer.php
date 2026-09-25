<?php
/**
 * Slate — customer-facing shell footer.
 * Closes the layout opened by partials/header.php.
 */
if (!defined('SLATE_ROOT')) exit;
$customerPageVariant = $customerPageVariant ?? 'auth';
?>
<?php if ($customerPageVariant === 'dashboard'): ?>
    </main>
<?php elseif ($customerPageVariant === 'auth-split'): ?>
            <?php /* Kohevo platform signature — the auth-split card's tenant brand
                     is shown once, at the top, in partials/header.php; this closes
                     the same card at the bottom, after whatever form the calling
                     page (customer/login.php, register.php, ...) placed inside
                     .auth-form-inner — mirroring where admin/login.php places its
                     own platform signature (last thing in the card). Routed
                     entirely through PlatformSignature/PlatformIdentity; never a
                     hardcoded asset path or literal name here. */ ?>
            <p class="auth-platform-signature">
                <?= \Slate\Services\Content\PlatformSignature::render(\Slate\Services\Content\PlatformSignature::MODE_SIGNATURE) ?>
            </p>
            </div><!-- .auth-form-inner -->
        </main><!-- .auth-form-panel -->
    </div><!-- .auth-split -->
<?php else: ?>
    </div>
<?php endif; ?>
<?php slate_form_validation_i18n_script(); ?>
</body>
</html>
