<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcOrderAttachment\Core\Content\OrderAttachment\OrderAttachmentCollection;
use Ruhrcoder\RcOrderAttachment\Installer\MediaFolderInstaller;
use Ruhrcoder\RcOrderAttachment\Service\Filename\FilenameSanitizer;
use Ruhrcoder\RcOrderAttachment\Service\Image\ExifStripper;
use Ruhrcoder\RcOrderAttachment\Service\OrderAttachmentUploadService;
use Ruhrcoder\RcOrderAttachment\Service\Session\PendingUpload;
use Ruhrcoder\RcOrderAttachment\Service\Session\PendingUploadStore;
use Ruhrcoder\RcOrderAttachment\Service\Validation\DangerousContentValidator;
use Ruhrcoder\RcOrderAttachment\Service\Validation\ExtensionValidator;
use Ruhrcoder\RcOrderAttachment\Service\Validation\FileCountValidator;
use Ruhrcoder\RcOrderAttachment\Service\Validation\FileSizeValidator;
use Ruhrcoder\RcOrderAttachment\Service\Validation\MagicBytesValidator;
use Ruhrcoder\RcOrderAttachment\Service\Validation\MimeTypeValidator;
use Ruhrcoder\RcOrderAttachment\Service\Validation\TotalSizeValidator;
use Ruhrcoder\RcOrderAttachment\Service\Validation\UploadValidator;
use RuntimeException;
use Shopware\Core\Content\Media\MediaCollection;
use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Prüft {@see OrderAttachmentUploadService::remove()}, das Entfernen eines Uploads vor dem
 * Absenden. Der Fremdschlüssel `fk.rc_order_attachment.media_id` steht auf `ON DELETE CASCADE`,
 * jedes gelöschte Medium reißt also den Anhang jeder Bestellung mit, die darauf zeigt.
 *
 * Die Plugin-Klassen sind echt, gemockt sind nur die Grenzen zu Shopware.
 */
final class OrderAttachmentUploadServiceRemoveTest extends TestCase
{
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const MEDIA_ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    // Ein beliebiger fester Zeitstempel (November 2023), damit der Test nicht von der Uhr abhängt.
    private const UPLOADED_AT = 1_700_000_000;

    /**
     * Bleibt ein Eintrag nach dem Bestellabschluss in der Sitzung stehen, zeigt die
     * Bestätigungsseite des nächsten Warenkorbs ihn samt gültigem Token zum Entfernen. Löschte
     * ein Klick darauf das Medium, risse die Kaskade den Anhang der abgeschlossenen Bestellung
     * mit, und der Support stünde vor `attached` ohne Datei. Nur der Sitzungseintrag geht.
     */
    public function testDoesNotDeleteMediaAlreadyBoundToAnOrder(): void
    {
        $store = $this->storeWithPendingUpload();

        $mediaRepository = $this->createMock(EntityRepository::class);
        $mediaRepository->expects(self::never())->method('delete');

        $service = $this->service($store, $mediaRepository, boundAttachments: 1);
        $service->remove(self::TOKEN, Context::createDefaultContext());

        self::assertSame(0, $store->count(), 'Der Session-Eintrag muss trotzdem verschwinden');
    }

    /**
     * Der Normalfall: Der Kunde entfernt eine Datei vor dem Absenden. Medium und
     * Sitzungseintrag gehen beide, sonst bliebe eine verwaiste Datei liegen.
     */
    public function testDeletesMediaWhenNotBoundToAnyOrder(): void
    {
        $store = $this->storeWithPendingUpload();

        $mediaRepository = $this->createMock(EntityRepository::class);
        $mediaRepository->expects(self::once())->method('delete');

        $service = $this->service($store, $mediaRepository, boundAttachments: 0);
        $service->remove(self::TOKEN, Context::createDefaultContext());

        self::assertSame(0, $store->count());
    }

    /**
     * Lässt sich die Bindung nicht prüfen, bleibt das Medium stehen. Ein verwaistes Medium
     * räumt die Waisenbereinigung später ab, ein zerstörter Bestellanhang ist verloren.
     */
    public function testDoesNotDeleteMediaWhenBoundCheckFails(): void
    {
        $store = $this->storeWithPendingUpload();

        $mediaRepository = $this->createMock(EntityRepository::class);
        $mediaRepository->expects(self::never())->method('delete');

        $attachmentRepository = $this->createMock(EntityRepository::class);
        $attachmentRepository->method('searchIds')->willThrowException(new RuntimeException('db down'));

        $service = $this->service($store, $mediaRepository, boundAttachments: 0, attachmentRepository: $attachmentRepository);
        $service->remove(self::TOKEN, Context::createDefaultContext());

        self::assertSame(0, $store->count());
    }

