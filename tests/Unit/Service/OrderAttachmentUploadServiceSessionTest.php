<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcOrderAttachment\Installer\MediaFolderInstaller;
use Ruhrcoder\RcOrderAttachment\Service\Config\PluginConfig;
use Ruhrcoder\RcOrderAttachment\Service\Exception\AttachmentUploadException;
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
use Shopware\Core\Content\Media\MediaCollection;
use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Prüft das Verhalten des Uploads, wenn keine Session zur Verfügung steht.
 *
 * Ohne Session landet die Datei in keinem Pending-Store — sie könnte später also keiner
 * Bestellung zugeordnet werden. Bis v1.0.5 meldete der Upload trotzdem Erfolg: Der Kunde sah
 * seine Datei in der Liste, angekommen wäre sie nie, und nach Ablauf der Frist hätte der
 * Orphan-Cleanup sie entfernt. Der Fund stammt aus dem Deep-Review vom 2026-07-15
 * („Upload meldet Erfolg trotz verworfenem PendingUpload bei fehlender Session") und war dort
 * nie verifiziert.
 */
#[CoversClass(OrderAttachmentUploadService::class)]
final class OrderAttachmentUploadServiceSessionTest extends TestCase
{
    private string $datei;

    protected function setUp(): void
    {
        $this->datei = sys_get_temp_dir() . '/rc-upload-' . uniqid('', true) . '.pdf';
        file_put_contents($this->datei, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
    }

    protected function tearDown(): void
    {
        if (is_file($this->datei)) {
            unlink($this->datei);
        }
    }

    /**
     * Was: Upload ohne Session.
     * Warum: Eine Datei, die nirgends vermerkt ist, kann keiner Bestellung zugeordnet werden.
     *        Erfolg zu melden wäre eine Lüge gegenüber dem Kunden.
     * Erwartet: Ablehnung mit dem Code `sessionUnavailable` statt eines gültigen Uploads.
     */
    public function testUploadOhneSessionWirdAbgelehnt(): void
    {
        $service = $this->service($this->storeOhneSession(), $this->createMock(EntityRepository::class));

        try {
            $service->upload($this->uploadedFile(), $this->config(), Context::createDefaultContext());
            static::fail('Der Upload haette abgelehnt werden muessen.');
        } catch (AttachmentUploadException $ausnahme) {
            static::assertSame(['sessionUnavailable'], $ausnahme->codes);
        }
    }

    /**
     * Was: Das bereits gespeicherte Medium bei fehlender Session.
     * Warum: Sonst bliebe für jeden solchen Aufruf eine private Kundendatei liegen, die niemand
     *        mehr zuordnen kann — bis der Cleanup sie Stunden später abräumt.
     * Erwartet: Das Medium wird im selben Zug wieder gelöscht.
     */
    public function testMediumWirdBeiFehlenderSessionWiederGeloescht(): void
    {
        $mediaRepository = $this->createMock(EntityRepository::class);
        $mediaRepository->expects(static::once())->method('delete');

        $service = $this->service($this->storeOhneSession(), $mediaRepository);

        try {
            $service->upload($this->uploadedFile(), $this->config(), Context::createDefaultContext());
        } catch (AttachmentUploadException) {
            // erwartet -- geprüft wird der Aufräum-Aufruf oben
        }
    }

    /**
     * Was: Derselbe Upload mit Session.
     * Warum: Gegenprobe — die Ablehnung darf nur an der fehlenden Session hängen, nicht an der
     *        Datei oder der Konfiguration.
     * Erwartet: ein Pending-Upload mit dem sanitisierten Dateinamen.
     */
    public function testMitSessionLaeuftDerselbeUploadDurch(): void
    {
        $session = new \Symfony\Component\HttpFoundation\Session\Session(
            new \Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage()
        );
        $request = new Request();
        $request->setSession($session);
        $stack = new RequestStack();
        $stack->push($request);

        $service = $this->service(new PendingUploadStore($stack), $this->createMock(EntityRepository::class));

        $upload = $service->upload($this->uploadedFile(), $this->config(), Context::createDefaultContext());

        static::assertInstanceOf(PendingUpload::class, $upload);
        static::assertSame('nachweis.pdf', $upload->originalFileName);
    }

    /**
     * Ein Store, dessen RequestStack keinen Request mit Session hergibt — genau die Lage, in
     * der `add()` nichts ablegen kann.
     */
    private function storeOhneSession(): PendingUploadStore
    {
        return new PendingUploadStore(new RequestStack());
    }

    private function uploadedFile(): UploadedFile
    {
        return new UploadedFile($this->datei, 'nachweis.pdf', 'application/pdf', null, true);
    }

    private function config(): PluginConfig
    {
        return new PluginConfig(
            enabled: true,
            required: false,
            maxFiles: 5,
            maxFileSizeKb: 10240,
            maxTotalSizeKb: 40960,
            allowedExtensions: ['pdf'],
            orphanRetentionHours: 24,
            attachmentRetentionDays: 180,
            attachToConfirmationMail: true,
            stripExifFromImages: false,
            rateLimitPerMinute: 30,
            mailTemplateWhitelist: ['order_confirmation_mail'],
        );
    }

    /**
     * @param EntityRepository<MediaCollection> $mediaRepository
     */
    private function service(PendingUploadStore $store, EntityRepository $mediaRepository): OrderAttachmentUploadService
    {
        $folderRepository = $this->createMock(EntityRepository::class);

        // Der MediaService liefert im Betrieb die ID des gespeicherten Mediums zurueck. Ein
        // Double, das null liefert, laesst den Service mit leerer ID weiterlaufen -- dann
        // greift der Uuid-Guard im Aufraeumpfad und der Test scheitert am falschen Grund.
        $mediaService = $this->createMock(MediaService::class);
        $mediaService->method('saveMediaFile')->willReturnCallback(
            // PHPUnit reicht die Argumente positionsbasiert weiter, auch wenn der Service sie
            // benannt uebergibt: 4 = mediaId (mediaFile, filename, context, folder, mediaId, private).
            static fn (mixed ...$argumente): string => (string) ($argumente[4] ?? Uuid::randomHex())
        );

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
            $mediaService,
            $mediaRepository,
            $this->createMock(EntityRepository::class),
            new MediaFolderInstaller($folderRepository, $folderRepository),
            $store,
            new FilenameSanitizer(),
            new ExifStripper(new NullLogger()),
            new NullLogger(),
        );
    }
}
