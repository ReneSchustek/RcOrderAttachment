# Changelog

## [1.0.7] - 2026-07-30 — Der Medienordner wird über seine Kennung wiedererkannt

### Behoben
- **Ein umbenannter Medienordner legte Aufräumen und Deinstallation still.** Der Ordner für die Kunden-Uploads wurde ausschließlich über seinen Namen gesucht — im Medien-Manager ist er ein ganz normaler Ordner, den jemand umbenennen kann. Danach fand das Plugin ihn nicht mehr: Verwaiste Uploads blieben liegen, obwohl die Aufbewahrungsfrist etwas anderes verspricht, und beim Deinstallieren blieben Ordner samt privater Kundendateien zurück. Die Kennung des Ordners wird jetzt bei der Anlage vermerkt und zuerst gelesen; die Suche über den Namen bleibt als Rückfall und trägt den Vermerk für bestehende Installationen nach.

## [1.0.6] - 2026-07-30 — Zwei Fehler im Upload-Weg behoben, JavaScript unter Test

### Behoben
- **Die Fehlermeldung bei Pflicht-Upload war keine.** Ist „Upload Pflichtfeld" aktiv und bestellt jemand ohne Datei, stand im roten Kasten der rohe Schlüssel `checkout.rc-order-attachment-required` statt eines Satzes. Es gab für diesen Fall schlicht keinen Text — jetzt auf Deutsch und Englisch, und im Frontend nachgeprüft.
- **Ein Upload ohne Session meldete Erfolg, obwohl die Datei verloren war.** Ohne Sitzung kann eine hochgeladene Datei keiner Bestellung zugeordnet werden; sie lag als verwaistes Medium herum, bis der Cleanup sie Stunden später entfernte. Der Kunde sah derweil einen Haken. Der Upload wird jetzt abgelehnt, das bereits gespeicherte Medium sofort wieder gelöscht und der Grund genannt.

### Geändert
- **Der Cleanup meldet einen fehlenden Medienordner jetzt als Warnung** statt auf `debug`. Der Ordner wird über seinen Namen gesucht — wird er im Medien-Manager umbenannt, räumt der Cleanup nichts mehr ab. Das war vorher unsichtbar, obwohl die Aufbewahrungsfrist etwas anderes verspricht.
- **Der Hilfetext zur Aufbewahrungsfrist sagt jetzt, was der Code tut:** Gelöscht wird ab dem Upload und unabhängig vom Bestellstatus. Vorher stand dort „Anhänge abgeschlossener Bestellungen", was eine Einschränkung suggerierte, die es nie gab.

### Getestet
- **15 JavaScript-Tests** für das Upload-Plugin — die clientseitige Vorprüfung (Endung, Größe, Anzahl, Gesamtgröße mit ihren Grenzfällen), die Übersetzung der Server-Fehlercodes und die Größenanzeige. Bisher lief für dieses ausgelieferte JavaScript kein einziger Test; der Gate meldete `[SKIP]`.
- Der Upload-Weg ohne Sitzung ist abgesichert, damit der Fall nicht zurückkehrt.

## [1.0.5] - 2026-07-30 — Auslieferbar ohne Node

### Hinzugefügt
- **Das gebaute Storefront-JS und das Admin-Bundle liegen jetzt im Repo.** Beides stand bisher in der `.gitignore`. Auf den Entwicklungs-Instanzen fiel das nicht auf, weil sie selbst bauen können — auf einem Server ohne Node wäre das Upload-Feld auf der Bestellbestätigung ohne sein JavaScript angekommen: ein Feld, das nichts tut, und das merkt man erst, wenn ein Kunde eine Datei anhängen will. Die Installation braucht damit keinen Build mehr.
- **Rauchtest**: vier echte Requests bis zur Bestellbestätigung, die prüfen, dass das Upload-Feld dort ankommt und die Upload-Route korrekt verdrahtet ist.

### Hinweis zur Versionsgeschichte
Die Fassungen 1.0.1 bis 1.0.4 liefen ausschließlich auf den Entwicklungs-Instanzen; ihre Änderungen stehen unten in diesem Abschnitt gesammelt. Erst 1.0.5 ist für die Auslieferung gedacht.

## Gesammelte Änderungen der Fassungen 1.0.1 bis 1.0.4

