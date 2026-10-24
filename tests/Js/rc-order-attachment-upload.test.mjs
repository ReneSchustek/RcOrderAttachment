// Testet die Prüf- und Anzeigelogik des Upload-Plugins ohne Browser: die Vorprüfung im
// Browser (Endung, Dateigröße, Anzahl, Gesamtgröße), die Übersetzung der Fehlercodes des
// Servers und die Größenanzeige. Dieser Code entscheidet, ob ein Kunde seine Datei anhängen kann.
//
// Der Quelltext wird gelesen und mit einer Ersatzklasse `Plugin` ausgewertet, damit die Datei
// unverändert als Modul baubar bleibt; das Theme testet sein JavaScript auf dieselbe Weise.

import { describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const sourcePath = join(
    __dirname, '..', '..',
    'src', 'Resources', 'app', 'storefront', 'src', 'plugin',
    'rc-order-attachment-upload', 'rc-order-attachment-upload.plugin.js',
);

const source = readFileSync(sourcePath, 'utf8');
const withoutImports = source
    .replace(/^import [^\n]*\n/gm, '')
    .replace(/^export default /m, '');

const UploadPlugin = new Function(`
    class Plugin {
        init() {}
        destroy() {}
    }
    ${withoutImports}
    return RcOrderAttachmentUploadPlugin;
`)();

/**
 * Baut eine Instanz ohne DOM: nur die Felder, welche die geprüften Methoden lesen.
 * `_addError` wird ersetzt, damit der Test die gezeigte Meldung sieht.
 */
function makePlugin(fields = {}) {
    const p = Object.create(UploadPlugin.prototype);

    p._allowedExtensions = fields.allowedExtensions ?? ['pdf', 'jpg'];
    p._maxFileSizeBytes = fields.maxFileSizeBytes ?? 1024 * 1024;
    p._maxFiles = fields.maxFiles ?? 3;
    p._maxTotalSizeBytes = fields.maxTotalSizeBytes ?? 4 * 1024 * 1024;
    p._texts = { generic: 'Allgemeiner Fehler' };
    p._errorMessages = fields.errorMessages ?? {
        extensionNotAllowed: 'Endung nicht erlaubt',
        fileSizeExceeded: 'Datei zu gross',
        fileCountExceeded: 'Zu viele Dateien',
        totalSizeExceeded: 'Gesamtgröße überschritten',
    };

    p.shownError = null;
    p._addError = (selected, message) => { p.shownError = message; };
    p._currentItemCount = () => fields.itemCount ?? 0;
    p._currentTotalSize = () => fields.totalSize ?? 0;

    return p;
}

const file = (name, size) => ({ name, size });

describe('Vorprüfung im Browser', () => {
    test('lässt eine erlaubte Datei durch', () => {
        const p = makePlugin();
        assert.equal(p._preValidate(file('zeichnung.pdf', 1000)), true);
        assert.equal(p.shownError, null);
    });

    test('weist eine nicht erlaubte Endung ab und nennt den Grund', () => {
        const p = makePlugin();
        assert.equal(p._preValidate(file('schadcode.exe', 10)), false);
        assert.equal(p.shownError, 'Endung nicht erlaubt');
    });

    test('prüft die Endung ohne Rücksicht auf Groß- und Kleinschreibung', () => {
        const p = makePlugin();
        assert.equal(p._preValidate(file('Zeichnung.PDF', 10)), true);
    });

    test('weist eine Datei ohne Endung ab, wenn eine Whitelist gesetzt ist', () => {
        const p = makePlugin();
        assert.equal(p._preValidate(file('dateiohnepunkt', 10)), false);
    });

    test('weist eine zu große Datei ab', () => {
        const p = makePlugin({ maxFileSizeBytes: 500 });
        assert.equal(p._preValidate(file('gross.pdf', 501)), false);
        assert.equal(p.shownError, 'Datei zu gross');
    });

    // Die Grenze ist einschließlich gemeint, wie in der Serverprüfung; ein `>=` wiese
    // genau die erlaubte Höchstgröße ab.
    test('lässt eine Datei exakt auf der Größengrenze durch', () => {
        const p = makePlugin({ maxFileSizeBytes: 500 });
        assert.equal(p._preValidate(file('grenze.pdf', 500)), true);
    });

    test('weist ab, wenn die erlaubte Anzahl schon erreicht ist', () => {
        const p = makePlugin({ maxFiles: 2, itemCount: 2 });
        assert.equal(p._preValidate(file('dritte.pdf', 10)), false);
        assert.equal(p.shownError, 'Zu viele Dateien');
    });

    test('weist ab, wenn die Gesamtgröße überschritten würde', () => {
        const p = makePlugin({ maxTotalSizeBytes: 1000, totalSize: 900 });
        assert.equal(p._preValidate(file('rest.pdf', 200)), false);
        assert.equal(p.shownError, 'Gesamtgröße überschritten');
    });

    // 0 und eine leere Liste bedeuten „keine Grenze", nicht „nichts erlaubt".
    test('ohne gesetzte Grenzen wird nichts abgewiesen', () => {
        const p = makePlugin({
            allowedExtensions: [], maxFileSizeBytes: 0, maxFiles: 0, maxTotalSizeBytes: 0,
        });
        assert.equal(p._preValidate(file('beliebig.xyz', 99999999)), true);
    });
});

describe('Fehlercodes des Servers', () => {
    test('übersetzt bekannte Codes in ihre Meldung', () => {
        const p = makePlugin();
        assert.equal(p._formatErrorCodes(['fileSizeExceeded']), 'Datei zu gross');
    });

    test('verbindet mehrere Codes sichtbar', () => {
        const p = makePlugin();
        assert.equal(
            p._formatErrorCodes(['extensionNotAllowed', 'fileSizeExceeded']),
            'Endung nicht erlaubt • Datei zu gross',
        );
    });

    test('zeigt bei leerer Liste die allgemeine Meldung', () => {
        const p = makePlugin();
        assert.equal(p._formatErrorCodes([]), 'Allgemeiner Fehler');
        assert.equal(p._formatErrorCodes(null), 'Allgemeiner Fehler');
    });

    test('gibt einen unbekannten Code unverändert aus, statt ihn zu verschlucken', () => {
        const p = makePlugin();
        assert.equal(p._formatErrorCodes(['sessionUnavailable']), 'sessionUnavailable');
    });
});

describe('Größenanzeige', () => {
    test('rechnet in B, KB und MB um', () => {
        const p = makePlugin();
        assert.equal(p._formatFileSize(512), '512 B');
        assert.equal(p._formatFileSize(2048), '2.0 KB');
        assert.equal(p._formatFileSize(5 * 1024 * 1024), '5.0 MB');
    });

    test('wechselt exakt an der Grenze die Einheit', () => {
        const p = makePlugin();
        assert.equal(p._formatFileSize(1023), '1023 B');
        assert.equal(p._formatFileSize(1024), '1.0 KB');
    });
});

describe('Fehler eines Durchgangs', () => {
    // Ein Ersatz für das Fehlerfeld: hält die Zeilen und den Sichtbarkeitszustand fest.
    const errorField = () => ({
        lines: [],
        hidden: true,
        replaceChildren(...children) { this.lines = children.map((child) => child.textContent); },
        classList: { toggle(name, force) { errorField.current.hidden = force; } },
    });

    const withErrorField = () => {
        const p = Object.create(UploadPlugin.prototype);
        p._errors = [];
        p._errorEl = errorField();
        errorField.current = p._errorEl;
        globalThis.document = { createElement: () => ({ textContent: '' }) };

        return p;
    };

    test('mehrere abgewiesene Dateien stehen zusammen da, je mit Namen', () => {
        const p = withErrorField();
        p._addError(file('plan.exe', 10), 'Endung nicht erlaubt');
        p._addError(file('foto.jpg', 99), 'Datei zu groß');

        assert.deepEqual(p._errorEl.lines, ['plan.exe: Endung nicht erlaubt', 'foto.jpg: Datei zu groß']);
        assert.equal(p._errorEl.hidden, false);
    });

    test('eine neue Auswahl leert die Fehler des vorigen Durchgangs', () => {
        const p = withErrorField();
        p._addError(file('plan.exe', 10), 'Endung nicht erlaubt');
        p._clearError();

        assert.deepEqual(p._errorEl.lines, []);
        assert.equal(p._errorEl.hidden, true);
    });
});
