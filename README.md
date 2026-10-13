# RcOrderAttachment

Ein Shopware-6-Plugin, mit dem der Kunde auf der Confirm-Page Dokumente (Zeichnungen, Skizzen, Pläne) hochlädt. Sie hängen anschließend automatisch an der Bestell-Bestätigungs-Mail.

## Was das Plugin macht

Im letzten Checkout-Schritt, nach der Eingabe der Kundendaten und vor „Kauf abschließen", erscheint ein Upload-Bereich. Hochgeladene Dateien werden privat gespeichert (`/media-private/…`), nach Bestellabschluss als Anhang an `order_confirmation_mail` versendet und damit den Shop-Mitarbeitern direkt zugänglich gemacht.

Nicht abgeschlossene Uploads werden automatisch nach einer konfigurierbaren Frist entfernt. Bestell-Anhänge unterliegen einer DSGVO-Retention-Frist und werden danach automatisch gelöscht.

## Voraussetzungen

- Shopware **6.7** oder **6.8**
- PHP **8.2+**
- GD- oder Imagick-PHP-Extension (für EXIF-Strip, optional)

## Installation

Das Plugin gehört als Ordner `custom/plugins/RcOrderAttachment` in die Shopware-Installation. Übertragen wird er von Hand per FTP in dieses Verzeichnis, ein Composer-Paket gibt es nicht. Danach registriert Shopware ihn über die folgenden Befehle:

Als Archiv geht es auch ohne FTP: In der Administration unter **Erweiterungen → Meine Erweiterungen → Erweiterung hochladen** nimmt Shopware eine ZIP-Datei entgegen und legt sie selbst an die richtige Stelle; auf der Konsole tut `plugin:zip-import` dasselbe. Danach folgen dieselben Schritte wie unten. Wer das Archiv unter macOS packt, entfernt vorher den Ordner `__MACOSX`, sonst weist Shopware die Datei ab.

Das gebaute Storefront-JS und das Admin-Bundle sind im Plugin enthalten. Auf dem Server ist deshalb **kein Node-Build nötig**.

```bash
php bin/console plugin:refresh
php bin/console plugin:install --activate RcOrderAttachment
php bin/console assets:install
php bin/console theme:compile
php bin/console cache:clear
```

**Aktualisierungen laufen denselben Weg.** Den neuen Ordner per FTP über den alten legen, danach `plugin:refresh` und `plugin:update RcOrderAttachment`. Von selbst aktualisiert sich nichts: Ohne Composer-Paket und ohne Shopware-Store bleibt das Sache des Betreibers. Vor einem Sprung über eine Hauptversion gehört ein Datenbank-Abzug dazu.

`assets:install` verteilt das Admin-Bundle, `theme:compile` übernimmt das mitgelieferte Storefront-JS.

**Beim Update** den Plugin-Ordner vollständig überschreiben (einschließlich `src/Resources/app/storefront/dist/` und `src/Resources/public/`) und dieselben Befehle mit `plugin:update` statt `plugin:install` fahren.

Bei eigenen Frontend-Änderungen (SCSS/JS) wird neu gebaut und das Ergebnis eingecheckt:

```bash
bin/build-storefront.sh   # danach dist/ ins Repo zurückholen und committen
```

## Konfiguration

Im Admin unter **Einstellungen → System → Plugins → Bestellungs-Anhänge → Konfigurieren**.

### Allgemein

| Feld | Beschreibung | Default |
|---|---|---:|
| Plugin aktiv | Aktiviert die Upload-Funktion | `true` |
| Upload Pflichtfeld | Mindestens eine Datei nötig, sonst kein Order-Placement | `false` |

### Datei-Limits

| Feld | Beschreibung | Default | Hartes Maximum |
|---|---|---:|---:|
| Max. Anzahl Dateien | Pro Bestellung | `5` | 50 |
| Max. Größe je Datei (KB) | Smartphone-Fotos liegen bei 3–10 MB | `10 240` (10 MB) | 102 400 (100 MB) |
| Max. Gesamt-Größe je Bestellung (KB) | Summe aller Anhänge | `40 960` (40 MB) | 512 000 (500 MB) |
| Erlaubte Endungen | Whitelist, kommasepariert | `pdf,jpg,jpeg,png,webp,heic,heif` | – |
| Rate-Limit (Uploads pro Minute) | Pro Customer-Session, 0 = aus | `30` | – |
| EXIF aus Bildern entfernen | DSGVO: GPS-Strip vor Speicherung | `true` | – |

### Aufbewahrung