### Hinzugefügt

- **Anhang-Ansicht in der Admin-Bestellung.** Der Detail-Tab einer Bestellung zeigt jetzt eine Card „Bestell-Anhänge" mit den Customer-Uploads (Dateiname, Größe, Typ, Upload-Zeit) und je Anhang einem Download. Bisher kam der Betrieb nur über die Bestätigungs-Mail oder den Kunden-Account an das Dokument. Die Datei ist privates Media; der Download läuft über den admin-authentifizierten Endpoint `GET /api/_action/rc-order-attachment/{attachmentId}/download` mit ACL `order:read` — der Media-Stream selbst im System-Scope, die Zugangskontrolle allein an der Route. Read-only: Hochladen bleibt der Storefront-Confirm-Page, Löschen dem Retention-/Orphan-Cleanup vorbehalten. Nicht gefundene bzw. bereits vom Cleanup entfernte Anhänge liefern denselben 404 (kein Existenz-Disclosure).

### Getestet

- **Die Auswahl der Aufräum-Routinen ist jetzt an echten Daten geprüft.** Beide Routinen löschen hochgeladene Kundendateien — bisher war nachgewiesen, was sie mit einem Ergebnis tun, aber nicht, welche Dateien ihre Abfragen überhaupt auswählen. Elf neue Tests gegen eine echte Datenbank decken das ab: die Stichtags-Grenze in beide Richtungen, dass an Bestellungen gebundene Dateien nie erfasst werden, dass Dateien anderer Bereiche des Shops unangetastet bleiben, und dass eine von mehreren Positionen geteilte Datei erhalten bleibt, solange eine gültige Bestellung sie braucht. Jede Schutzbedingung wurde testweise ausgehebelt, um zu belegen, dass die Tests sie wirklich bewachen.

### Behoben — Sicherheit und Robustheit

