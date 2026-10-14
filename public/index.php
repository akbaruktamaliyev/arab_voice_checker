<?php
/**
 * QRaft — landing page with the free static QR generator.
 * Works without an account; generation happens entirely in the browser.
 */

declare(strict_types=1);
require __DIR__ . '/../src/bootstrap.php';

$title = 'Free QR Code Generator';
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
                    <option value="url">Website link</option>
                    <option value="text">Plain text</option>
                    <option value="email">Email</option>
                    <option value="phone">Phone number</option>
                    <option value="sms">SMS</option>
                    <option value="wifi">Wi-Fi network</option>
                    <option value="vcard">Contact card (vCard)</option>
                    <option value="whatsapp">WhatsApp</option>
                    <option value="geo">Location (GPS)</option>
                    <option value="event">Calendar event</option>
                    <option value="crypto">Crypto payment</option>
                </select>
            </div>
            <div id="qr-fields"></div>
            <div class="field">
                <label for="qr-size"><?= e(t('gen.size')) ?></label>
                <select id="qr-size">
                    <option value="192">Small (192px)</option>
                    <option value="256" selected>Medium (256px)</option>
                    <option value="384">Large (384px)</option>
                    <option value="512">Extra large (512px)</option>
                </select>
            </div>
            <div class="row">
                <div class="field">
                    <label for="qr-dots"><?= e(t('gen.dotstyle')) ?></label>
                    <select id="qr-dots">
                        <option value="square">Square</option>
                        <option value="rounded" selected>Rounded</option>
                        <option value="dots">Dots</option>
                        <option value="classy">Classy</option>
                        <option value="classy-rounded">Classy rounded</option>
                        <option value="extra-rounded">Extra rounded</option>
                    </select>
                </div>
                <div class="field">
                    <label for="qr-eyes"><?= e(t('gen.eyestyle')) ?></label>
                    <select id="qr-eyes">
                        <option value="square">Square</option>
                        <option value="extra-rounded" selected>Rounded</option>
                        <option value="dot">Dot</option>
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
                <input type="file" id="qr-logo" accept="image/*">
                <button type="button" id="qr-logo-clear" class="btn btn-ghost" hidden
                        style="margin-top:8px"><?= e(t('gen.removelogo')) ?></button>
            </div>
        </div>

        <div class="card stack center">
            <div class="qr-preview" id="qr-holder"></div>
            <span class="muted" id="qr-hint"><?= e(t('gen.hint')) ?></span>
            <div class="row">
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

<script src="<?= e(url('assets/js/qr-code-styling.js')) ?>"></script>
<script src="<?= e(url('assets/js/app.js')) ?>"></script>
<?php
$content = ob_get_clean();
require __DIR__ . '/../src/layout.php';
