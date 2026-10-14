# Third-party components

QRaft bundles these browser libraries (in `public/assets/js/`). They are not modified
and keep their own licences. All are MIT-licensed.

| Library | Used for | Author | Licence |
|---------|----------|--------|---------|
| **qr-code-styling** (`qr-code-styling.js`) | The free generator: dot/eye styles, gradients, centre logo, PNG/SVG download | Denys Kozak | MIT |
| **qrcode-generator** (`qrcode-generator.min.js`) | Rendering of dynamic-code pages (PNG/SVG/PDF) | Kazuhiko Arase | MIT |
| **jsPDF** (`jspdf.umd.min.js`, v2.5.2) | PDF export of a code (loaded only when the button is clicked) | James Hall and contributors | MIT |

jsPDF's file header lists the copyright holders of the code it contains; keep that header
when you redistribute the file. The MIT licence requires that the copyright notice and
permission notice stay with the software — the headers inside the files do this, and this
document points to them.

Everything else in the product — the PHP application, the stylesheet and the other
JavaScript — is original work covered by `LICENSE.txt`.
