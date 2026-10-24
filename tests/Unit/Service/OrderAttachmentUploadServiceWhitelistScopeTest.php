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
use Shopware\Core\Content\Media\Aggregate\MediaFolder\MediaFolderCollection;
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
 * Hält den Lebenszyklus der Herkunftsmarke {@see OrderAttachmentUploadScope} fest, an der allein
 * der {@see \Ruhrcoder\RcOrderAttachment\Service\Subscriber\MediaWhitelistSubscriber} einen
 * Upload des Plugins erkennt.
 *
 * Beide Fehler blieben still: Fehlt die Marke beim Schreiben, lehnt der Validator des Kerns die
 * eingestellten zusätzlichen Endungen ab. Bleibt sie danach stehen, erbt jeder weitere Upload
 * derselben Anfrage die Endungen des Plugins, und die Freigabe gälte wieder shopweit.
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
     * Die Marke hängt genau während `saveMediaFile()` am Kontext und ist nach `upload()` weg.
     */
    public function testMarksContextForTheMediaWriteAndCleansUpAfterwards(): void
    {
        $context = Context::createDefaultContext();
        $markedDuringWrite = null;
        $extensionsDuringWrite = null;

        $service = $this->service($this->mediaServiceRecording($markedDuringWrite, $extensionsDuringWrite));
        $service->upload($this->pdfFile(), $this->config(), $context);

        self::assertTrue($markedDuringWrite, 'Ohne Marker lehnt der Core-Validator konfigurierte Endungen ab');
        self::assertSame(['pdf'], $extensionsDuringWrite, 'Der Marker trägt die Endungen des Kanals, mit denen geprüft wurde');
        self::assertFalse(
            $context->hasExtension(OrderAttachmentUploadScope::NAME),
            'Der Marker darf den Speicheraufruf nicht überleben — sonst erbt jeder weitere Upload des Requests die Plugin-Endungen',
        );
    }

    /**
     * Der Fehlerweg ist der stillere: Ohne `finally` bliebe die Marke nach jedem gescheiterten
     * Schreiben am Kontext der Anfrage hängen. Die Ausnahme selbst kommt durch.
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
            // Die Ausnahme ist erwartet, geprüft wird der Zustand danach.
        }

        self::assertFalse(
            $context->hasExtension(OrderAttachmentUploadScope::NAME),
            'Nach einem Fehlschlag muss der Marker ebenso verschwinden wie nach Erfolg',
        );
    }

    /**
     * Hält fest, ob die Marke beim Schreiben am Kontext hing.
     */
    /**
     * @param list<string>|null $extensionsDuringWrite
     */
    private function mediaServiceRecording(?bool &$markedDuringWrite, ?array &$extensionsDuringWrite = null): MediaService
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
            ) use (&$markedDuringWrite, &$extensionsDuringWrite): string {
                $markedDuringWrite = $writeContext->hasExtension(OrderAttachmentUploadScope::NAME);
                $scope = $writeContext->getExtension(OrderAttachmentUploadScope::NAME);
                $extensionsDuringWrite = $scope instanceof OrderAttachmentUploadScope ? $scope->allowedExtensions : null;

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
     * Meldet den Medienordner des Plugins als vorhanden, sonst legte der Installer ihn an, was
     * hier nichts beiträgt.
     *
     * @return EntityRepository<MediaFolderCollection>
     */
    private function folderRepository(): EntityRepository
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository->method('searchIds')->willReturnCallback(
            static fn (Criteria $criteria, Context $context): IdSearchResult => new IdSearchResult(
                1,
                [self::FOLDER_ID => ['primaryKey' => self::FOLDER_ID, 'data' => []]],
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
