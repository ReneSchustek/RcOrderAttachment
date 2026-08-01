<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ruhrcoder\RcOrderAttachment\Installer\MediaFolderInstaller;
use Ruhrcoder\RcOrderAttachment\Service\Config\PluginConfig;
use Ruhrcoder\RcOrderAttachment\Service\Filename\FilenameSanitizer;
use Ruhrcoder\RcOrderAttachment\Service\Image\ExifStripper;
use Ruhrcoder\RcOrderAttachment\Service\Media\OrderAttachmentUploadScope;
use Ruhrcoder\RcOrderAttachment\Service\OrderAttachmentUploadService;
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
use Shopware\Core\Content\Media\File\MediaFile;
use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\Framework\Uuid\Uuid;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Pinnt den Lebenszyklus des Herkunfts-Markers {@see OrderAttachmentUploadScope}.
 *
 * Der Marker ist die einzige Information, an der der
 * {@see \Ruhrcoder\RcOrderAttachment\Service\Subscriber\MediaWhitelistSubscriber}
 * einen Plugin-Upload erkennt. Zwei Eigenschaften müssen gelten und sind beide
 * still brechbar:
 *
 * - Fehlt er beim Schreiben, lehnt der Core-Validator konfigurierte Sonder-Endungen ab.
 * - Bleibt er danach stehen, erbt jeder weitere Media-Upload desselben Requests die
 *   Plugin-Endungen — die shopweite Ausweitung wäre zurück.
 */
final class OrderAttachmentUploadServiceWhitelistScopeTest extends TestCase
{
    private const FOLDER_ID = 'cccccccccccccccccccccccccccccccc';

    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->cleanup = [];
    }

    /**
     * Was: Ein regulärer Upload.
     * Warum: Der Marker muss genau während des Media-Schreibens am Context hängen
     *        und danach wieder weg sein.
     * Erwartet: Während `saveMediaFile` gesetzt, nach `upload()` nicht mehr.
     */
    public function testMarksContextForTheMediaWriteAndCleansUpAfterwards(): void
    {
        $context = Context::createDefaultContext();
        $markedDuringWrite = null;

        $service = $this->service($this->mediaServiceRecording($markedDuringWrite));
        $service->upload($this->pdfFile(), $this->config(), $context);

        self::assertTrue($markedDuringWrite, 'Ohne Marker lehnt der Core-Validator konfigurierte Endungen ab');
        self::assertFalse(
            $context->hasExtension(OrderAttachmentUploadScope::NAME),
            'Der Marker darf den Speicheraufruf nicht überleben — sonst erbt jeder weitere Upload des Requests die Plugin-Endungen',
        );
    }

    /**
     * Was: Das Media-Schreiben scheitert (Storage weg, Validator lehnt ab).
     * Warum: Der Aufräum-Pfad ist der stillere von beiden. Ohne `finally` bliebe der
     *        Marker nach jedem Fehlschlag am Context des Requests hängen.
     * Erwartet: Exception kommt durch, Marker ist trotzdem entfernt.
     */
    public function testMarkerIsRemovedWhenTheMediaWriteFails(): void
    {
        $context = Context::createDefaultContext();

        $mediaService = $this->createMock(MediaService::class);
        $mediaService->method('saveMediaFile')->willThrowException(new RuntimeException('storage down'));

        $service = $this->service($mediaService);

        try {
            $service->upload($this->pdfFile(), $this->config(), $context);
            self::fail('Der Fehlschlag muss durchschlagen');
        } catch (RuntimeException) {
            // erwartet — geprüft wird der Zustand danach
        }

        self::assertFalse(
            $context->hasExtension(OrderAttachmentUploadScope::NAME),
            'Nach einem Fehlschlag muss der Marker ebenso verschwinden wie nach Erfolg',
        );
    }

    /**
     * MediaService-Mock, der festhält, ob der Marker beim Schreiben gesetzt war.
     */
    private function mediaServiceRecording(?bool &$markedDuringWrite): MediaService
    {
        $mediaService = $this->createMock(MediaService::class);
        $mediaService->method('saveMediaFile')->willReturnCallback(
            static function (
                MediaFile $mediaFile,
                string $filename,
                Context $writeContext,
                ?string $folder = null,
                ?string $mediaId = null,
                bool $private = true,
            ) use (&$markedDuringWrite): string {
                $markedDuringWrite = $writeContext->hasExtension(OrderAttachmentUploadScope::NAME);

                return $mediaId ?? Uuid::randomHex();
            },
        );

        return $mediaService;
    }

    private function service(MediaService $mediaService): OrderAttachmentUploadService
    {
        $session = new Session(new MockArraySessionStorage());
        $requestStack = new RequestStack();
        $request = new Request();
        $request->setSession($session);
        $requestStack->push($request);

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
            $this->createMock(EntityRepository::class),
            $this->createMock(EntityRepository::class),
            new MediaFolderInstaller($this->folderRepository(), $this->createMock(EntityRepository::class)),
            new PendingUploadStore($requestStack),
            new FilenameSanitizer(),
            new ExifStripper(new NullLogger()),
            new NullLogger(),
        );
    }

    /**
     * Repository, das den Plugin-Media-Folder als vorhanden meldet — sonst legt der
     * Installer ihn an, was für diesen Test nichts beiträgt.
     */
    private function folderRepository(): EntityRepository
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('searchIds')->willReturnCallback(
            static fn (Criteria $criteria, Context $context): IdSearchResult => new IdSearchResult(
                1,
                [['primaryKey' => self::FOLDER_ID, 'data' => []]],
                $criteria,
                $context,
            ),
        );

        return $repository;
    }

    private function pdfFile(): UploadedFile
    {
        $dir = sys_get_temp_dir() . '/rc-order-attachment-whitelist-scope-tests';
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        $path = $dir . '/test-' . Uuid::randomHex() . '-zeichnung.pdf';
        file_put_contents($path, "%PDF-1.5\nrest-bytes" . str_repeat('a', 200));
        $this->cleanup[] = $path;

        return new UploadedFile($path, 'zeichnung.pdf', null, null, true);
    }

    private function config(): PluginConfig
    {
        return new PluginConfig(
            enabled: true,
            required: false,
            maxFiles: 5,
            maxFileSizeKb: 5120,
            maxTotalSizeKb: 20480,
            allowedExtensions: ['pdf'],
            orphanRetentionHours: 24,
            attachmentRetentionDays: 180,
            attachToConfirmationMail: true,
            stripExifFromImages: false,
            rateLimitPerMinute: 30,
            mailTemplateWhitelist: [],
        );
    }
}