- **Entfernen-Schaltfläche auf Touch-Geräten vergrößert.** Sie sitzt direkt neben dem Dateinamen und war mit 24 × 24 px so klein, dass ein Fehlgriff auf dem Handy leicht passiert — und ein Fehlgriff **löscht den Anhang**. Auf Geräten mit Finger- statt Mausbedienung ist die Fläche jetzt 44 × 44 px (WCAG 2.5.5). Am Rechner bleibt die Darstellung unverändert kompakt.
- **Der Wiederholversuch für fehlgeschlagene Mail-Anhänge wartete nicht.** Die Funktion sollte einen fehlgeschlagenen Anhang bis zu fünfmal erneut versuchen, mit wachsenden Abständen von einer bis acht Minuten — damit der Speicher Zeit hat, sich zu erholen. Tatsächlich liefen alle fünf Versuche **im selben Vorgang innerhalb von Millisekunden** ab, weil die Aufgabe nie in die Warteschlange gelegt, sondern sofort abgearbeitet wurde. Jeder reale Ausfall verbrauchte damit augenblicklich alle Versuche. Die Aufgaben werden jetzt korrekt eingereiht; gemessen: Wartezeit von 60 Sekunden eingehalten, Anhang danach erfolgreich nachgetragen. **Voraussetzung ist ein laufender Worker** — das steht jetzt auch in der README.
- **Jedes Update des installierten Plugins schlug fehl.** `plugin:update` endete mit einer Datenbank-Fehlermeldung, weil die Zuordnung der Zusatzfelder zur Bestellung bei jedem Aufruf neu angelegt statt aktualisiert wurde. Betroffen war jede Installation, auf der das Plugin bereits lief. Bestehende Zuordnungen werden jetzt vor dem Schreiben bereinigt; die Daten in den Bestellungen bleiben unberührt.
- **Deinstallation brach ab, bevor die Kundendaten gelöscht wurden.** `plugin:uninstall` endete mit einer Container-Fehlermeldung — und zwar in der ersten Zeile der Aufräumroutine, also bevor die Tabelle fiel, bevor die Zusatzfelder verschwanden und bevor die privaten Kunden-Uploads gelöscht wurden. Wer das Plugin auf ein Löschersuchen hin entfernte, glaubte aufgeräumt zu haben und hatte es nicht; nebenbei ließ sich das Plugin überhaupt nicht mehr entfernen. Ursache war eine Service-Kennung, die es in dieser Form nicht gibt. Alle Container-Zugriffe im Installations- und Deinstallations-Weg laufen jetzt über eine Abfrage, die einen Fehlgriff protokolliert statt den Vorgang abzubrechen.
- **Die Protokollierung der Aufräum-Fehler lief ins Leere.** Sie war ausdrücklich dagegen eingebaut worden, dass zurückbleibende Kundendaten unbemerkt liegen — griff aber nie, weil auch sie eine nicht abrufbare Kennung verwendete. Der Zugriff versucht jetzt mehrere bekannte Kennungen der Reihe nach.
- **Endungs-Whitelist wirkte shopweit statt nur auf Plugin-Uploads.** Shopware fragt die erlaubten Datei-Endungen über ein Event ab, das bei **jedem** Media-Upload feuert und kein Merkmal für die Herkunft trägt. Der `MediaWhitelistSubscriber` erweiterte die Liste deshalb bedingungslos: Eine in der Plugin-Konfiguration eingetragene Sonder-Endung (`dwg`, `zip`, …) war anschließend im gesamten Shop erlaubt, auch für öffentliche Medien — die Plugin-Konfiguration wurde zur Shop-Konfiguration. Der Upload-Service markiert seinen Context jetzt mit `OrderAttachmentUploadScope` und entfernt den Marker nach dem Speichern wieder; der Subscriber erweitert nur bei gesetztem Marker und lässt die Core-Whitelist andernfalls unangetastet (fail-closed, auch wenn das Event ohne Context ankommt). Der Subscriber selbst bleibt nötig — der Core-Validator läuft auch auf dem Plugin-Pfad.
- **Fail-open in der Media-Endungs-Whitelist.** `MediaWhitelistSubscriber` merged die admin-konfigurierten `allowedExtensions` in die Core-Media-Whitelist, ohne die Blacklist gefährlicher Endungen anzuwenden — eine per Config gesetzte Endung (`svg`, `html`, `php`, …) wäre so global für private Media freigegeben worden, ohne je den `DangerousContentValidator` zu durchlaufen. Die Blacklist greift jetzt an der Quelle (`PluginConfigProvider::parseExtensions`), gefährliche Endungen werden vor jeder Weitergabe verworfen.
- **`plugin:update` brach ab (Idempotenz).** Das genestete Status-Custom-Field hatte keine feste ID; da `update()` bei jedem Plugin-Update `install()` (upsert) aufruft, hätte der Writer eine neue Zufalls-UUID vergeben und wäre gegen die UNIQUE-Constraint `custom_field.name` gelaufen (Duplicate-Key). Feld hat jetzt eine deterministische ID; Alt-Instanzen unter Zufalls-UUID werden vor dem Upsert reconciled (Order-Daten bleiben unberührt — sie liegen per Feld-Name, nicht per ID).
- **DSGVO-Fail-open beim Deinstallieren.** Fehler beim Löschen der privaten Customer-Upload-Media wurden im `uninstall()` still verschluckt (leerer catch) mit der falschen Begründung, der Orphan-Cron räume nach — dieser plugin-eigene Cron ist zu dem Zeitpunkt aber schon deregistriert. Fehler werden jetzt mit Kontext geloggt, damit zurückbleibende PII-Reste diagnostizierbar sind.
- **Mail-Whitelist fiel bei nur-ungültiger Eingabe fail-open** auf „an alle Order-Mails anhängen" (Mail-Inflation). Jetzt fail-closed auf den Default `order_confirmation_mail`. Die config.xml-Hilfe wurde an das reale Verhalten angeglichen.
- **Retry-Handler verlor die Teilzustellungs-Information.** Bei erschöpften Versuchen wurde pauschal `failed` gesetzt, auch wenn nur ein Teil der Anhänge unlesbar blieb. Jetzt `partial_failure`, wenn `0 < Fehler < Gesamt`.
- **Entfernen eines Anhangs verschwieg Server-Fehler.** Das Storefront-JS entfernte das DOM-Element im `fetch().then()` ohne `response.ok`-Prüfung (fetch rejectet nicht bei HTTP 4xx/5xx) — eine serverseitig fehlgeschlagene Löschung sah im UI trotzdem wie erfolgreich aus. Jetzt wird nur bei 2xx entfernt, sonst Fehler angezeigt.
- **Test-Vakuität geschlossen:** ein Test beweist jetzt, dass der Customer-Download den DAL-Owner-Filter (`order.orderCustomer.customerId`) tatsächlich setzt (IDOR-Schutz), plus Abdeckung des `partial_failure`-Zweigs und der Blacklist-Filterung.
- **EXIF-Strip überschrieb die Datei nicht mehr in-place.** Ein Schreibfehler mitten im GD-Re-Encode (z. B. Disk voll) hätte die Original-Upload-Datei verstümmelt und ein korruptes Media gespeichert. Jetzt wird in eine temporäre Datei geschrieben und erst bei Erfolg per `rename()` über das Original gezogen; bei Fehler bleibt das Original unangetastet.
- **Ungültiger Dateiname lieferte HTTP 500 statt 422.** Ein Dateiname, der nach Sanitisierung leer ist (nur Steuerzeichen/Bidi-Marker), löste im `FilenameSanitizer` eine `InvalidAttachmentPayloadException` aus, die der Upload-Controller als Server-Fehler behandelte. Wird jetzt als Eingabe-Fehler (422) beantwortet.
- **Upload-XHR ohne Timeout.** Eine hängende Verbindung blockierte die sequenzielle Upload-Queue dauerhaft. Jetzt 120-s-Timeout mit Fehleranzeige.
- **Test:** neuer `MediaWhitelistSubscriberTest` sichert den sicherheitsrelevanten Whitelist-Merge inkl. Nachweis, dass geblacklistete Endungen die Core-Whitelist nie erreichen.
- **Negative-Caching im `MailTemplateTypeResolver`.** Ein transienter Lookup-Fehler wurde als endgültiges `null` gecacht — für den Rest des Requests/der Worker-Nachricht wären dann alle Anhänge ausgeblieben. Jetzt wird nur das echte Ergebnis (gefunden/nicht gefunden) gecacht, Fehler propagieren ungecacht.
- **Falsche Datei-Größe nach EXIF-Strip.** `getSize()` las über den PHP-Stat-Cache den Pre-Strip-Wert; nach dem Re-Encode wird der Cache jetzt invalidiert (`clearstatcache`), sodass Media/PendingUpload die tatsächliche Größe tragen.
- **Async-Race in der Admin-Anhang-Liste.** Ein Order-Wechsel während einer laufenden Suche konnte eine ältere Antwort über die neuere schreiben. Sequenz-Guard ergänzt.
- **Fokusverlust nach Entfernen eines Anhangs (WCAG 2.4.3).** Der Fokus wird jetzt auf das Datei-Input zurückgesetzt statt an den Seitenanfang zu springen.
- **Pflicht-Upload blockierte sessionlose Bestellkontexte.** Bei `required=true` verhinderte der Cart-Validator Bestellungen ohne Storefront-Session (Store-API/Headless/manuelle Admin-Bestellung) dauerhaft — dort existiert die Confirm-Page-Upload-Möglichkeit gar nicht. Die Erzwingung greift jetzt nur im Session-Kontext (Storefront-Checkout).
- **Fehlende Fehlermeldung für den Pflicht-Upload.** Der Cart-Error `rc-order-attachment-required` hatte kein Storefront-Snippet — der Kunde hätte den rohen Key gesehen. Snippet `error.rc-order-attachment-required` in de-DE + en-GB ergänzt.

