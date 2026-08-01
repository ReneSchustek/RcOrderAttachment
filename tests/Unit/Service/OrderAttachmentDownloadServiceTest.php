<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Psr\Log\NullLogger;
use Ruhrcoder\RcOrderAttachment\Core\Content\OrderAttachment\OrderAttachmentCollection;
use Ruhrcoder\RcOrderAttachment\Core\Content\OrderAttachment\OrderAttachmentEntity;
use Ruhrcoder\RcOrderAttachment\Service\Exception\AttachmentNotFoundException;
use Ruhrcoder\RcOrderAttachment\Service\Exception\MediaNotLoadedException;
use Ruhrcoder\RcOrderAttachment\Service\OrderAttachmentDownloadService;
use Ruhrcoder\RcOrderAttachment\Service\OrderAttachmentLoader;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Senior-saubere Tests: echter {@see OrderAttachmentLoader} mit gemocktem
 * {@see EntityRepository}, keine Mocks finaler Plugin-Klassen.
 */
final class OrderAttachmentDownloadServiceTest extends TestCase
{
    private const FIXED_ATTACHMENT_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const FIXED_CUSTOMER_ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const FIXED_MEDIA_ID = 'cccccccccccccccccccccccccccccccc';

    /**
     * Was: Anhang gehört dem aufrufenden Customer, Storage-Stream ist verfügbar.
     * Warum: Happy-Path nach DSGVO Art. 15 (Auskunft) — Customer lädt seinen
     *        eigenen Anhang im Account herunter. Headers `nosniff` und
     *        `Content-Disposition: attachment` schützen vor MIME-Sniffing und
     *        Inline-Render im Browser.
     * Erwartet: `StreamedResponse` mit korrektem Content-Type, attachment-
     *           Disposition und nosniff-Header.
     */
    public function testStreamsForRightfulOwner(): void
    {
        $loader = $this->loader([$this->attachment()]);

        $mediaService = $this->createMock(MediaService::class);
        $mediaService->method('loadFileStream')->willReturn($this->stubStream('FILE-CONTENT'));

        $service = new OrderAttachmentDownloadService($loader, $mediaService, new NullLogger());
        $response = $service->streamForCustomer(
            self::FIXED_ATTACHMENT_ID,
            $this->customer(),
            Context::createDefaultContext(),
        );

        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertStringContainsString('attachment', $response->headers->get('Content-Disposition') ?? '');
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    /**
     * Was: Customer ruft einen Anhang ab, der nicht zu seinen Bestellungen
     *      gehört (Owner-Check schlägt fehl).
     * Warum: IDOR-Schutz — wenn ein Angreifer fremde Anhang-IDs durchprobiert,
     *        muss „nicht autorisiert" denselben Fehler liefern wie „nicht
     *        vorhanden", sonst entstehen Disclosure-Pfade über Antwortzeit
     *        oder Fehler-Differenz.
     * Erwartet: `AttachmentNotFoundException` — kein 403, sondern 404.
     */
    public function testThrowsNotFoundWhenAttachmentBelongsToOtherCustomer(): void
    {
        // Owner-Check schlägt fehl → DAL-Suche liefert nichts → AttachmentNotFoundException.
        $service = new OrderAttachmentDownloadService(
            $this->loader([]),
            $this->createMock(MediaService::class),
            new NullLogger(),
        );

        $this->expectException(AttachmentNotFoundException::class);
        $service->streamForCustomer(self::FIXED_ATTACHMENT_ID, $this->customer(), Context::createDefaultContext());
    }

    /**
     * Was: Anhang ist gefunden, aber `media` ist `null` (Media-Assoziation
     *      fehlt nach DAL-Read).
     * Warum: Defekter Datensatz — Anhang-Eintrag existiert, aber Media wurde
     *        zwischendurch hart gelöscht. Klare Trennung von „nicht autorisiert"
     *        (404) und „Server-State korrupt" (500) hilft beim Debugging.
     * Erwartet: `MediaNotLoadedException`.
     */
    public function testThrowsMediaNotLoadedWhenMediaIsMissing(): void
    {
        $attachment = $this->attachment();
        $attachment->setMedia(null); // Simuliert verlorene Association

        $service = new OrderAttachmentDownloadService(
            $this->loader([$attachment]),
            $this->createMock(MediaService::class),
            new NullLogger(),
        );

        $this->expectException(MediaNotLoadedException::class);
        $service->streamForCustomer(self::FIXED_ATTACHMENT_ID, $this->customer(), Context::createDefaultContext());
    }

    /**
     * Was: Admin-Pfad lädt einen beliebigen Anhang ohne Owner-Bindung.
     * Warum: Die Auftragsbearbeitung braucht Zugriff auf das Kundendokument;
     *        die Autorisierung liegt auf der ACL des Admin-Endpoints, nicht auf
     *        einer Customer-Beziehung. Der Stream trägt dieselben Hardening-
     *        Header wie der Customer-Pfad.
     * Erwartet: `StreamedResponse` mit Content-Type, attachment-Disposition
     *           und nosniff-Header.
     */
    public function testStreamsForAdminWithoutOwnerCheck(): void
    {
        $mediaService = $this->createMock(MediaService::class);
        $mediaService->method('loadFileStream')->willReturn($this->stubStream('FILE-CONTENT'));

        $service = new OrderAttachmentDownloadService($this->loader([$this->attachment()]), $mediaService, new NullLogger());
        $response = $service->streamForAdmin(self::FIXED_ATTACHMENT_ID, Context::createDefaultContext());

        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertSame('application/pdf', $response->headers->get('Content-Type'));
        self::assertStringContainsString('attachment', $response->headers->get('Content-Disposition') ?? '');
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    /**
     * Was: Admin ruft eine Anhang-ID ab, zu der kein Datensatz existiert.
     * Warum: Auch der Admin-Pfad darf keinen anderen Fehler liefern als „nicht
     *        vorhanden" — der Controller mappt das auf 404, ohne die Existenz
     *        einzelner Datensätze durchsickern zu lassen.
     * Erwartet: `AttachmentNotFoundException`.
     */
    public function testStreamForAdminThrowsNotFoundWhenAttachmentMissing(): void
    {
        $service = new OrderAttachmentDownloadService($this->loader([]), $this->createMock(MediaService::class), new NullLogger());

        $this->expectException(AttachmentNotFoundException::class);
        $service->streamForAdmin(self::FIXED_ATTACHMENT_ID, Context::createDefaultContext());
    }

    /**
     * Was: Anhang existiert, aber das Media wurde bereits vom Retention-/Orphan-
     *      Cleanup entfernt (`media` = null).
     * Warum: Klare Trennung zwischen fehlendem Datensatz und fehlendem Media;
     *        der Controller mappt beide auf 404, der Service unterscheidet sie
     *        über die Exception-Klasse fürs Logging.
     * Erwartet: `MediaNotLoadedException`.
     */
    public function testStreamForAdminThrowsMediaNotLoadedWhenMediaMissing(): void
    {
        $attachment = $this->attachment();
        $attachment->setMedia(null);

        $service = new OrderAttachmentDownloadService($this->loader([$attachment]), $this->createMock(MediaService::class), new NullLogger());

        $this->expectException(MediaNotLoadedException::class);
        $service->streamForAdmin(self::FIXED_ATTACHMENT_ID, Context::createDefaultContext());
    }

    /**
     * Was: Der Customer-Download muss den DAL-Owner-Filter tatsächlich setzen.
     * Warum: Die anderen Tests beweisen nur Verhalten bei „gefunden"/„leer" —
     *        sie würden auch dann grün bleiben, wenn der Owner-Filter fehlte
     *        (IDOR). Dieser Test fängt die Criteria ein und prüft, dass der
     *        Filter `order.orderCustomer.customerId = <eigene ID>` gesetzt ist.
     * Erwartet: Ein `EqualsFilter` auf `order.orderCustomer.customerId` mit der
     *           ID des aufrufenden Customers ist Teil der Suche.
     */
    public function testStreamForCustomerAppliesOwnerFilter(): void
    {
        $captured = null;
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturnCallback(
            function (Criteria $criteria, Context $context) use (&$captured): EntitySearchResult {
                $captured = $criteria;
                $collection = new OrderAttachmentCollection([$this->attachment()]);

                return new EntitySearchResult('rc_order_attachment', 1, $collection, null, $criteria, $context);
            },
        );

        $mediaService = $this->createMock(MediaService::class);
        $mediaService->method('loadFileStream')->willReturn($this->stubStream('X'));

        $service = new OrderAttachmentDownloadService(new OrderAttachmentLoader($repository), $mediaService, new NullLogger());
        $service->streamForCustomer(self::FIXED_ATTACHMENT_ID, $this->customer(), Context::createDefaultContext());

        self::assertInstanceOf(Criteria::class, $captured);

        $ownerFilterApplied = false;
        foreach ($captured->getFilters() as $filter) {
            if ($filter instanceof EqualsFilter
                && $filter->getField() === 'order.orderCustomer.customerId'
                && $filter->getValue() === self::FIXED_CUSTOMER_ID
            ) {
                $ownerFilterApplied = true;
            }
        }

        self::assertTrue($ownerFilterApplied, 'Owner-Check-Filter fehlt — IDOR-Schutz nicht angewandt.');
    }

    /**
     * @param list<OrderAttachmentEntity> $entities
     */
    private function loader(array $entities): OrderAttachmentLoader
    {
        $collection = new OrderAttachmentCollection($entities);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willReturnCallback(
            static fn (Criteria $criteria, Context $context): EntitySearchResult => new EntitySearchResult(
                'rc_order_attachment',
                $collection->count(),
                $collection,
                null,
                $criteria,
                $context,
            ),
        );

        return new OrderAttachmentLoader($repository);
    }

    private function attachment(): OrderAttachmentEntity
    {
        $media = new MediaEntity();
        $media->setId(self::FIXED_MEDIA_ID);
        $media->setUniqueIdentifier(self::FIXED_MEDIA_ID);

        $entity = new OrderAttachmentEntity();
        $entity->setId(self::FIXED_ATTACHMENT_ID);
        $entity->setUniqueIdentifier(self::FIXED_ATTACHMENT_ID);
        $entity->setOrderId('00000000000000000000000000000000');
        $entity->setOrderVersionId('00000000000000000000000000000000');
        $entity->setMediaId(self::FIXED_MEDIA_ID);
        $entity->setOriginalFileName('drawing.pdf');
        $entity->setMimeType('application/pdf');
        $entity->setFileSize(1024);
        $entity->setMedia($media);

        return $entity;
    }

    private function stubStream(string $content): StreamInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('eof')->willReturnOnConsecutiveCalls(false, true);
        $stream->method('read')->willReturn($content);

        return $stream;
    }

    private function customer(): CustomerEntity
    {
        $customer = new CustomerEntity();
        $customer->setId(self::FIXED_CUSTOMER_ID);
        $customer->setUniqueIdentifier(self::FIXED_CUSTOMER_ID);

        return $customer;
    }
}
