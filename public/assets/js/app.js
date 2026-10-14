/* QRaft — client-side QR generator (landing page).
 * Builds the encoded string from the selected type and renders it locally with
 * the shared QRaftRender module (no data leaves the browser). Supports custom
 * colours and PNG/SVG download. */
(function () {
    'use strict';

    var holder = document.getElementById('qr-holder');
    if (!holder || !window.QRaftRender) return;

    var typeSel   = document.getElementById('qr-type');
    var fieldsBox = document.getElementById('qr-fields');
    var sizeSel   = document.getElementById('qr-size');
    var fgInput   = document.getElementById('qr-fg');
    var bgInput   = document.getElementById('qr-bg');
    var logoInput = document.getElementById('qr-logo');
    var logoClear = document.getElementById('qr-logo-clear');
    var pngBtn    = document.getElementById('qr-download-png');
    var svgBtn    = document.getElementById('qr-download-svg');

    var current = null;      // last built matrix
    var logoImg = null;      // loaded logo Image, or null
    var logoDataUrl = null;  // logo as a data: URL, for SVG export

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

    // "2026-01-02T15:04" -> "20260102T150400" for iCalendar.
    function icalTime(v) { return (v || '').replace(/[-:]/g, '').replace('T', 'T') + '00'; }

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
                return 'mailto:' + g('to') +
                    '?subject=' + encodeURIComponent(g('subject')) +
                    '&body=' + encodeURIComponent(g('body'));
            case 'sms':   return g('phone') ? 'SMSTO:' + g('phone') + ':' + g('text') : '';
            case 'wifi':
                if (!g('ssid')) return '';
                return 'WIFI:T:' + g('enc') + ';S:' + esc(g('ssid')) +
                    ';P:' + esc(g('password')) + ';;';
            case 'vcard':
                if (!g('name')) return '';
                return 'BEGIN:VCARD\nVERSION:3.0\n' +
                    'FN:' + g('name') + '\n' +
                    (g('org')   ? 'ORG:' + g('org') + '\n' : '') +
                    (g('phone') ? 'TEL:' + g('phone') + '\n' : '') +
                    (g('email') ? 'EMAIL:' + g('email') + '\n' : '') +
                    (g('url')   ? 'URL:' + g('url') + '\n' : '') +
                    'END:VCARD';
            case 'whatsapp':
                if (!g('phone')) return '';
                return 'https://wa.me/' + g('phone').replace(/[^0-9]/g, '') +
                    (g('text') ? '?text=' + encodeURIComponent(g('text')) : '');
            case 'geo':
                return (g('lat') && g('lng')) ? 'geo:' + g('lat') + ',' + g('lng') : '';
            case 'event':
                if (!g('summary') || !g('start')) return '';
                return 'BEGIN:VCALENDAR\nVERSION:2.0\nBEGIN:VEVENT\n' +
                    'SUMMARY:' + g('summary') + '\n' +
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

    // Higher error correction when a logo covers the centre, so it still scans.
    function ecc() { return logoImg ? 'H' : 'M'; }

    function render() {
        var value = buildValue();
        if (!value) {
            holder.innerHTML = '<span class="muted">Fill in the fields to see your QR code</span>';
            current = null;
            pngBtn.setAttribute('disabled', 'disabled');
            svgBtn.setAttribute('disabled', 'disabled');
            return;
        }
        try {
            current = QRaftRender.paint(holder, value, size(), fgInput.value, bgInput.value, ecc(), logoImg);
            pngBtn.removeAttribute('disabled');
            svgBtn.removeAttribute('disabled');
        } catch (err) {
            holder.innerHTML = '<span class="muted">That content is too long for one QR code.</span>';
            current = null;
            pngBtn.setAttribute('disabled', 'disabled');
            svgBtn.setAttribute('disabled', 'disabled');
        }
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
                    opt.value = o;
                    opt.textContent = o === 'nopass' ? 'No password' : o;
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
    [sizeSel, fgInput, bgInput].forEach(function (el) {
        el.addEventListener('change', render);
        el.addEventListener('input', render);
    });

    // Logo upload — handled entirely in the browser, never sent anywhere.
    if (logoInput) {
        logoInput.addEventListener('change', function () {
            var file = logoInput.files && logoInput.files[0];
            if (!file) return;
            if (!/^image\//.test(file.type) || file.size > 1024 * 1024) {
                alert('Please choose an image file under 1 MB.');
                logoInput.value = '';
                return;
            }
            var reader = new FileReader();
            reader.onload = function (ev) {
                logoDataUrl = ev.target.result;
                var img = new Image();
                img.onload = function () { logoImg = img; if (logoClear) logoClear.hidden = false; render(); };
                img.src = logoDataUrl;
            };
            reader.readAsDataURL(file);
        });
    }
    if (logoClear) {
        logoClear.addEventListener('click', function () {
            logoImg = null; logoDataUrl = null;
            if (logoInput) logoInput.value = '';
            logoClear.hidden = true;
            render();
        });
    }

    pngBtn.addEventListener('click', function () {
        if (current) QRaftRender.downloadPng(current, size(), fgInput.value, bgInput.value, 'qr-' + typeSel.value, logoImg);
    });
    svgBtn.addEventListener('click', function () {
        if (current) QRaftRender.downloadSvg(current, size(), fgInput.value, bgInput.value, 'qr-' + typeSel.value, logoDataUrl);
    });

    buildFields();
})();