### Hinzugefügt (Resilienz)

- **Attach-Retry beim Order-Placement.** Scheitert das Verknüpfen der Customer-Uploads mit der Bestellung komplett (z. B. transienter DB-Fehler), wären die Dokumente sonst still für die Order verloren. Neu: `RetryOrderAttachmentLinkMessage` + idempotenter Handler (überspringt bereits verlinkte Media, Exponential-Backoff 60 s → 1 h, `MAX_ATTEMPTS = 5`, danach finaler Error-Log für die manuelle Nacharbeit). Voraussetzung: aktiver Worker.

### Behoben — kritisch

- **Datenverlust an abgeschlossenen Bestellungen.** Die Pending-Liste in der Session wurde nach dem Order-Placement nie geleert: `CheckoutOrderPlacedSubscriber::onFinishPageLoaded()` stieg hinter einem `snapshotTaken`-Guard aus, und Order-Placement (`POST /checkout/order`) und Finish-Page (`GET /checkout/finish`) sind zwei getrennte Requests — das Flag war im zweiten immer `false`. Folge: Die Datei blieb „pending", die Confirm-Page des nächsten Warenkorbs zeigte sie mit gültigem Remove-Token. Ein Klick auf „Entfernen" löschte das Media, und der Fremdschlüssel `fk.rc_order_attachment.media_id` (`ON DELETE CASCADE`) riss den Anhang der **bereits abgeschlossenen** Bestellung mit — Mail-Status blieb auf `attached`, die Datei war weg. Ohne Entfernen wurde dieselbe Datei bei der nächsten Bestellung erneut angehängt und verschickt. Die Session-Liste wird jetzt unmittelbar im Order-Placement geleert (der Snapshot im Arbeitsspeicher versorgt weiterhin alle Teil-Orders eines Cart-Splits). Zusätzlich verweigert `OrderAttachmentUploadService::remove()` das Löschen von Media, das bereits an eine Bestellung gebunden ist — fail-closed auch bei DAL-Fehlern.
- **Checkout-Confirm-Page brach mit HTTP 500 (zweite, unabhängige Ursache).** Das Template baute die Remove-URL mit dem Platzhalter `__TOKEN__`, die Route erzwingt aber `token` = 32 Hex-Zeichen. Der Symfony-URL-Generator warf eine `InvalidParameterException` und riss die gesamte Seite mit. Nur beim tatsächlichen Rendern sichtbar — `lint:twig` kann das nicht finden. Der Platzhalter erfüllt jetzt die Route-Requirements und wird im JS über ein `data`-Attribut ersetzt.
- **Checkout-Confirm-Page brach mit HTTP 500.** Das Confirm-Template rief `sw_csrf()` auf. Shopware hat den CSRF-Layer mit 6.5 ersatzlos entfernt; eine unbekannte Twig-Funktion ist ein Compile-Fehler, der die gesamte Seite reißt — nicht nur die Plugin-Sektion. `sw_csrf` samt CSRF-Token aus Twig, JS und Snippets entfernt. Cross-Site-Schutz liegt seit 6.5 beim `SameSite=Lax`-Session-Cookie; die Endpoints bleiben POST-only, Remove-Tokens sind session-gebunden.
- **Anhänge wurden nie an die Bestell-Bestätigungs-Mail gehängt.** Der Whitelist-Abgleich prüfte `templateData['mailTemplate']` — diesen Key setzt Shopware nicht. Der Fallback verglich den Flow-Event-Namen `checkout.order.placed` gegen den Whitelist-Default `order_confirmation_mail` und traf nie. Neu: `MailTemplateTypeResolver` löst `data['templateId']` zum `technicalName` des Mail-Template-Types auf; verglichen wird gegen Template-Type UND Flow-Event-Name. Fail-closed, wenn der Typ unbekannt bleibt. Abgesichert durch einen Integration-Test gegen echte DAL, echten Media-Storage und das echte Mail-Template.