    /**
     * Ein fremder oder abgelaufener Token findet im Speicher nichts, und der Dienst steigt ohne
     * Abfrage und ohne Löschen aus.
     */
    public function testUnknownTokenIsANoOp(): void
    {
        $store = $this->storeWithPendingUpload();

        $mediaRepository = $this->createMock(EntityRepository::class);
        $mediaRepository->expects(self::never())->method('delete');

        $attachmentRepository = $this->createMock(EntityRepository::class);
        $attachmentRepository->expects(self::never())->method('searchIds');

        $service = $this->service($store, $mediaRepository, boundAttachments: 0, attachmentRepository: $attachmentRepository);
        $service->remove('cccccccccccccccccccccccccccccccc', Context::createDefaultContext());

        self::assertSame(1, $store->count(), 'Der fremde Token darf den eigenen Eintrag nicht entfernen');
    }

    private function storeWithPendingUpload(): PendingUploadStore
    {
        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);
        $stack = new RequestStack();
        $stack->push($request);

        $store = new PendingUploadStore($stack);
        $store->add(new PendingUpload(
            self::TOKEN,
            self::MEDIA_ID,
            'zeichnung.pdf',
            'application/pdf',
            1234,
            self::UPLOADED_AT,
        ));

        return $store;
    }

    /**
     * @param EntityRepository<OrderAttachmentCollection> $attachmentRepository
     * @param EntityRepository<MediaCollection> $mediaRepository
     */
    private function service(
        PendingUploadStore $store,
        EntityRepository $mediaRepository,
        int $boundAttachments,
        ?EntityRepository $attachmentRepository = null,
    ): OrderAttachmentUploadService {
        $attachmentRepository ??= $this->attachmentRepository($boundAttachments);

        $folderRepository = $this->createMock(EntityRepository::class);

        return new OrderAttachmentUploadService(
            new UploadValidator(
                new ExtensionValidator(),
                new DangerousContentValidator(),
                new MimeTypeValidator(),
                new MagicBytesValidator(),
                new FileSizeValidator(),
                new TotalSizeValidator(),
                new FileCountValidator(),
            ),
            $this->createMock(MediaService::class),
            $mediaRepository,
            $attachmentRepository,
            new MediaFolderInstaller($folderRepository, $folderRepository),
            $store,
            new FilenameSanitizer(),
            new ExifStripper(new NullLogger()),
            new NullLogger(),
        );
    }

    /**
     * Das Double wertet die Criteria aus und liefert nur Treffer, wenn auf `mediaId` mit dem zu
     * löschenden Medium gefiltert wird. Ein Double, das sie übergeht, bestätigte den Schutz auch
     * bei falschem Feld oder Wert, und der Test bewiese nichts.
     *
     * @return EntityRepository<OrderAttachmentCollection>
     */
    private function attachmentRepository(int $total): EntityRepository
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('searchIds')->willReturnCallback(
            static function (Criteria $criteria, Context $context) use ($total): IdSearchResult {
                $touchesMedia = false;
                foreach ($criteria->getFilters() as $filter) {
                    if ($filter instanceof EqualsFilter
                        && $filter->getField() === 'mediaId'
                        && $filter->getValue() === self::MEDIA_ID
                    ) {
                        $touchesMedia = true;
                    }
                }

                $match = $touchesMedia ? $total : 0;

                // IdSearchResult erwartet die Treffer nach ihrem Schlüssel abgelegt,
                // nicht als fortlaufende Liste.
                $entries = [];
                foreach ($match > 0 ? range(1, $match) : [] as $i) {
                    // Hexadezimal wie eine echte Kennung und mit führendem `f`, damit PHP den
                    // Schlüssel nicht als Zahlentext in einen Ganzzahlschlüssel umwandelt.
                    $key = \sprintf('f%031x', $i);
                    $entries[$key] = ['primaryKey' => $key, 'data' => []];
                }

                return new IdSearchResult(
                    $match,
                    $entries,
                    $criteria,
                    $context,
                );
            },
        );

        return $repository;
    }
}
