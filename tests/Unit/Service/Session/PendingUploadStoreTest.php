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
 * Prüft den Sitzungsspeicher der offenen Uploads: Ablegen, Lesen und Entfernen, den Weg durch
 * die Serialisierung und das Lesen beschädigter Sitzungsdaten. Aus diesem Speicher entstehen
 * beim Bestellabschluss die Anhänge.
 */
final class PendingUploadStoreTest extends TestCase
{
    // Ein beliebiger fester Zeitstempel (November 2023), damit der Test nicht von der Uhr abhängt.
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
     * Eine beschädigte oder manipulierte Sitzung darf keinen Typfehler auslösen. Steht unter dem
     * Schlüssel kein Array, gilt die Liste als leer.
     */
    public function testDefensiveReadOnNonArraySession(): void
    {
        $this->stack->getMainRequest()?->getSession()->set(PendingUploadStore::SESSION_KEY, 'not-an-array');

        self::assertSame([], $this->store->all());
        self::assertSame(0, $this->store->count());
    }

    /**
     * Ein einzelner kaputter Eintrag (kein Array, Zahl als Token, unvollständig) darf die übrige
     * Liste nicht unbrauchbar machen.
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
     * Bestätigungsseite, Grenzprüfung und Bestellabschluss lesen alle über diese Schnittstelle.
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
     * Auf der Bestätigungsseite entfernt der Kunde einzelne Dateien. Nur die gewählte geht, und
     * `remove()` gibt sie zurück, damit der Aufrufer ihr Medium löschen kann.
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
     * Nach dem Bestellabschluss wird der Speicher geleert. Reste erschienen bei der nächsten
     * Bestellung als schon hochgeladen und hingen ein zweites Mal an.
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
     * In der Sitzung liegt der Upload als Array. Geht auf dem Weg hin und zurück ein Feld
     * verloren, ist er beim nächsten Seitenaufruf unvollständig.
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
     * Manipulierte oder nach einem Update unpassende Sitzungsdaten ergeben `null` statt eines
     * halb gefüllten Uploads.
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