### Behoben

- `ExifStripper` nutzte den `@`-Error-Suppression-Operator. Ersetzt durch einen eng gefassten `set_error_handler`/`restore_error_handler`-Rahmen, der die GD-Warnung einfängt und im Debug-Log sichtbar macht, statt sie zu verschlucken.
- `RetryFailedMailAttachmentsHandler` zählte seine Versuche nicht: eine `RecoverableMessageHandlingException` stellt dieselbe Nachricht erneut zu, `attempt` blieb für immer `1`. Damit war `MAX_ATTEMPTS` unerreichbar und der finale `failed`-Status wurde nie geschrieben. Neu: expliziter Re-Dispatch mit `attempt + 1` und `DelayStamp` (Exponential-Backoff 60 s → 1 h), unabhängig von der `retry_strategy` des Transports.
- `MailBeforeValidateSubscriber` und `RetryFailedMailAttachmentsHandler` beziehen den Status-Tracker jetzt über den Interface-Alias statt über die konkrete Klasse (DIP).
- `composer.lock` war im Arbeitsverzeichnis gelöscht und wurde wiederhergestellt.
- **Plugin-Konfiguration erschien im deutschen Admin auf Englisch.** In `config.xml` trugen die 4 Karten-Titel, 13 Feld-Labels und 13 HelpTexts den deutschen Text im `lang`-losen `<label>`/`<title>` plus eine `en-GB`-Variante, aber kein `de-DE`. Shopware löst für einen de-DE-Admin `de-DE → en-GB-Fallback` auf und zeigte damit Englisch. Alle 30 Einträge tragen jetzt explizit `lang="de-DE"`.

