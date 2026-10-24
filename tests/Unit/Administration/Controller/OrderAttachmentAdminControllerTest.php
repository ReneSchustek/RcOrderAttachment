<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit\Administration\Controller;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;
use Psr\Log\NullLogger;
use Ruhrcoder\RcOrderAttachment\Administration\Controller\OrderAttachmentAdminController;
use Ruhrcoder\RcOrderAttachment\Core\Content\OrderAttachment\OrderAttachmentCollection;
use Ruhrcoder\RcOrderAttachment\Core\Content\OrderAttachment\OrderAttachmentEntity;
use Ruhrcoder\RcOrderAttachment\Service\OrderAttachmentDownloadService;
use Ruhrcoder\RcOrderAttachment\Service\OrderAttachmentLoader;
use RuntimeException;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Prüft, wie der Download-Endpunkt der Verwaltung Ergebnisse und Fehler in HTTP-Antworten
 * übersetzt; der Inhalt des Datenstroms ist nicht Gegenstand. Ein falscher Status verrät
 * entweder die Existenz von Datensätzen oder lässt den Mitarbeiter ohne Kundendatei stehen.
 *
 * Downloaddienst und Loader sind echt, gemockt ist nur das Repository darunter, weil sich die
 * finalen Plugin-Klassen nicht mocken lassen.
 */
final class OrderAttachmentAdminControllerTest extends TestCase
{
    private const ATTACHMENT_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const MEDIA_ID = 'cccccccccccccccccccccccccccccccc';

    /**
     * Der Regelfall der Auftragsbearbeitung: Der Mitarbeiter lädt die Kundendatei direkt aus
     * der Bestellung.
     */
    public function testDownloadStreamsAttachment(): void
    {
        $mediaService = $this->createMock(MediaService::class);
        $mediaService->method('loadFileStream')->willReturn($this->stubStream('DATA'));

        $response = $this->controller($this->repository([$this->attachment()]), $mediaService)
            ->download(self::ATTACHMENT_ID, Context::createDefaultContext());

        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
    }

    /**
     * Eine unbekannte ID ergibt 404 mit festem Fehlercode statt 403 oder 500, damit sich aus
     * der Antwort nicht ablesen lässt, welche IDs es gibt.
     */
    public function testDownloadReturns404WhenAttachmentMissing(): void
    {
        $response = $this->controller($this->repository([]))
            ->download(self::ATTACHMENT_ID, Context::createDefaultContext());

        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertStringContainsString('RC_ORDER_ATTACHMENT__NOT_FOUND', (string) $response->getContent());
    }

    /**
     * Hat eine Bereinigung das Medium schon entfernt, antwortet der Endpunkt mit demselben 404
     * wie bei einem fehlenden Anhang statt mit 500, und die beiden Fälle bleiben ununterscheidbar.
     */
    public function testDownloadReturns404WhenMediaMissing(): void
    {
        $attachment = $this->attachment();
        $attachment->setMedia(null);

        $response = $this->controller($this->repository([$attachment]))
            ->download(self::ATTACHMENT_ID, Context::createDefaultContext());

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    /**
     * Ein Fehler der Infrastruktur wird protokolliert und als 500 mit festem Code beantwortet,
     * ohne Stacktrace in der Antwort. Nur solche Fehler werden 500, alles Fachliche bleibt 404.
     */
    public function testDownloadReturns500OnUnexpectedError(): void
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('search')->willThrowException(new RuntimeException('boom'));

        $response = $this->controller($repository)
            ->download(self::ATTACHMENT_ID, Context::createDefaultContext());

        self::assertSame(Response::HTTP_INTERNAL_SERVER_ERROR, $response->getStatusCode());
        self::assertStringContainsString('RC_ORDER_ATTACHMENT__DOWNLOAD_FAILED', (string) $response->getContent());
    }

    /**
     * @param EntityRepository<OrderAttachmentCollection> $repository
     */
    private function controller(EntityRepository $repository, ?MediaService $mediaService = null): OrderAttachmentAdminController
    {
        $service = new OrderAttachmentDownloadService(
            new OrderAttachmentLoader($repository),
            $mediaService ?? $this->createMock(MediaService::class),
            new NullLogger(),
        );

        return new OrderAttachmentAdminController($service, new NullLogger());
    }

    /**
     * @param list<OrderAttachmentEntity> $entities
     *
     * @return EntityRepository<OrderAttachmentCollection>
     */
    private function repository(array $entities): EntityRepository
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

        return $repository;
    }

    private function attachment(): OrderAttachmentEntity
    {
        $media = new MediaEntity();
        $media->setId(self::MEDIA_ID);
        $media->setUniqueIdentifier(self::MEDIA_ID);

        $entity = new OrderAttachmentEntity();
        $entity->setId(self::ATTACHMENT_ID);
        $entity->setUniqueIdentifier(self::ATTACHMENT_ID);
        $entity->setOrderId('00000000000000000000000000000000');
        $entity->setOrderVersionId('00000000000000000000000000000000');
        $entity->setMediaId(self::MEDIA_ID);
        $entity->setOriginalFileName('drawing.pdf');
        $entity->setMimeType('application/pdf');
        $entity->setFileSize(2048);
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
}
