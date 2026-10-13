/* QRaft — client-side QR generator.
 * Builds the encoded string from the selected type, renders it locally with
 * qrcode.min.js (no data leaves the browser) and powers the PNG download. */
(function () {
    'use strict';

    var holder = document.getElementById('qr-holder');
    if (!holder) return;

    var typeSel   = document.getElementById('qr-type');
    var fieldsBox = document.getElementById('qr-fields');
    var download  = document.getElementById('qr-download');
    var sizeSel   = document.getElementById('qr-size');

    var qr = null;

    // Field definitions per QR type.
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
        ]
    };

    function esc(v) { return (v || '').replace(/([\\;,:"])/g, '\\$1'); }

    // Turn the current field values into the string encoded in the QR.
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
        }
        return '';
    }

    function render() {
        var value = buildValue();
        holder.innerHTML = '';
        if (!value) {
            holder.innerHTML = '<span class="muted">Fill in the fields to see your QR code</span>';
            download.setAttribute('disabled', 'disabled');
            return;
        }
        var size = parseInt(sizeSel.value, 10) || 256;
        qr = new QRCode(holder, {
            text: value,
            width: size,
            height: size,
            correctLevel: QRCode.CorrectLevel.M
        });
        download.removeAttribute('disabled');
    }

    // Build the input fields for the selected type.
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
                input.type = d.type;
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

    // Download the rendered QR as a PNG.
    function downloadPng() {
        var img = holder.querySelector('img');
        var canvas = holder.querySelector('canvas');
        var dataUrl = img ? img.src : (canvas ? canvas.toDataURL('image/png') : null);
        if (!dataUrl) return;
        var a = document.createElement('a');
        a.href = dataUrl;
        a.download = 'qr-' + typeSel.value + '.png';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
    }

    typeSel.addEventListener('change', buildFields);
    sizeSel.addEventListener('change', render);
    download.addEventListener('click', downloadPng);

    buildFields();
})();