### Qualität

- **CI-Wächter statt Schein-Prüfung.** Die Schritte „Twig-Templates linten" und „Config-XML linten" hingen an `vendor/bin/console` — das es in einem Plugin-Repository nie gibt. Sie meldeten grün, ohne je etwas zu prüfen; genau deshalb blieb `sw_csrf` unentdeckt. Ersetzt durch einen Regel-Wächter, der ohne Shopware-Kernel läuft: entfernte APIs (`sw_csrf`, `CsrfPlaceholderHandler`), `@`-Error-Suppression, XML-/JSON-Wohlgeformtheit.
- Neue Tests: `MailTemplateTypeResolverTest` (6), Regressionstests im `MailBeforeValidateSubscriberTest` (3), neuer `MailAttachmentIntegrationTest` (4), Backoff- und Attempt-Tests im Retry-Handler. Der Integration-Test `testMailServiceBuildsEmailWithCustomerAttachment` fährt die vollständige Kette durch `MailService::send()` — echter Event-Dispatcher (prüft damit auch die DI-Registrierung des Subscribers), Daten-Validierung und `MailFactory` — und prüft am erzeugten `Symfony\Component\Mime\Email`-Objekt, dass der Anhang mit Dateiname, MIME-Typ und Inhalt wirklich dranhängt.
- `composer.lock` aktualisiert: 14 Security-Advisories in transitiven Abhängigkeiten geschlossen (`twig/twig` 3.26→3.28, `guzzlehttp/psr7` 2.10→2.12, `guzzlehttp/guzzle`, `phpseclib/phpseclib`, `symfony/*` auf 7.4.14). `composer audit`: **0 Vulnerabilities**. `shopware/core`, PHPUnit, PHPStan und PHP-CS-Fixer bleiben unverändert; `content-hash` identisch, da `composer.json` nicht angefasst wurde.

### Architektur

- Page-Loader-Pattern für DSGVO-relevante Storefront-Pfade: `OrderAttachmentPageletLoader` (mit Interface) sammelt Daten für Confirm- und Account-Order-Page, die Subscriber bleiben reine Event-Adapter. Vorbereitet für künftige Caching-Decoration ohne Eingriff in Subscriber.

### Resilienz

- Mail-Anhang-Status-Tracking: neues internes Custom-Field `rc_order_attachment_mail_status` auf `order` (Werte `attached`, `partial_failure`, `failed`). Set wird über einen `CustomFieldInstaller` in `install()`/`update()` idempotent angelegt und in `uninstall(keepUserData=false)` entfernt.
- Optionales Opt-in `enableMailRetry` (Default aus): bei Teil-/Voll-Fehler im Mail-Anhang dispatcht der Subscriber ein `RetryFailedMailAttachmentsMessage`. Der `RetryFailedMailAttachmentsHandler` prüft die Lesbarkeit der Anhänge und stellt sich bei Misserfolg selbst erneut zu — `attempt + 1` plus `DelayStamp` (60 s, 120 s, 240 s, 480 s; Deckel 1 h). Ab `MAX_ATTEMPTS = 5` final `failed`. Der eigentliche Mail-Re-Send bleibt Operator-Aufgabe.

### Qualität

- PHPStan Level 8 ohne Findings im gesamten `src/` (15 pre-existing Findings behoben: fehlende Generic-Array-Typen in PHPDoc, `Doctrine\DBAL\ArrayParameterType` statt deprecated `Connection::PARAM_STR_ARRAY`, redundante Type-Checks im Loader, präzise UploadedFile-Typen im Upload-Service).
- Vollständiger Kommentar-Audit: jede Klasse hat einen Doc-Block, jede Test-Methode dokumentiert „Was/Warum/Erwartet". Lokales `phpunit.xml` läuft nur die Unit-Suite; Integration-Tests brauchen eine vollständige Installation (`tests/Integration` via `phpunit.xml.dist`).
- 11 pre-existing rote Unit-Tests auf „echte-Instanzen-mit-Shopware-Boundary-Mocks"-Pattern umgestellt; Service-Boundaries `OrderAttachmentManagerInterface` und `MailAttachmentStatusTrackerInterface` extrahiert für künftige Audit- und Caching-Decorationen.

