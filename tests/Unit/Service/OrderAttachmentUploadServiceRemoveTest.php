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
 * Unit-Tests für {@see OrderAttachmentUploadService::remove()}.
 *
 * Der Remove-Pfad ist sicherheitskritisch: Der Fremdschlüssel
 * `fk.rc_order_attachment.media_id` steht auf `ON DELETE CASCADE`. Ein Media-Delete
 * reisst den Anhang jeder Bestellung mit, die darauf zeigt.
 *
 * Pattern: echte Instanzen mit gemockten Shopware-Boundaries.
 */
final class OrderAttachmentUploadServiceRemoveTest extends TestCase
{
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const MEDIA_ID = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const UPLOADED_AT = 1_700_000_000;

    /**
     * Was: Ein Pending-Upload, dessen Media bereits an eine Bestellung gebunden ist.
     * Warum: REGRESSION mit Datenverlust. Nach dem Order-Placement blieb der Eintrag
     *        in der Session stehen; die Confirm-Page des nächsten Warenkorbs zeigte ihn
     *        samt gültigem Remove-Token. Ein Klick löschte das Media — und der
     *        FK-Cascade riss den Anhang der abgeschlossenen Bestellung mit. Der Support
     *        stand vor einer Bestellung mit Status `attached`, aber ohne Datei.
     * Erwartet: KEIN Media-Delete. Der Session-Eintrag verschwindet trotzdem.
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
     * Was: Ein Pending-Upload, der noch zu keiner Bestellung gehört.
     * Warum: Der Normalfall — der Customer entfernt eine Datei vor dem Absenden.
     *        Media und Session-Eintrag müssen beide weg, sonst bleibt eine verwaiste
     *        Datei auf der Platte liegen.
     * Erwartet: Genau ein Media-Delete, Session-Eintrag entfernt.
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
     * Was: Die DAL wirft beim Prüfen der Bindung (DB weg, Scope-Problem).
     * Warum: Fail-closed. Ein verwaistes Media räumt der Orphan-Cleanup-Cron ab;
     *        ein zerstörter Bestell-Anhang ist unwiederbringlich.
     * Erwartet: KEIN Media-Delete.
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
     * Was: Unbekannter Token.
     * Warum: Ein fremder oder abgelaufener Token darf nichts anfassen — der Store
     *        liefert `null`, der Service muss geräuschlos aussteigen.
     * Erwartet: Kein Media-Delete, keine Bindungs-Abfrage.
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
     * Repository-Double, das die Criteria **honoriert**: Es liefert nur dann Treffer, wenn
     * tatsächlich auf `mediaId` = das zu löschende Media gefiltert wird.
     *
     * Ein Double, das die Criteria ignoriert, würde den Cascade-Schutz auch dann bestätigen,
     * wenn der Service auf das falsche Feld oder den falschen Wert filtert — der Test wäre
     * vakuant.
     *
     * @return EntityRepository<OrderAttachmentCollection>
     */
    private function attachmentRepository(int $total): EntityRepository
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('searchIds')->willReturnCallback(
            static function (Criteria $criteria, Context $context) use ($total): IdSearchResult {
                $trifftMedia = false;
                foreach ($criteria->getFilters() as $filter) {
                    if ($filter instanceof EqualsFilter
                        && $filter->getField() === 'mediaId'
                        && $filter->getValue() === self::MEDIA_ID
                    ) {
                        $trifftMedia = true;
                    }
                }

                $treffer = $trifftMedia ? $total : 0;

                // IdSearchResult erwartet die Treffer nach ihrem Schlüssel abgelegt,
                // nicht als fortlaufende Liste.
                $eintraege = [];
                foreach ($treffer > 0 ? range(1, $treffer) : [] as $i) {
                    $schluessel = \sprintf('%032d', $i);
                    $eintraege[$schluessel] = ['primaryKey' => $schluessel, 'data' => []];
                }

                // PHP macht aus einem rein numerischen Zeichenketten-Schlüssel keinen
                // Ganzzahl-Schluessel, solange führende Nullen dranstehen; die Analyse
                // sieht das nicht und weitet den Typ auf int|string.
                /** @var array<string, array{primaryKey: string, data: array<string, mixed>}> $eintraege */

                return new IdSearchResult(
                    $treffer,
                    $eintraege,
                    $criteria,
                    $context,
                );
            },
        );

        return $repository;
    }
}
