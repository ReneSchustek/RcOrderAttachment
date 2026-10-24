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
 * Prüft den Upload ohne Sitzung. Die Datei landet dann in keinem Speicher offener Uploads und
 * kann später keiner Bestellung zugeordnet werden.
 *
 * Meldete der Upload trotzdem Erfolg, sähe der Kunde seine Datei in der Liste, angekommen wäre
 * sie nie, und nach Ablauf der Frist räumte die Waisenbereinigung sie ab.
 */
#[CoversClass(OrderAttachmentUploadService::class)]
final class OrderAttachmentUploadServiceSessionTest extends TestCase
{
    private string $tempFile;

    protected function setUp(): void
    {
        $this->tempFile = sys_get_temp_dir() . '/rc-upload-' . uniqid('', true) . '.pdf';
        file_put_contents($this->tempFile, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
    }

    protected function tearDown(): void
    {
        if (is_file($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    /**
     * Eine nirgends vermerkte Datei kann keiner Bestellung zugeordnet werden; der Upload wird
     * deshalb mit `sessionUnavailable` abgelehnt, statt Erfolg zu melden.
     */
    public function testUploadWithoutSessionIsRejected(): void
    {
        $service = $this->service($this->storeWithoutSession(), $this->createMock(EntityRepository::class));

        try {
            $service->upload($this->uploadedFile(), $this->config(), Context::createDefaultContext());
            static::fail('Der Upload hätte abgelehnt werden müssen.');
        } catch (AttachmentUploadException $exception) {
            static::assertSame(['sessionUnavailable'], $exception->codes);
        }
    }

    /**
     * Das schon gespeicherte Medium wird im selben Zug wieder gelöscht. Sonst bliebe je Aufruf
     * eine private Kundendatei liegen, bis die Waisenbereinigung sie Stunden später abräumt.
     */
    public function testTheMediaIsDeletedAgainWithoutSession(): void
    {
        $mediaRepository = $this->createMock(EntityRepository::class);
        $mediaRepository->expects(static::once())->method('delete');

        $service = $this->service($this->storeWithoutSession(), $mediaRepository);

        try {
            $service->upload($this->uploadedFile(), $this->config(), Context::createDefaultContext());
        } catch (AttachmentUploadException) {
            // Die Ablehnung ist erwartet, geprüft wird der Löschaufruf oben.
        }
    }

    /**
     * Gegenprobe: Mit Sitzung geht derselbe Upload durch. Die Ablehnung hängt also nur an der
     * fehlenden Sitzung und nicht an Datei oder Konfiguration.
     */
    public function testTheSameUploadPassesWithASession(): void
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
     * Der `RequestStack` ist leer, es gibt also keine Sitzung, in der `add()` etwas ablegen könnte.
     */
    private function storeWithoutSession(): PendingUploadStore
    {
        return new PendingUploadStore(new RequestStack());
    }

    private function uploadedFile(): UploadedFile
    {
        return new UploadedFile($this->tempFile, 'nachweis.pdf', 'application/pdf', null, true);
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

        // Im Betrieb gibt der MediaService die ID des gespeicherten Mediums zurück. Lieferte das
        // Double null, liefe der Dienst mit leerer ID weiter, die UUID-Prüfung im Aufräumweg
        // griffe, und der Test scheiterte aus dem falschen Grund.
        $mediaService = $this->createMock(MediaService::class);
        $mediaService->method('saveMediaFile')->willReturnCallback(
            // PHPUnit reicht die Argumente nach Position weiter, auch wenn der Dienst sie benannt
            // übergibt. Index 4 ist `mediaId` (mediaFile, filename, context, folder, mediaId, private).
            static fn (mixed ...$arguments): string => (string) ($arguments[4] ?? Uuid::randomHex())
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