## [1.0.0] - 2026-05-20

> **Deployment:** `bin/build-storefront.sh` erforderlich (Erstinstallation, JS + SCSS).

### Erst-Release — Customer-Upload auf Confirm-Page mit Mail-Anhang, B2C-optimiert

#### Funktional

- Confirm-Page-Sektion mit asynchronem Multi-File-Upload, Drag&Drop auf Desktop, nativem Picker auf Smartphone
- Smartphone-tauglich: HEIC/HEIF-Format (iPhone), 10 MB Default-Dateigröße, Touch-optimierte UI
- Storefront-Controller `POST /rc-order-attachment/upload` und `POST /rc-order-attachment/{token}` (CSRF-Token, Login Required, Guest erlaubt)
- Customer-Download-Endpoint im Kundenkonto `/account/order-attachment/{id}/download` (Owner-Check via DAL-Filter)
- Pending-Uploads werden session-basiert verwaltet, beim Order-Placement an die Bestellung gebunden
- `MailBeforeValidateSubscriber` hängt Dateien an `order_confirmation_mail` (template-agnostisch via `binAttachments`)
- Cart-Validator erzwingt „Upload Pflicht" server-seitig (JS-Bypass unmöglich)
- Account-Bereich-Erweiterung zeigt Customer seine Anhänge mit Download-Link

#### Konfiguration (12 Settings)

- Plugin aktiv / Upload Pflichtfeld
- Max. Anzahl Dateien (1–50), max. Größe je Datei, max. Gesamt-Größe je Bestellung
- Whitelist erlaubter Endungen (Default für B2C: `pdf,jpg,jpeg,png,webp,heic,heif`)
- Rate-Limit (Uploads pro Minute pro Session, Default 30)
- EXIF-Strip aus Bildern (Default aktiv, DSGVO-relevant)
- Retention-Fristen: verwaiste Uploads (Stunden), abgeschlossene Anhänge (Tage, 0 = nie)
- Toggle für Mail-Anhang + Mail-Template-Whitelist (Default `order_confirmation_mail`, verhindert Mehrfach-Versand)

#### Sicherheit (Defense-in-Depth, 17 Schichten)

- Extension-Whitelist (admin-konfigurierbar, normalisiert)
- Hart kodierte Blacklist gefährlicher Endungen (überstimmt Whitelist)
- MIME-Detection server-seitig via `finfo`
- Magic-Bytes-Check (PDF, JPEG, PNG, WebP, GIF, BMP, TIFF, ZIP, Office, HEIC/HEIF mit ISO-Base-Media-Container-Check)
- Script-Signaturen-Scan der ersten 2 KB (`<?php`, `<script`, Shebang)
- **Filename-Sanitization**: CRLF, Steuerzeichen (`\x00–\x1F`, `\x7F`), Unicode-Bidi-Marker (U+202A–E, U+2066–9 — RTL-Override-Phishing-Schutz)
- **EXIF-Strip** aus Bildern (GD-basiert, Fail-Open bei fehlender Extension)
- Datei-Umbenennung auf `rc-<uuid>.<ext>` (Original nur in DB)
- Private Media-Speicherung im dedizierten Plugin-Folder
- CSRF-Token auf Upload UND Remove via Shopware-CSRF-Layer
- Login-Required (Guest erlaubt)
- Owner-Check beim Account-Download via DAL-Filter (`order.orderCustomer.customerId`)
- Cart-Validator (server-seitige Erzwingung)
- **Rate-Limiting** (Cache-basiert, Fail-Open)
- Order-Version-Filter (Defense gegen Draft-Version-Leak)
- **Sichere Response-Header**: `X-Content-Type-Options: nosniff`, `Content-Security-Policy`, `Referrer-Policy`, `X-Frame-Options`
- **Worker-Mode-Sicherheit**: `ResetInterface` + `kernel.reset` schützt vor Cross-Customer-Leak in Swoole/RoadRunner/FrankenPHP

#### Plugin-Interaktion

