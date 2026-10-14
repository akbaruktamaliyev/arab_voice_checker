/* QRaft — client-side QR generator (landing page).
 * Builds the encoded string from the selected type and renders it with
 * qr-code-styling (dots, eye styles, gradients, centre logo). Everything
 * happens in the browser; nothing is uploaded. */
(function () {
    'use strict';

    var holder = document.getElementById('qr-holder');
    if (!holder || typeof QRCodeStyling === 'undefined') return;

    var typeSel   = document.getElementById('qr-type');
    var fieldsBox = document.getElementById('qr-fields');
    var sizeSel   = document.getElementById('qr-size');
    var fgInput   = document.getElementById('qr-fg');
    var bgInput   = document.getElementById('qr-bg');
    var dotsSel   = document.getElementById('qr-dots');
    var eyesSel   = document.getElementById('qr-eyes');
    var gradChk   = document.getElementById('qr-gradient');
    var fg2Input  = document.getElementById('qr-fg2');
    var logoInput = document.getElementById('qr-logo');
    var logoClear = document.getElementById('qr-logo-clear');
    var pngBtn    = document.getElementById('qr-download-png');
    var svgBtn    = document.getElementById('qr-download-svg');

    var logoDataUrl = null;
    var qr = null;

    var TYPES = {
        url:   [{ name: 'url', label: 'Website URL', type: 'url', ph: 'https://example.com' }],
        text:  [{ name: 'text', label: 'Text', type: 'text', ph: 'Any text you like' }],
        email: [
            { name: 'to', label: 'Email address', type: 'email', ph: 'hello@example.com' },
            { name: 'subject', label: 'Subject', type: 'text', ph: 'Optional' },
            { name: 'body', label: 'Message', type: 'text', ph: 'Optional' }
        ],
        phone: [{ name: 'phone', label: 'Phone number', type: 'text', ph: '+998 90 123 45 67' }],
        sms:   [
            { name: 'phone', label: 'Phone number', type: 'text', ph: '+998 90 123 45 67' },
            { name: 'text', label: 'Message', type: 'text', ph: 'Optional' }
        ],
        wifi:  [
            { name: 'ssid', label: 'Network name (SSID)', type: 'text', ph: 'MyWiFi' },
            { name: 'password', label: 'Password', type: 'text', ph: '' },
            { name: 'enc', label: 'Security', type: 'select', options: ['WPA', 'WEP', 'nopass'] }
        ],
        vcard: [
            { name: 'name', label: 'Full name', type: 'text', ph: 'John Doe' },
            { name: 'org', label: 'Company', type: 'text', ph: 'Optional' },
            { name: 'phone', label: 'Phone', type: 'text', ph: 'Optional' },
            { name: 'email', label: 'Email', type: 'email', ph: 'Optional' },
            { name: 'url', label: 'Website', type: 'text', ph: 'Optional' }
        ],
        whatsapp: [
            { name: 'phone', label: 'Phone (with country code, no +)', type: 'text', ph: '998901234567' },
            { name: 'text', label: 'Message', type: 'text', ph: 'Optional' }
        ],
        geo: [
            { name: 'lat', label: 'Latitude', type: 'text', ph: '41.311081' },
            { name: 'lng', label: 'Longitude', type: 'text', ph: '69.240562' }
        ],
        event: [
            { name: 'summary', label: 'Event title', type: 'text', ph: 'Meeting' },
            { name: 'location', label: 'Location', type: 'text', ph: 'Optional' },
            { name: 'start', label: 'Starts', type: 'datetime' },
            { name: 'end', label: 'Ends', type: 'datetime' }
        ],
        crypto: [
            { name: 'coin', label: 'Coin', type: 'select', options: ['bitcoin', 'ethereum', 'litecoin'] },
            { name: 'address', label: 'Wallet address', type: 'text', ph: 'Address' },
            { name: 'amount', label: 'Amount', type: 'text', ph: 'Optional' }
        ]
    };

    function icalTime(v) { return (v || '').replace(/[-:]/g, '') + '00'; }
    function esc(v) { return (v || '').replace(/([\\;,:"])/g, '\\$1'); }

    function buildValue() {
        var t = typeSel.value;
        var g = function (n) {
            var el = fieldsBox.querySelector('[name="' + n + '"]');
            return el ? el.value.trim() : '';
        };
        switch (t) {
            case 'url':   return g('url');
            case 'text':  return g('text');
            case 'phone': return g('phone') ? 'tel:' + g('phone') : '';
            case 'email':
                if (!g('to')) return '';
                return 'mailto:' + g('to') + '?subject=' + encodeURIComponent(g('subject')) +
                    '&body=' + encodeURIComponent(g('body'));
            case 'sms':   return g('phone') ? 'SMSTO:' + g('phone') + ':' + g('text') : '';
            case 'wifi':
                if (!g('ssid')) return '';
                return 'WIFI:T:' + g('enc') + ';S:' + esc(g('ssid')) + ';P:' + esc(g('password')) + ';;';
            case 'vcard':
                if (!g('name')) return '';
                return 'BEGIN:VCARD\nVERSION:3.0\nFN:' + g('name') + '\n' +
                    (g('org') ? 'ORG:' + g('org') + '\n' : '') +
                    (g('phone') ? 'TEL:' + g('phone') + '\n' : '') +
                    (g('email') ? 'EMAIL:' + g('email') + '\n' : '') +
                    (g('url') ? 'URL:' + g('url') + '\n' : '') + 'END:VCARD';
            case 'whatsapp':
                if (!g('phone')) return '';
                return 'https://wa.me/' + g('phone').replace(/[^0-9]/g, '') +
                    (g('text') ? '?text=' + encodeURIComponent(g('text')) : '');
            case 'geo':
                return (g('lat') && g('lng')) ? 'geo:' + g('lat') + ',' + g('lng') : '';
            case 'event':
                if (!g('summary') || !g('start')) return '';
                return 'BEGIN:VCALENDAR\nVERSION:2.0\nBEGIN:VEVENT\nSUMMARY:' + g('summary') + '\n' +
                    (g('location') ? 'LOCATION:' + g('location') + '\n' : '') +
                    'DTSTART:' + icalTime(g('start')) + '\n' +
                    (g('end') ? 'DTEND:' + icalTime(g('end')) + '\n' : '') +
                    'END:VEVENT\nEND:VCALENDAR';
            case 'crypto':
                return (g('coin') && g('address'))
                    ? g('coin') + ':' + g('address') + (g('amount') ? '?amount=' + g('amount') : '')
                    : '';
        }
        return '';
    }

    function size() { return parseInt(sizeSel.value, 10) || 256; }

    function dotsColor() {
        if (gradChk.checked) {
            return {
                gradient: {
                    type: 'linear', rotation: 0.79,
                    colorStops: [{ offset: 0, color: fgInput.value }, { offset: 1, color: fg2Input.value }]
                }
            };
        }
        return { color: fgInput.value };
    }

    function options(data) {
        var o = {
            width: size(), height: size(), data: data, margin: 10,
            qrOptions: { errorCorrectionLevel: logoDataUrl ? 'H' : 'M' },
            backgroundOptions: { color: bgInput.value },
            dotsOptions: Object.assign({ type: dotsSel.value }, dotsColor()),
            cornersSquareOptions: { type: eyesSel.value, color: fgInput.value }
        };
        if (logoDataUrl) {
            o.image = logoDataUrl;
            o.imageOptions = { margin: 4, imageSize: 0.22, crossOrigin: 'anonymous' };
        }
        return o;
    }

    function setDownloads(enabled) {
        [pngBtn, svgBtn].forEach(function (b) {
            if (enabled) b.removeAttribute('disabled'); else b.setAttribute('disabled', 'disabled');
        });
    }

    // Show the placeholder (or an error) instead of a QR code.
    function showHint(message) {
        var hint = document.getElementById('qr-hint');
        if (qr) { holder.innerHTML = ''; qr = null; }
        if (hint) {
            hint.textContent = message || hint.getAttribute('data-default') || '';
            hint.style.display = '';
        }
        setDownloads(false);
    }

    function render() {
        var value = buildValue();
        var hint = document.getElementById('qr-hint');
        if (!value) { showHint(); return; }
        try {
            if (!qr) {
                qr = new QRCodeStyling(options(value));
                qr.append(holder);
            } else {
                qr.update(options(value));
            }
        } catch (err) {
            // e.g. "code length overflow" when the content is too long for a QR.
            showHint(hint && hint.getAttribute('data-too-long') || 'That content is too long for a QR code.');
            return;
        }
        if (hint) hint.style.display = 'none';
        setDownloads(true);
    }

    function buildFields() {
        var defs = TYPES[typeSel.value] || [];
        fieldsBox.innerHTML = '';
        defs.forEach(function (d) {
            var wrap = document.createElement('div');
            wrap.className = 'field';
            var lab = document.createElement('label');
            lab.textContent = d.label;
            wrap.appendChild(lab);
            var input;
            if (d.type === 'select') {
                input = document.createElement('select');
                d.options.forEach(function (o) {
                    var opt = document.createElement('option');
                    opt.value = o; opt.textContent = o === 'nopass' ? 'No password' : o;
                    input.appendChild(opt);
                });
            } else {
                input = document.createElement('input');
                input.type = d.type === 'datetime' ? 'datetime-local' : d.type;
                input.placeholder = d.ph || '';
            }
            input.name = d.name;
            input.addEventListener('input', render);
            input.addEventListener('change', render);
            wrap.appendChild(input);
            fieldsBox.appendChild(wrap);
        });
        render();
    }

    typeSel.addEventListener('change', buildFields);
    [sizeSel, fgInput, bgInput, fg2Input, dotsSel, eyesSel].forEach(function (el) {
        el.addEventListener('change', render);
        el.addEventListener('input', render);
    });
    gradChk.addEventListener('change', function () {
        document.getElementById('qr-fg2-wrap').style.display = gradChk.checked ? '' : 'none';
        render();
    });

    if (logoInput) {
        logoInput.addEventListener('change', function () {
            var file = logoInput.files && logoInput.files[0];
            if (!file) return;
            if (!/^image\//.test(file.type) || file.size > 1024 * 1024) {
                alert(logoInput.getAttribute('data-error') || 'Please choose an image file under 1 MB.');
                logoInput.value = ''; return;
            }
            var reader = new FileReader();
            reader.onload = function (ev) {
                logoDataUrl = ev.target.result;
                if (logoClear) logoClear.hidden = false;
                render();
            };
            reader.readAsDataURL(file);
        });
    }
    if (logoClear) {
        logoClear.addEventListener('click', function () {
            logoDataUrl = null;
            if (logoInput) logoInput.value = '';
            logoClear.hidden = true;
            render();
        });
    }

    pngBtn.addEventListener('click', function () {
        if (qr) qr.download({ name: 'qr-' + typeSel.value, extension: 'png' });
    });
    svgBtn.addEventListener('click', function () {
        if (qr) qr.download({ name: 'qr-' + typeSel.value, extension: 'svg' });
    });

    buildFields();
})();