| Feld | Beschreibung | Default |
|---|---|---:|
| Verwaiste Uploads löschen nach (Stunden) | Cron-Cleanup für Uploads ohne Order-Bindung | `24` |
| Bestell-Anhänge löschen nach (Tagen) | DSGVO-Retention, `0` = nie | `180` |

### E-Mail

| Feld | Beschreibung | Default |
|---|---|---:|
| An Bestätigungs-Mail anhängen | Anhänge an `order_confirmation_mail` mitsenden | `true` |
| Mail-Template-Technical-Names | Whitelist der Mail-Templates, an die angehängt wird | `order_confirmation_mail` |

PHP-Limits (`upload_max_filesize`, `post_max_size`) bilden den absoluten Deckel. Was darüber hinaus konfiguriert ist, schneidet der Webserver schon vor PHP ab. Die PHP-Limits sollten deshalb bei mindestens 12 MB liegen, damit der Plugin-Default (10 MB je Datei) sicher passt.

## Sicherheit

### Mehrschichtige Verteidigung im Plugin

1. **Whitelist** der erlaubten Endungen (admin-konfigurierbar, normalisiert). Sie gilt **nur für Uploads dieses Plugins**. Shopware fragt die erlaubten Endungen über ein Event ab, das für jeden Media-Upload im Shop feuert; das Plugin markiert dafür den Upload-Context und erweitert die Liste ausschließlich bei gesetztem Marker. Eine hier eingetragene Sonder-Endung (z. B. `dwg`) wird dadurch **nicht** shopweit erlaubt
2. **Hart kodierte Blacklist** gefährlicher Endungen (`php`, `phtml`, `phar`, `htaccess`, `sh`, `exe`, `bat`, `cmd`, `ps1`, `vbs`, `js`, `jsp`, `asp`, `aspx`, `html`, `htm`, `svg`, `hta`, `dll`, `msi`, …), überstimmt die Whitelist
3. **MIME-Detection** server-seitig per `finfo` (kein Client-`Content-Type`-Trust)
4. **Magic-Bytes-Check** für PDF, JPEG, PNG, WebP, HEIC/HEIF, GIF, ZIP, Office-Dokumente
5. **Script-Signaturen-Scan** der ersten 2 KB (`<?php`, `<script`, Shebang-Lines) als Schutz gegen Polyglot-Dateien
6. **Datei-Umbenennung** auf `rc-<uuid>.<ext>`; der Original-Filename steht nur in der DB, nie im Filesystem-Pfad
7. **Filename-Sanitization** entfernt CRLF, Steuerzeichen, Bidi-Override-Marker (Schutz vor Phishing-Filenames wie `cute‮gpj.exe`)
8. **EXIF-Strip** aus Bildern (GPS-Koordinaten weg vor Speicherung)
9. **Private Speicherung** im dedizierten Media-Folder → `/media-private/`, kein direkter HTTP-Pfad
10. **POST-only + SameSite-Session-Cookie** auf Upload- und Remove-Endpoint. Shopware hat den CSRF-Layer mit 6.5 ersatzlos entfernt (`sw_csrf`, `CsrfPlaceholderHandler` existieren nicht mehr); Cross-Site-Schutz liefert seitdem das `SameSite=Lax`-Session-Cookie. Zusätzlich sind Remove-Tokens 128-Bit-Zufallswerte und ausschließlich in der eigenen Session gültig
11. **Login-Required** auf Upload-Endpoint (Guest-Checkout zulässig, wie im Shopware-Standard)
12. **Owner-Check** beim Account-Download: Customer sieht nur Anhänge eigener Orders
13. **Cart-Validator** erzwingt „Upload Pflicht" server-seitig, ein Umgehen per JS ist nicht möglich
14. **Rate-Limiting** pro Session (Cache-basiert, konfigurierbar)
15. **Order-Version-Filter** verhindert Draft-Version-Vermischung
16. **Sichere Response-Header** (`X-Content-Type-Options`, CSP, Referrer-Policy, X-Frame-Options) auf allen JSON-Endpoints
17. **Reset-Mechanik** im OrderPlaced-Subscriber schützt Worker-Mode-Setups (Swoole, RoadRunner) vor Cross-Customer-Leaks

### Empfehlungen für Produktiv-Setups

Diese Verteidigungs-Schichten gehören in den Webserver/Hosting-Layer und sind **nicht** Plugin-Verantwortung:

