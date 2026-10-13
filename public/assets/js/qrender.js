/* QRaft — shared QR renderer.
 * Builds the QR module matrix once with qrcode-generator (MIT), then draws it
 * to a <canvas> (PNG export) or an SVG string (vector export), with custom
 * colours, a quiet-zone margin and an optional centre logo.
 *
 * When a logo is used the matrix must be built with error-correction level 'H'
 * so the code still scans with its centre covered — callers pass ecc='H' then.
 */
(function (global) {
    'use strict';

    var MARGIN = 4;       // quiet-zone, in modules (QR spec recommends 4)
    var LOGO_RATIO = 0.22; // logo side as a fraction of the QR side

    function build(text, ecc) {
        var qr = qrcode(0, ecc || 'M'); // type 0 = auto-fit the data
        qr.addData(text);
        qr.make();
        return qr;
    }

    function toCanvas(qr, size, fg, bg, logoImg) {
        var count = qr.getModuleCount();
        var total = count + MARGIN * 2;
        var cell = Math.max(1, Math.floor(size / total));
        var dim = cell * total;

        var canvas = document.createElement('canvas');
        canvas.width = canvas.height = dim;
        var ctx = canvas.getContext('2d');

        ctx.fillStyle = bg;
        ctx.fillRect(0, 0, dim, dim);
        ctx.fillStyle = fg;
        for (var r = 0; r < count; r++) {
            for (var c = 0; c < count; c++) {
                if (qr.isDark(r, c)) {
                    ctx.fillRect((c + MARGIN) * cell, (r + MARGIN) * cell, cell, cell);
                }
            }
        }

        if (logoImg) {
            var logo = Math.round(dim * LOGO_RATIO);
            var pad = Math.round(logo * 0.14);
            var box = logo + pad * 2;
            var pos = Math.round((dim - box) / 2);
            // Clear a padded panel behind the logo so modules don't bleed through.
            ctx.fillStyle = bg;
            ctx.fillRect(pos, pos, box, box);
            try {
                ctx.drawImage(logoImg, pos + pad, pos + pad, logo, logo);
            } catch (e) { /* ignore a broken image */ }
        }
        return canvas;
    }

    function toSvg(qr, size, fg, bg, logoDataUrl) {
        var count = qr.getModuleCount();
        var total = count + MARGIN * 2;
        var parts = [];
        for (var r = 0; r < count; r++) {
            for (var c = 0; c < count; c++) {
                if (qr.isDark(r, c)) {
                    parts.push('M' + (c + MARGIN) + ',' + (r + MARGIN) + 'h1v1h-1z');
                }
            }
        }
        var svg = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" ' +
            'width="' + size + '" height="' + size + '" viewBox="0 0 ' + total + ' ' + total +
            '" shape-rendering="crispEdges">' +
            '<rect width="' + total + '" height="' + total + '" fill="' + bg + '"/>' +
            '<path d="' + parts.join('') + '" fill="' + fg + '"/>';

        if (logoDataUrl) {
            var logo = total * LOGO_RATIO;
            var pad = logo * 0.14;
            var box = logo + pad * 2;
            var pos = (total - box) / 2;
            svg += '<rect x="' + pos + '" y="' + pos + '" width="' + box + '" height="' + box +
                '" fill="' + bg + '"/>' +
                '<image x="' + (pos + pad) + '" y="' + (pos + pad) + '" width="' + logo +
                '" height="' + logo + '" preserveAspectRatio="xMidYMid meet" ' +
                'href="' + logoDataUrl + '" xlink:href="' + logoDataUrl + '"/>';
        }
        return svg + '</svg>';
    }

    function download(href, filename, revoke) {
        var a = document.createElement('a');
        a.href = href;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        if (revoke) { setTimeout(function () { URL.revokeObjectURL(href); }, 1000); }
    }

    global.QRaftRender = {
        build: build,
        toCanvas: toCanvas,
        toSvg: toSvg,
        /** Render into a holder element (clears it first). Returns the matrix. */
        paint: function (holder, text, size, fg, bg, ecc, logoImg) {
            holder.innerHTML = '';
            var qr = build(text, ecc);
            holder.appendChild(toCanvas(qr, size, fg, bg, logoImg));
            return qr;
        },
        downloadPng: function (qr, size, fg, bg, name, logoImg) {
            download(toCanvas(qr, size, fg, bg, logoImg).toDataURL('image/png'), name + '.png', false);
        },
        downloadSvg: function (qr, size, fg, bg, name, logoDataUrl) {
            var blob = new Blob([toSvg(qr, size, fg, bg, logoDataUrl)], { type: 'image/svg+xml' });
            download(URL.createObjectURL(blob), name + '.svg', true);
        }
    };
})(window);
