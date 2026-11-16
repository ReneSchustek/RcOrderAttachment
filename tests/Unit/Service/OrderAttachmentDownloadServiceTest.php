<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit\Service;

use League\Flysystem\UnableToReadFile;
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
 * Prüft den Downloaddienst für Kunde und Verwaltung: Kunden bekommen nur eigene Anhänge, und
 * jeder Download trägt die Kopfzeilen, die das Anzeigen im Browser verhindern.
 *
 * Der {@see OrderAttachmentLoader} ist echt, gemockt ist nur das {@see EntityRepository}, weil
 * sich die finalen Plugin-Klassen nicht mocken lassen.
 */
final class OrderAttachmentDownloadServiceTest extends TestCase
{
    private const FIXED_ATTACHMENT_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const FIXED_CUSTOMER_ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const FIXED_MEDIA_ID = 'cccccccccccccccccccccccccccccccc';

    /**
     * Der Kunde lädt seinen eigenen Anhang im Konto herunter (Auskunft nach Art. 15 DSGVO).
     * `nosniff` und `Content-Disposition: attachment` verhindern, dass der Browser die Datei
     * anders deutet oder direkt anzeigt.
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
     * Probiert jemand fremde IDs durch, muss ein fremder Anhang denselben Fehler liefern wie
     * ein fehlender. Ein 403 verriete, dass es die ID gibt.
     */
    public function testThrowsNotFoundWhenAttachmentBelongsToOtherCustomer(): void
    {
        // Der Eigentümerfilter lässt die Suche leer ausgehen, so wie bei einem fremden Anhang.
        $service = new OrderAttachmentDownloadService(
            $this->loader([]),
            $this->createMock(MediaService::class),
            new NullLogger(),
        );

        $this->expectException(AttachmentNotFoundException::class);
        $service->streamForCustomer(self::FIXED_ATTACHMENT_ID, $this->customer(), Context::createDefaultContext());
    }

    /**
     * Der Anhang ist da, das Medium aber gelöscht. Die eigene Ausnahme trennt diesen defekten
     * Zustand (im Kundenpfad 500) vom fremden oder fehlenden Anhang (404).
     */
    public function testThrowsMediaNotLoadedWhenMediaIsMissing(): void
    {
        $attachment = $this->attachment();
        $attachment->setMedia(null);

        $service = new OrderAttachmentDownloadService(
            $this->loader([$attachment]),
            $this->createMock(MediaService::class),
            new NullLogger(),
        );

        $this->expectException(MediaNotLoadedException::class);
        $service->streamForCustomer(self::FIXED_ATTACHMENT_ID, $this->customer(), Context::createDefaultContext());
    }

    /**
     * Die Auftragsbearbeitung braucht jeden Anhang; die Berechtigung prüft die ACL des
     * Verwaltungsendpunkts, nicht eine Kundenbeziehung. Die Kopfzeilen sind dieselben wie im
     * Kundenpfad.
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
     * Auch in der Verwaltung ergibt eine unbekannte ID nur „nicht vorhanden", das der
     * Controller als 404 beantwortet.
     */
    public function testStreamForAdminThrowsNotFoundWhenAttachmentMissing(): void
    {
        $service = new OrderAttachmentDownloadService($this->loader([]), $this->createMock(MediaService::class), new NullLogger());

        $this->expectException(AttachmentNotFoundException::class);
        $service->streamForAdmin(self::FIXED_ATTACHMENT_ID, Context::createDefaultContext());
    }

    /**
     * Hat eine Bereinigung das Medium schon entfernt, wirft der Dienst eine eigene Ausnahme.
     * Der Verwaltungs-Controller beantwortet sie wie einen fehlenden Anhang mit 404.
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
     * Die übrigen Tests sehen nur „gefunden" oder „leer" und blieben grün, wenn der
     * Eigentümerfilter fehlte und jeder Kunde fremde Anhänge laden könnte. Hier wird die Suche
     * abgefangen und `order.orderCustomer.customerId` mit der eigenen ID verlangt.
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
     * Was: Dateinamen mit Umlaut, Prozentzeichen und anderen Zeichen außerhalb von ASCII.
     * Warum: Symfony wirft sonst beim Bauen der Kopfzeile, der Download endete mit 500.
     * Erwartet: Der Rückfallname ist reines ASCII, der echte Name steht in `filename*`.
     *
     * @dataProvider unusualFilenames
     */
    public function testUnusualFilenamesStillDownload(string $filename, string $expectedFallback): void
    {
        $attachment = $this->attachment();
        $attachment->setOriginalFileName($filename);
        $mediaService = $this->createMock(MediaService::class);
        $mediaService->method('loadFileStream')->willReturn($this->stubStream('X'));

        $service = new OrderAttachmentDownloadService($this->loader([$attachment]), $mediaService, new NullLogger());
        $disposition = $service->streamForAdmin(self::FIXED_ATTACHMENT_ID, Context::createDefaultContext())
            ->headers->get('Content-Disposition') ?? '';

        self::assertStringContainsString($expectedFallback, $disposition);
        self::assertStringContainsString("filename*=utf-8''", $disposition);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unusualFilenames(): array
    {
        return [
            'Umlaut' => ['Schrägschnitt.pdf', 'filename=Schraegschnitt.pdf'],
            'Prozent' => ['100%-Maß.pdf', 'filename=100_-Mass.pdf'],
            'anderes Zeichen' => ['Länge€.pdf', 'filename=Laenge_.pdf'],
        ];
    }

    /**
     * Was: Der Datensatz des Mediums besteht, die Datei fehlt im Speicher.
     * Warum: Der Strom öffnete erst nach den Kopfzeilen, der Kunde bekam ein leeres 200 statt einer Fehlermeldung.
     * Erwartet: dieselbe Ausnahme wie bei fehlendem Medium, die beide Aufrufer als 404 beantworten.
     */
    public function testAMissingFileIsReportedBeforeTheResponseStarts(): void
    {
        $mediaService = $this->createMock(MediaService::class);
        $mediaService->method('loadFileStream')->willThrowException(UnableToReadFile::fromLocation('weg.pdf'));

        $service = new OrderAttachmentDownloadService($this->loader([$this->attachment()]), $mediaService, new NullLogger());

        $this->expectException(MediaNotLoadedException::class);
        $service->streamForAdmin(self::FIXED_ATTACHMENT_ID, Context::createDefaultContext());
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
