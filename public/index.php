<?php
/**
 * QRaft — landing page with the free static QR generator.
 * Works without an account; generation happens entirely in the browser.
 */

declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$title = t('hero.page_title');
ob_start();
?>
<section class="hero">
    <h1><?= e(t('hero.title')) ?></h1>
    <p><?= e(t('hero.subtitle')) ?></p>
</section>

<section class="section">
    <div class="grid-2">
        <div class="card stack">
            <div class="field">
                <label for="qr-type"><?= e(t('gen.contains')) ?></label>
                <select id="qr-type">
                    <option value="url"><?= e(t('gen.t.url')) ?></option>
                    <option value="text"><?= e(t('gen.t.text')) ?></option>
                    <option value="email"><?= e(t('gen.t.email')) ?></option>
                    <option value="phone"><?= e(t('gen.t.phone')) ?></option>
                    <option value="sms"><?= e(t('gen.t.sms')) ?></option>
                    <option value="wifi"><?= e(t('gen.t.wifi')) ?></option>
                    <option value="vcard"><?= e(t('gen.t.vcard')) ?></option>
                    <option value="whatsapp"><?= e(t('gen.t.whatsapp')) ?></option>
                    <option value="geo"><?= e(t('gen.t.geo')) ?></option>
                    <option value="event"><?= e(t('gen.t.event')) ?></option>
                    <option value="crypto"><?= e(t('gen.t.crypto')) ?></option>
                </select>
            </div>
            <div id="qr-fields"></div>
            <div class="field">
                <label for="qr-size"><?= e(t('gen.size')) ?></label>
                <select id="qr-size">
                    <option value="192"><?= e(t('gen.size.small')) ?></option>
                    <option value="256" selected><?= e(t('gen.size.medium')) ?></option>
                    <option value="384"><?= e(t('gen.size.large')) ?></option>
                    <option value="512"><?= e(t('gen.size.xl')) ?></option>
                </select>
            </div>
            <div class="row">
                <div class="field">
                    <label for="qr-dots"><?= e(t('gen.dotstyle')) ?></label>
                    <select id="qr-dots">
                        <option value="square"><?= e(t('gen.dot.square')) ?></option>
                        <option value="rounded" selected><?= e(t('gen.dot.rounded')) ?></option>
                        <option value="dots"><?= e(t('gen.dot.dots')) ?></option>
                        <option value="classy"><?= e(t('gen.dot.classy')) ?></option>
                        <option value="classy-rounded"><?= e(t('gen.dot.classy-rounded')) ?></option>
                        <option value="extra-rounded"><?= e(t('gen.dot.extra-rounded')) ?></option>
                    </select>
                </div>
                <div class="field">
                    <label for="qr-eyes"><?= e(t('gen.eyestyle')) ?></label>
                    <select id="qr-eyes">
                        <option value="square"><?= e(t('gen.eye.square')) ?></option>
                        <option value="extra-rounded" selected><?= e(t('gen.eye.rounded')) ?></option>
                        <option value="dot"><?= e(t('gen.eye.dot')) ?></option>
                    </select>
                </div>
            </div>
            <div class="row">
                <div class="field">
                    <label for="qr-fg"><?= e(t('gen.qrcolour')) ?></label>
                    <input type="color" id="qr-fg" value="#000000">
                </div>
                <div class="field">
                    <label for="qr-bg"><?= e(t('gen.background')) ?></label>
                    <input type="color" id="qr-bg" value="#ffffff">
                </div>
            </div>
            <label style="font-weight:400"><input type="checkbox" id="qr-gradient" style="width:auto"> <?= e(t('gen.gradient')) ?></label>
            <div class="field" id="qr-fg2-wrap" style="display:none">
                <label for="qr-fg2"><?= e(t('gen.gradient2')) ?></label>
                <input type="color" id="qr-fg2" value="#7c3aed">
            </div>
            <div class="field">
                <label for="qr-logo"><?= e(t('gen.logo')) ?> <span class="muted"><?= e(t('gen.optional')) ?></span></label>
                <input type="file" id="qr-logo" accept="image/*" data-error="<?= e(t('gen.logo_err')) ?>">
                <button type="button" id="qr-logo-clear" class="btn btn-ghost" hidden
                        style="margin-top:8px"><?= e(t('gen.removelogo')) ?></button>
            </div>
        </div>

        <div class="card stack center">
            <div class="qr-preview">
                <div id="qr-holder"></div>
                <span class="muted" id="qr-hint" data-default="<?= e(t('gen.hint')) ?>" data-too-long="<?= e(t('gen.too_long')) ?>"><?= e(t('gen.hint')) ?></span>
            </div>
            <div class="row nowrap">
                <button type="button" id="qr-download-png" class="btn btn-primary" disabled><?= e(t('gen.download_png')) ?></button>
                <button type="button" id="qr-download-svg" class="btn btn-ghost" disabled><?= e(t('gen.download_svg')) ?></button>
            </div>
            <p class="muted" style="font-size:.85rem">
                <?= e(t('gen.privacy')) ?>
            </p>
        </div>
    </div>
</section>

<section class="section center">
    <div class="card">
        <h2><?= e(t('gen.track_title')) ?></h2>
        <p class="muted"><?= e(t('gen.track_text')) ?></p>
        <?php if (current_user_id() === null): ?>
            <a class="btn btn-primary" href="<?= e(url('register.php')) ?>"><?= e(t('gen.create_account')) ?></a>
        <?php else: ?>
            <a class="btn btn-primary" href="<?= e(url('dashboard.php')) ?>"><?= e(t('gen.go_dashboard')) ?></a>
        <?php endif; ?>
    </div>
</section>

<?php
// Field captions for the generator script (see TYPES in assets/js/app.js).
$genStrings = [];
foreach ($GLOBALS['lang'] as $k => $v) {
    if (str_starts_with($k, 'gen.f.')) { $genStrings[substr($k, 6)] = $v; }
}
?>
<script>window.QRAFT_I18N = <?= json_encode($genStrings, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="<?= e(url('assets/js/qr-code-styling.js')) ?>"></script>
<script src="<?= e(url('assets/js/app.js')) ?>"></script>
<?php
$content = ob_get_clean();
require __DIR__ . '/../src/layout.php';