| Empfehlung | Warum |
|---|---|
| **ClamAV als Webserver-Modul** oder Symfony-Bundle | Antivirus-Scan vor PHP. Plugin scannt nur Script-Signaturen, keine Malware-Datenbanken. EICAR-Tests laufen sonst durch. |
| **WAF/Cloudflare Rate-Limit** auf Upload-Endpoint | Plugin-Rate-Limit ist Defense-in-Depth, kein Erste-Verteidigungslinie. WAF stoppt Bots vor PHP. |
| **TLS-only + HSTS** im Webserver | Plugin kann HTTPS nicht erzwingen. Pflicht via `Strict-Transport-Security`-Header. |
| **`upload_max_filesize` ≥ `maxFileSizeKb`** | PHP-Limit ist hartes Gate. Plugin-Konfig darunter sonst wirkungslos. |
| **PDF-Reader mit Sandbox beim Mitarbeiter** | PDFs können `OpenAction`-JavaScript enthalten, und das Plugin entfernt es nicht. Adobe-Acrobat-Sandbox / Foxit-Reader sind sichere Defaults. |
| **`qpdf --linearize --object-streams=disable`** vor Mail-Versand | Optionaler PDF-JS-Strip auf OS-Ebene. |
| **Mailbox-Quota beim Mitarbeiter** | Schutz gegen Mail-Volume-Inflation durch Customer-Bestellungen mit großen Anhängen. |

## DSGVO

Das Plugin verarbeitet personenbezogene Daten (Original-Filename, hochgeladenes Dokument, Upload-Zeitpunkt).

### Datenfluss

1. Customer lädt auf Confirm-Page hoch → Datei landet privat in `/media-private/`, Filename als Label in DB
2. Bei Order-Placement: Verknüpfung mit Order
3. Bei Mail-Versand: Datei wird an `order_confirmation_mail` angehängt
4. Nach Retention-Frist (Default 180 Tage): automatische Löschung

### Customer-Rechte (Art. 15 + Art. 17 DSGVO)

- **Auskunft**: Customer sieht im Kundenkonto unter „Meine Bestellungen → Bestelldetails" die Liste der hochgeladenen Dateien mit Download-Link
- **Löschung**: Bei Customer-Account-Löschung (Shopware-Standard) bleiben die Anhänge erhalten (Order bleibt aus steuerrechtlichen Gründen). Anhänge folgen der Order-Retention.

### Datenschutz-Hinweis im UI

Auf der Confirm-Page erscheint ein aufklappbares Hinweis-Feld vor dem Upload. Das verlangt DSGVO Art. 13 (Informationspflicht).

### Auftragsverarbeitung bei Cloud-Storage

Wenn Shopware-Media auf S3/Cloud-Storage liegt: **Auftragsverarbeitungs-Vertrag (AVV)** mit dem Cloud-Provider Pflicht. Plugin selbst hat keinen externen Storage-Zugriff.

## Barrierefreiheit (BFSG / WCAG 2.2 AA)

Der Confirm-Page-Upload erfüllt WCAG 2.2 AA:

- Tastatur-Bedienbar (Tab-Navigation, Enter/Space)
- Sichtbarer Tastatur-Fokus (`focus-visible`, 2px Outline)
- ARIA-Live-Regionen für Status-Updates (`aria-live="polite"` auf Datei-Liste, `aria-live="assertive"` auf Fehler)
- `aria-describedby` auf File-Input verlinkt die Limits
- Progress-Bar mit `aria-valuenow`-Updates
- Touch-Ziel-Mindestgröße 24×24 CSS-Pixel
- Respekt für `prefers-reduced-motion` (Progress-Animation aus)
- Semantisches HTML (`<button>`, `<label>`, `<details>`)

Audit-Tools getestet: NVDA + Tab-Only-Navigation auf Firefox/Chrome.

## Smartphone-Tauglichkeit

B2C-tauglich für Smartphone-Uploads:

- HEIC/HEIF (iPhone-Default seit iOS 11) wird über Magic-Bytes-Check unterstützt
- Default-Dateigröße auf 10 MB hochgesetzt (passt zu Smartphone-Fotos)
- File-Input nutzt nativen iOS/Android-Picker (Foto-Library + Kamera)
- Drag&Drop-Hinweis wird auf Touch-Only-Geräten ausgeblendet (`@media (hover: none)`)
- Filename umbricht statt horizontal zu scrollen (`word-break: break-all`)

Empfohlene Tests: iOS Safari (iOS 15+), Android Chrome (Android 10+).

## Plugin-Interaktion

Kompatibel mit anderen Ruhrcoder-Plugins, die den Checkout berühren, also `RcDynamicPrice`, `RcCartSplitter`, `RcCustomFields` und `RcColorPicker`:

- Berührt **keinen** Buy-Widget-Block, **keine** LineItem-ID, **kein** Cart-Payload
- Erweitert ausschließlich Confirm-Page (`page_checkout_additional`), Account-Order-Page und Mail-Pipeline
- Subscriber auf `CheckoutOrderPlacedEvent` läuft mit Priority **-500** (nach `RcCartSplitter`)
- **Cart-Split**: Bei mehreren resultierenden Orders aus einem geteilten Cart werden die Anhänge an alle Teil-Orders verlinkt (Snapshot-Pattern + Worker-Mode-sicheres Reset)

## Architektur

Aufgebaut ist es wie ein klassisches Shopware-Plugin: Subscriber/Controller → Service → DAL-Repository → Entity. Dazu kommen:

- **Page-Loader-Pattern** für DSGVO-relevante Storefront-Pfade: `Storefront\Page\OrderAttachmentPageletLoaderInterface` sammelt die Daten für Confirm- und Account-Order-Page. Die Subscriber (`CheckoutConfirmPageSubscriber`, `AccountOrderPageSubscriber`) sind reine Event-Adapter und delegieren ans Interface. Eine spätere Caching-Decoration kommt so ohne Eingriff in die Subscriber aus.
- **Service-Boundaries als Interfaces**: `OrderAttachmentManagerInterface` und `MailAttachmentStatusTrackerInterface` lassen Caller und Tests unabhängig von der konkreten (`final`-)Implementierung sein. Standard-Aufruf läuft via `services.xml`-Alias auf die konkrete Klasse.
- **Mail-Anhang-Status-Tracking**: internes Custom-Field `rc_order_attachment_mail_status` auf `order` mit den Werten `attached`, `partial_failure`, `failed`. Wird vom `MailBeforeValidateSubscriber` über `MailAttachmentStatusTrackerInterface` gesetzt. Das Custom-Field-Set wird via `CustomFieldInstaller` in `install()`/`update()` idempotent angelegt.
- **Opt-in Mail-Retry**: bei aktiviertem `enableMailRetry` (Default `false`) dispatcht der Subscriber bei Teil-/Voll-Fehler ein `RetryFailedMailAttachmentsMessage`. Die Nachricht implementiert `AsyncMessageInterface` und wird damit auf den `async`-Transport geroutet. Der `RetryFailedMailAttachmentsHandler` prüft die Lesbarkeit der Anhänge bis zu fünfmal mit Exponential-Backoff (60 s, verdoppelnd, gedeckelt), indem er sich selbst mit `attempt + 1` und `DelayStamp` neu dispatcht. Bewusst nicht über `RecoverableMessageHandlingException`, weil dabei der Versuchszähler nicht mitwandert und die Abbruchbedingung nie griffe. Erst danach final `failed`. Operator entscheidet bewusst, ob ein Mail-Re-Send manuell oder automatisiert erfolgt.
  > **Voraussetzung: ein laufender Messenger-Worker** (`bin/console messenger:consume async`). Ohne Worker bleibt die Nachricht in der Queue liegen, der Status verharrt dann auf `partial_failure`/`failed`, und es findet kein Retry statt. Ohne die Transport-Zuordnung wiederum liefe der Retry synchron im selben Request ab und die Wartezeiten wären wirkungslos.

## Cleanup

Vier Lösch-Wege halten den Server sauber:

| Wann | Wer | Was |
|---|---|---|
| Customer klickt „Entfernen" auf Confirm-Page | Storefront-Controller (POST, session-gebundener Token) | sofortiges Media-Delete |
| Pending-Upload ohne Order-Abschluss älter als X Stunden | `OrphanedAttachmentCleanupTask` (stündlich) | Cron räumt verwaiste Media |
| Bestell-Anhänge älter als N Tage | `ExpiredAttachmentCleanupTask` (täglich) | DSGVO-Retention; Media bleibt erhalten, solange noch jüngere Order (z. B. Cart-Split-Teil) darauf zeigt |
| `plugin:uninstall` ohne `--keep-user-data` | `RcOrderAttachment::uninstall()` | Tabelle + Media-Records + Plugin-MediaFolder werden entfernt |

## Mail-Template

**Keine Template-Änderung nötig.** Anhänge werden über `binAttachments` ins `MailBeforeValidateEvent` injiziert, und das Mail-System hängt sie automatisch an die `order_confirmation_mail` an.

