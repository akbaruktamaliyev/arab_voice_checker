/* QRaft — shared QR renderer.
 * Builds the QR module matrix once with qrcode-generator (MIT), then draws it
 * either to a <canvas> (for PNG export) or to an SVG string (for vector
 * export), with custom foreground/background colours and a quiet-zone margin.
 */
(function (global) {
    'use strict';

    var MARGIN = 4; // quiet-zone, in modules (QR spec recommends 4)

    function build(text, ecc) {
        var qr = qrcode(0, ecc || 'M'); // type 0 = auto-fit the data
        qr.addData(text);
        qr.make();
        return qr;
    }

    function toCanvas(qr, size, fg, bg) {
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
        return canvas;
    }

    function toSvg(qr, size, fg, bg) {
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
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' + size + '" height="' + size +
            '" viewBox="0 0 ' + total + ' ' + total + '" shape-rendering="crispEdges">' +
            '<rect width="' + total + '" height="' + total + '" fill="' + bg + '"/>' +
            '<path d="' + parts.join('') + '" fill="' + fg + '"/></svg>';
    }

    // Trigger a browser download of a data/blob URL.
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
        paint: function (holder, text, size, fg, bg, ecc) {
            holder.innerHTML = '';
            var qr = build(text, ecc);
            holder.appendChild(toCanvas(qr, size, fg, bg));
            return qr;
        },
        downloadPng: function (qr, size, fg, bg, name) {
            download(toCanvas(qr, size, fg, bg).toDataURL('image/png'), name + '.png', false);
        },
        downloadSvg: function (qr, size, fg, bg, name) {
            var blob = new Blob([toSvg(qr, size, fg, bg)], { type: 'image/svg+xml' });
            download(URL.createObjectURL(blob), name + '.svg', true);
        }
    };
})(window);