- Kompatibel mit `RcDynamicPrice`, `RcCartSplitter`, `RcCustomFields`, `RcColorPicker`: keine LineItem-ID/Buy-Widget/Payload-Berührung
- OrderPlaced-Subscriber Priority -500 (nach CartSplitter)
- Snapshot-Pattern für Cart-Split-Kompatibilität

#### DSGVO / Datenschutz

- Customer-Sicht im Kundenkonto (Art. 15 Auskunft)
- Datenschutz-Hinweis als `<details>`-Element auf Confirm-Page (Art. 13 Informationspflicht)
- EXIF-Strip schützt vor unbeabsichtigtem GPS-/Geräte-Leak (Art. 5 Datenminimierung)
- Konfigurierbare Retention-Frist (Art. 17 Lösch-Recht, Default 180 Tage)
- Strukturiertes Logging ohne PII-Volltexte

#### Barrierefreiheit (BFSG seit 28.06.2025)

- WCAG 2.2 AA: `aria-live="polite"` auf Datei-Liste, `aria-live="assertive"` auf Fehler-Region
- `aria-describedby` verbindet File-Input mit Limits-Hinweis
- Progress-Bar mit `aria-valuenow`-Updates
- Touch-Ziel-Mindestgröße 24×24 CSS-Pixel
- Respekt für `prefers-reduced-motion`
- Sichtbarer Tastatur-Fokus (`focus-visible` mit 2 px Outline)
- Drag&Drop-Hinweis auf Touch-Only-Geräten ausgeblendet
- Semantisches HTML (`<button>`, `<label for>`, `<details>`)

#### Cleanup

- `OrphanedAttachmentCleanupTask` (stündlich): verwaiste Pending-Uploads ohne Order-Bindung
- `ExpiredAttachmentCleanupTask` (täglich): DSGVO-Retention mit zweistufigem Löschen (Aggregat zuerst, Media nur unreferenced)
- Plugin-Uninstall ohne `keep-user-data` räumt Tabelle + Media + MediaFolder

#### Tests

- 16 Test-Klassen, ~90 Unit-Tests, deterministisch (keine `time()`/`uniqid()`/`random_int`)
- 2 Integration-Test-Skelette für DAL-Round-Trip und Migration
- Edge-Case-Tests für: Bidi-Override (RTL-Phishing), CRLF, Null-Byte, Steuerzeichen, HEIC-Magic-Bytes, Cart-Split-Snapshot, Worker-Mode-Reset, Owner-Check

#### Code-Qualität

- PHP 8.2+ Features: `final readonly class`, Constructor Promotion, Enums, Match-Expressions, First-Class Callable
- `declare(strict_types=1)` in jeder Datei
- Keine `@`-Error-Suppression
- Keine PII im Logging
- PHPStan-Level 8 + banned-code + strict-rules
- Senior-Code-Standard mit deutschen Kommentaren, deutsche Umlaute korrekt

### Bewusste Einschränkungen

- **Mail-Anhang-Memory**: Anhänge als String-Blob, Peak-Memory entspricht Summe der Anhang-Größen. Bei Default-Konfig (40 MB Gesamt) unkritisch.
- **PDF-JavaScript wird nicht gestrippt**: Mitarbeiter-PDF-Reader muss sandboxed sein. Empfehlung im README: `qpdf`-Strip auf OS-Ebene.
- **Antivirus-Scan ist nicht integriert**: EICAR-Test-Patterns gehen durch. Empfehlung im README: ClamAV als Webserver-Modul.
- **HEIC-EXIF-Strip**: GD kann HEIC nicht — Original wird durchgelassen. Imagick-Integration wäre Erweiterung.
- **Multi-SC-Extensions-Whitelist**: SC-agnostisches Event in Shopware — Whitelist gilt global.

### Sicherheitshinweise für Produktiv-Setup

- PHP-Limits (`upload_max_filesize`, `post_max_size`) ≥ Plugin-Konfig
- HTTPS-only + HSTS-Header (Webserver-Verantwortung)
- WAF/Cloudflare-Rate-Limit als Erste-Verteidigungslinie
- ClamAV als Webserver-Modul empfohlen
- Mitarbeiter-Mail-Client mit Sandbox + Antivirus
- Mailbox-Quota beim Mitarbeiter
