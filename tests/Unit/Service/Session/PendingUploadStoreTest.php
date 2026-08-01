<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit\Service\Session;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcOrderAttachment\Service\Session\PendingUpload;
use Ruhrcoder\RcOrderAttachment\Service\Session\PendingUploadStore;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Unit-Tests für den Session-Store der Pending-Uploads. Round-Trip (add/get/remove),
 * Persistenz über Session-Serialisierung, Defensive-Reads bei Müll-Sessions.
 */
final class PendingUploadStoreTest extends TestCase
{
    private const FIXED_UPLOADED_AT = 1_700_000_000;

    private RequestStack $stack;

    private PendingUploadStore $store;

    protected function setUp(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $request = Request::create('/');
        $request->setSession($session);

        $this->stack = new RequestStack();
        $this->stack->push($request);

        $this->store = new PendingUploadStore($this->stack);
    }

    /**
     * Was: Unter dem Session-Key liegt Müll (kein Array).
     * Warum: Manipulierte/korrupte Session darf keinen Type-Error auslösen —
     *        `loadList()` muss defensiv auf leere Liste zurückfallen.
     * Erwartet: `all()` leer, `count()` = 0.
     */
    public function testDefensiveReadOnNonArraySession(): void
    {
        $this->stack->getMainRequest()?->getSession()->set(PendingUploadStore::SESSION_KEY, 'not-an-array');

        self::assertSame([], $this->store->all());
        self::assertSame(0, $this->store->count());
    }

    /**
     * Was: Die Session-Liste enthält neben einem gültigen Eintrag kaputte
     *      (Nicht-Array-Payload, nicht-string-Token, unvollständige Daten).
     * Warum: Ein einzelner korrupter Eintrag darf nicht die ganze Liste
     *        unbrauchbar machen — nur der gültige Eintrag überlebt.
     * Erwartet: Genau der gültige Eintrag bleibt.
     */
    public function testDefensiveReadSkipsInvalidEntries(): void
    {
        $valid = new PendingUpload('validToken', 'media-1', 'a.pdf', 'application/pdf', 100, self::FIXED_UPLOADED_AT);
        $this->stack->getMainRequest()?->getSession()->set(PendingUploadStore::SESSION_KEY, [
            'validToken' => $valid->jsonSerialize(),
            'brokenPayload' => 'not-an-array',
            123 => ['incomplete' => true],
        ]);

        $all = $this->store->all();

        self::assertArrayHasKey('validToken', $all);
        self::assertCount(1, $all);
    }

    /**
     * Was: Upload hinzufügen und über Token/Counter/Total-Bytes/Media-IDs auslesen.
     * Warum: Kern-API des Stores — wenn das Round-Trip kaputt ist, gehen alle
     *        Confirm-Page- und Mail-Pfade kaputt.
     * Erwartet: Counter `1`, Bytes `2048`, identisches Upload-Objekt, Media-ID-Liste.
     */
    public function testAddAndRetrieve(): void
    {
        $upload = $this->upload('token-a', 'media-a', 'a.pdf', 'application/pdf', 2048);
        $this->store->add($upload);

        self::assertSame(1, $this->store->count());
        self::assertSame(2048, $this->store->totalBytes());
        self::assertEquals($upload, $this->store->get('token-a'));
        self::assertSame(['media-a'], $this->store->getMediaIds());
    }

    /**
     * Was: Zwei Uploads hinzufügen, einen entfernen.
     * Warum: Customer kann auf der Confirm-Page einzelne Dateien wieder entfernen.
     *        Der Store muss die richtige Datei finden und nur diese rauswerfen.
     * Erwartet: `remove` gibt das entfernte Upload zurück, Counter `1`,
     *           `get('a')` ist `null`.
     */
    public function testRemove(): void
    {
        $a = $this->upload('a', 'media-a', 'a.pdf', 'application/pdf', 100);
        $b = $this->upload('b', 'media-b', 'b.pdf', 'application/pdf', 200);
        $this->store->add($a);
        $this->store->add($b);

        $removed = $this->store->remove('a');

        self::assertEquals($a, $removed);
        self::assertSame(1, $this->store->count());
        self::assertNull($this->store->get('a'));
    }

    /**
     * Was: Store leeren via `clear()`.
     * Warum: Pflicht nach Order-Placement und beim Worker-Mode-Reset. Reste in
     *        der Session würden bei der nächsten Bestellung als „bereits da"
     *        erscheinen — Datenleak und doppelter Mail-Anhang.
     * Erwartet: Counter `0`, Bytes `0`, `all()` leer.
     */
    public function testClear(): void
    {
        $this->store->add($this->upload('a', 'media-a', 'a.pdf', 'application/pdf', 100));

        $this->store->clear();

        self::assertSame(0, $this->store->count());
        self::assertSame(0, $this->store->totalBytes());
        self::assertSame([], $this->store->all());
    }

    /**
     * Was: `PendingUpload::jsonSerialize()` und `fromArray()` als Round-Trip.
     * Warum: Die Session serialisiert/deserialisiert das Upload-Struct über
     *        die JSON-Schicht — wenn ein Feld verloren geht, gibt's bei
     *        Session-Reload ein kaputtes Upload-Objekt.
     * Erwartet: Alle Felder bleiben gleich nach `fromArray(jsonSerialize())`.
     */
    public function testSerializationRoundTrip(): void
    {
        $payload = $this->upload('xyz', 'media-xyz', 'a.pdf', 'application/pdf', 999, 1700000000)->jsonSerialize();
        $rebuilt = PendingUpload::fromArray($payload);

        self::assertNotNull($rebuilt);
        self::assertSame('xyz', $rebuilt->token);
        self::assertSame('media-xyz', $rebuilt->mediaId);
        self::assertSame('a.pdf', $rebuilt->originalFileName);
        self::assertSame(999, $rebuilt->fileSize);
        self::assertSame(1700000000, $rebuilt->uploadedAt);
    }

    /**
     * Was: `fromArray` mit leerem Payload und Type-Mismatch.
     * Warum: Defense-in-Depth — wenn Session-Daten manipuliert oder durch
     *        Versions-Wechsel inkompatibel werden, darf `fromArray` keinen
     *        Half-Initialized-Upload zurückgeben (`null` als Signal).
     * Erwartet: `null` bei leerem Array und bei Feld mit falschem Typ.
     */
    public function testFromArrayRejectsInvalidPayload(): void
    {
        self::assertNull(PendingUpload::fromArray([]));
        self::assertNull(PendingUpload::fromArray(['token' => 't', 'mediaId' => 1]));
    }

    private function upload(string $token, string $mediaId, string $name, string $mime, int $size, ?int $time = null): PendingUpload
    {
        return new PendingUpload($token, $mediaId, $name, $mime, $size, $time ?? self::FIXED_UPLOADED_AT);
    }
}
