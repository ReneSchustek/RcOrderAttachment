// Testet die Prüf- und Anzeigelogik des Upload-Plugins, ohne einen Browser zu brauchen:
// die clientseitige Vorprüfung (Endung, Dateigröße, Anzahl, Gesamtgröße), die Übersetzung
// der Server-Fehlercodes und die Größenformatierung.
//
// Warum überhaupt: Bis v1.0.6 lieferte dieses Plugin Storefront-JS aus, ohne dass eine
// einzige Zeile davon getestet war — der Gate meldete `[SKIP] Kein tests/Js`. Genau dieser
// Code entscheidet, ob ein Kunde seine Datei anhängen kann.
//
// Der Quelltext wird gelesen und in einer Stub-Umgebung ausgewertet, damit das Plugin
// unverändert als Modul buildbar bleibt (dasselbe Vorgehen wie im Theme).

import { describe, test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const __dirname = dirname(fileURLToPath(import.meta.url));
const quellpfad = join(
    __dirname, '..', '..',
    'src', 'Resources', 'app', 'storefront', 'src', 'plugin',
    'rc-order-attachment-upload', 'rc-order-attachment-upload.plugin.js',
);

const roh = readFileSync(quellpfad, 'utf8');
const ohneImporte = roh
    .replace(/^import [^\n]*\n/gm, '')
    .replace(/^export default /m, '');

const UploadPlugin = new Function(`
    class Plugin {
        init() {}
        destroy() {}
    }
    ${ohneImporte}
    return RcOrderAttachmentUploadPlugin;
`)();

/**
 * Baut eine Instanz ohne DOM: nur die Felder, welche die geprüften Methoden lesen.
 * `_showError` wird ersetzt, damit der Test die gezeigte Meldung sieht.
 */
function pluginMit(felder = {}) {
    const p = Object.create(UploadPlugin.prototype);

    p._allowedExtensions = felder.allowedExtensions ?? ['pdf', 'jpg'];
    p._maxFileSizeBytes = felder.maxFileSizeBytes ?? 1024 * 1024;
    p._maxFiles = felder.maxFiles ?? 3;
    p._maxTotalSizeBytes = felder.maxTotalSizeBytes ?? 4 * 1024 * 1024;
    p._texts = { generic: 'Allgemeiner Fehler' };
    p._errorMessages = felder.errorMessages ?? {
        extensionNotAllowed: 'Endung nicht erlaubt',
        fileSizeExceeded: 'Datei zu gross',
        fileCountExceeded: 'Zu viele Dateien',
        totalSizeExceeded: 'Gesamtgröße überschritten',
    };

    p.gezeigterFehler = null;
    p._showError = (nachricht) => { p.gezeigterFehler = nachricht; };
    p._currentItemCount = () => felder.itemCount ?? 0;
    p._currentTotalSize = () => felder.totalSize ?? 0;

    return p;
}

const datei = (name, size) => ({ name, size });

describe('Vorprüfung im Browser', () => {
    test('lässt eine erlaubte Datei durch', () => {
        const p = pluginMit();
        assert.equal(p._preValidate(datei('zeichnung.pdf', 1000)), true);
        assert.equal(p.gezeigterFehler, null);
    });

    test('weist eine nicht erlaubte Endung ab und nennt den Grund', () => {
        const p = pluginMit();
        assert.equal(p._preValidate(datei('schadcode.exe', 10)), false);
        assert.equal(p.gezeigterFehler, 'Endung nicht erlaubt');
    });

    test('prüft die Endung ohne Rücksicht auf Groß- und Kleinschreibung', () => {
        const p = pluginMit();
        assert.equal(p._preValidate(datei('Zeichnung.PDF', 10)), true);
    });

    test('weist eine Datei ohne Endung ab, wenn eine Whitelist gesetzt ist', () => {
        const p = pluginMit();
        assert.equal(p._preValidate(datei('dateiohnepunkt', 10)), false);
    });

    test('weist eine zu große Datei ab', () => {
        const p = pluginMit({ maxFileSizeBytes: 500 });
        assert.equal(p._preValidate(datei('gross.pdf', 501)), false);
        assert.equal(p.gezeigterFehler, 'Datei zu gross');
    });

    test('lässt eine Datei exakt auf der Größengrenze durch', () => {
        const p = pluginMit({ maxFileSizeBytes: 500 });
        assert.equal(p._preValidate(datei('grenze.pdf', 500)), true);
    });

    test('weist ab, wenn die erlaubte Anzahl schon erreicht ist', () => {
        const p = pluginMit({ maxFiles: 2, itemCount: 2 });
        assert.equal(p._preValidate(datei('dritte.pdf', 10)), false);
        assert.equal(p.gezeigterFehler, 'Zu viele Dateien');
    });

    test('weist ab, wenn die Gesamtgröße überschritten würde', () => {
        const p = pluginMit({ maxTotalSizeBytes: 1000, totalSize: 900 });
        assert.equal(p._preValidate(datei('rest.pdf', 200)), false);
        assert.equal(p.gezeigterFehler, 'Gesamtgröße überschritten');
    });

    test('ohne gesetzte Grenzen wird nichts abgewiesen', () => {
        const p = pluginMit({
            allowedExtensions: [], maxFileSizeBytes: 0, maxFiles: 0, maxTotalSizeBytes: 0,
        });
        assert.equal(p._preValidate(datei('beliebig.xyz', 99999999)), true);
    });
});

describe('Fehlercodes des Servers', () => {
    test('übersetzt bekannte Codes in ihre Meldung', () => {
        const p = pluginMit();
        assert.equal(p._formatErrorCodes(['fileSizeExceeded']), 'Datei zu gross');
    });

    test('verbindet mehrere Codes sichtbar', () => {
        const p = pluginMit();
        assert.equal(
            p._formatErrorCodes(['extensionNotAllowed', 'fileSizeExceeded']),
            'Endung nicht erlaubt • Datei zu gross',
        );
    });

    test('zeigt bei leerer Liste die allgemeine Meldung', () => {
        const p = pluginMit();
        assert.equal(p._formatErrorCodes([]), 'Allgemeiner Fehler');
        assert.equal(p._formatErrorCodes(null), 'Allgemeiner Fehler');
    });

    test('gibt einen unbekannten Code unverändert aus, statt ihn zu verschlucken', () => {
        const p = pluginMit();
        assert.equal(p._formatErrorCodes(['sessionUnavailable']), 'sessionUnavailable');
    });
});

describe('Größenanzeige', () => {
    test('rechnet in B, KB und MB um', () => {
        const p = pluginMit();
        assert.equal(p._formatFileSize(512), '512 B');
        assert.equal(p._formatFileSize(2048), '2.0 KB');
        assert.equal(p._formatFileSize(5 * 1024 * 1024), '5.0 MB');
    });

    test('wechselt exakt an der Grenze die Einheit', () => {
        const p = pluginMit();
        assert.equal(p._formatFileSize(1023), '1023 B');
        assert.equal(p._formatFileSize(1024), '1.0 KB');
    });
});
