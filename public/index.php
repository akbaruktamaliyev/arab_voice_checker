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
    <h1>Create QR codes in seconds</h1>
    <p>Generate QR codes for links, Wi-Fi, vCards and more — free, instantly,
       right in your browser. Create an account for dynamic codes with scan analytics.</p>
</section>

<section class="section">
    <div class="grid-2">
        <div class="card stack">
            <div class="field">
                <label for="qr-type">What should the QR contain?</label>
                <select id="qr-type">
                    <option value="url">Website link</option>
                    <option value="text">Plain text</option>
                    <option value="email">Email</option>
                    <option value="phone">Phone number</option>
                    <option value="sms">SMS</option>
                    <option value="wifi">Wi-Fi network</option>
                    <option value="vcard">Contact card (vCard)</option>
                </select>
            </div>
            <div id="qr-fields"></div>
            <div class="field">
                <label for="qr-size">Size</label>
                <select id="qr-size">
                    <option value="192">Small (192px)</option>
                    <option value="256" selected>Medium (256px)</option>
                    <option value="384">Large (384px)</option>
                    <option value="512">Extra large (512px)</option>
                </select>
            </div>
            <div class="row">
                <div class="field">
                    <label for="qr-fg">QR colour</label>
                    <input type="color" id="qr-fg" value="#000000">
                </div>
                <div class="field">
                    <label for="qr-bg">Background</label>
                    <input type="color" id="qr-bg" value="#ffffff">
                </div>
            </div>
            <div class="field">
                <label for="qr-logo">Centre logo <span class="muted">(optional)</span></label>
                <input type="file" id="qr-logo" accept="image/*">
                <button type="button" id="qr-logo-clear" class="btn btn-ghost" hidden
                        style="margin-top:8px">Remove logo</button>
            </div>
        </div>

        <div class="card stack center">
            <div class="qr-preview" id="qr-holder">
                <span class="muted">Fill in the fields to see your QR code</span>
            </div>
            <div class="row">
                <button type="button" id="qr-download-png" class="btn btn-primary" disabled>Download PNG</button>
                <button type="button" id="qr-download-svg" class="btn btn-ghost" disabled>Download SVG</button>
            </div>
            <p class="muted" style="font-size:.85rem">
                Everything is generated on your device — nothing is uploaded.
            </p>
        </div>
    </div>
</section>

<section class="section center">
    <div class="card">
        <h2>Want to track scans?</h2>
        <p class="muted">Dynamic QR codes let you change the destination later and
           see how many times each code was scanned.</p>
        <?php if (current_user_id() === null): ?>
            <a class="btn btn-primary" href="<?= e(url('register.php')) ?>">Create a free account</a>
        <?php else: ?>
            <a class="btn btn-primary" href="<?= e(url('dashboard.php')) ?>">Go to your dashboard</a>
        <?php endif; ?>
    </div>
</section>

<script src="<?= e(url('assets/js/qrcode-generator.min.js')) ?>"></script>
<script src="<?= e(url('assets/js/qrender.js')) ?>"></script>
<script src="<?= e(url('assets/js/app.js')) ?>"></script>
<?php
$content = ob_get_clean();
require __DIR__ . '/../src/layout.php';