Die Whitelist `mailTemplateWhitelist` steuert, an welche Mail-Templates Anhänge gehängt werden. Default ist `order_confirmation_mail`, damit Order-State-Change-Mails (Versand, Storno, Rückerstattung) NICHT mit Anhängen geflutet werden.

**Wie der Abgleich funktioniert.** Shopware liefert im `MailBeforeValidateEvent` keinen `mailTemplate`-Eintrag in den Template-Daten, denn `SendMailAction` baut sie als `['eventName' => <Flow-Event>, ...$flow->data()]`. Der einzige Bezug zum konkreten Template ist `data['templateId']`. Der `MailTemplateTypeResolver` löst daraus den `technicalName` des Mail-Template-Types auf. Verglichen wird gegen zwei Kandidaten:

1. den aufgelösten Template-Type (`order_confirmation_mail`), das ist der Default
2. den Flow-Event-Namen (`checkout.order.placed`), falls ein Admin ihn einträgt

Lässt sich der Template-Type nicht auflösen (gelöschtes Template, DB-Fehler) und passt auch der Event-Name nicht, wird **nicht** angehängt (fail-closed). Die Mail geht trotzdem raus, nur ohne Anhang. Der Fehlschlag steht im Log unter `rc_order_attachment.mail.template_type_lookup_failed`.

Leere Whitelist bedeutet „an alle Mails mit Order-Bezug anhängen".

Wer im Mail-Text auf die Anhänge hinweisen möchte, kann das Standard-Snippet selbst ergänzen. Die Plugin-Logik prüft den Body nicht.

## Bekannte Einschränkungen

- **Mail-Anhang-Memory**: Anhänge werden als String-Blob ins `binAttachments`-Array gelegt. Peak-Memory beim Mail-Versand entspricht der Summe der Anhang-Größen. Bei Default-Konfig (40 MB Gesamt) unkritisch. `php-fpm` `memory_limit` entsprechend dimensionieren.
- **PDF-JavaScript wird nicht gestrippt**: PDFs mit eingebettetem JS gehen 1:1 weiter. Mitarbeiter-PDF-Reader muss sandboxed sein. Plugin-eigener Strip wäre `qpdf`-abhängig.
- **Antivirus-Scan ist nicht integriert**: EICAR-Test-Patterns gehen durch. Bei kritischen Umgebungen ClamAV als Webserver-Modul vorschalten.
- **Multi-SC-Whitelist**: Der `MediaFileExtensionWhitelistEvent` hat keinen SC-Kontext. Pro-SC-unterschiedliche Endungs-Whitelists werden zur globalen Vereinigungs-Menge.
- **Customer-Account-Wechsel in derselben Browser-Session**: Pending-Uploads sind session-basiert, nicht an den Customer gebunden, und würden deshalb „mitwandern". Das ist ein sehr seltener Edge-Case.
- **Mail-Retry-Verhalten**: Bei Mail-Anhang-Failure (z. B. Storage-Down beim Mail-Versand) wird die Mail OHNE den betroffenen Anhang versendet. Das Custom-Field `rc_order_attachment_mail_status` markiert den Befund (`partial_failure` / `failed`). Bei aktiviertem `enableMailRetry` (opt-in) wird ein Retry über die Messenger-Queue dispatcht; der eigentliche Re-Send der Mail bleibt bewusst Operator-Aufgabe, damit Kunden keine Duplikat-Mails erhalten.

## Update

```bash
php bin/console plugin:refresh
php bin/console plugin:update RcOrderAttachment
php bin/console cache:clear
```

Bei Versionssprüngen mit Frontend-Änderungen siehe `CHANGELOG.md` für den Deployment-Hinweis.

## Entwicklung

```bash
composer install
composer quality   # cs-check + phpstan + test
```

Unit-Tests laufen lokal ohne lebende Shopware-Instanz (`vendor/bin/phpunit` mit lokalem `phpunit.xml`, nur die Unit-Suite). Integration-Tests (`tests/Integration/` für DAL-Round-Trips, Migration-Schema-Checks, Mail-Anhang-Kette) benötigen das Shopware-Test-Bootstrap, eine MySQL-Test-Datenbank und laufen via `phpunit.xml.dist` aus dem Shopware-Root:

```bash
DATABASE_URL=mysql://user:pass@host:3306/shopware_test APP_ENV=test \
  vendor/bin/phpunit -c custom/plugins/RcOrderAttachment/phpunit.xml.dist --testsuite Integration
```

## Lizenz

MIT

---

Entwickelt von [Ruhrcoder](https://ruhrcoder.de)
