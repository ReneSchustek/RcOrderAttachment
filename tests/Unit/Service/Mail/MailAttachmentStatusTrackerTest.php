<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit\Service\Mail;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Ruhrcoder\RcOrderAttachment\Installer\CustomFieldInstaller;
use Ruhrcoder\RcOrderAttachment\Service\Mail\MailAttachmentStatusTracker;
use RuntimeException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;

/**
 * Prüft, wie der Status des Mailanhangs im internen Zusatzfeld der Bestellung landet. Der
 * Betrieb liest dort ab, ob die Kundendatei angekommen ist; ungültige IDs erreichen die DAL nicht,
 * und ein Schreibfehler bricht weder Subscriber noch Handler ab.
 */
final class MailAttachmentStatusTrackerTest extends TestCase
{
    private const ORDER_ID = '1234567890abcdef1234567890abcdef';

    /**
     * Nur am Zusatzfeld sieht der Betrieb, dass die Datei mit der Mail hinausging.
     */
    public function testMarkAttachedWritesCustomField(): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->expects(self::once())
            ->method('update')
            ->with([[
                'id' => self::ORDER_ID,
                'customFields' => [
                    CustomFieldInstaller::FIELD_MAIL_STATUS => CustomFieldInstaller::STATUS_ATTACHED,
                ],
            ]]);

        (new MailAttachmentStatusTracker($repo, new NullLogger()))
            ->markAttached(self::ORDER_ID, Context::createDefaultContext());
    }

    /**
     * Der Betrieb muss unterscheiden können, ob gar kein oder nur ein Teil der Anhänge fehlt,
     * denn davon hängt ab, was er dem Kunden schreibt.
     */
    public function testMarkPartialFailureWritesPartialFailureStatus(): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->expects(self::once())
            ->method('update')
            ->with([[
                'id' => self::ORDER_ID,
                'customFields' => [
                    CustomFieldInstaller::FIELD_MAIL_STATUS => CustomFieldInstaller::STATUS_PARTIAL_FAILURE,
                ],
            ]]);

        (new MailAttachmentStatusTracker($repo, new NullLogger()))
            ->markPartialFailure(self::ORDER_ID, Context::createDefaultContext());
    }

    /**
     * Mit einer ungültigen ID würfe die DAL eine `InvalidUuidException` und setzte dem ohnehin
     * gescheiterten Versand einen zweiten Fehler obendrauf. Die Prüfung vorab verhindert den Aufruf.
     */
    public function testInvalidOrderIdIsIgnored(): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->expects(self::never())->method('update');

        (new MailAttachmentStatusTracker($repo, new NullLogger()))
            ->markFailed('not-a-uuid', Context::createDefaultContext());
    }

    /**
     * Der Status ist eine Zusatzinformation. Ein kurzer Ausfall der DAL darf deshalb nicht den
     * Versand der Bestätigungsmail mitreißen; der Fehler landet als Warnung im Protokoll.
     */
    public function testRepositoryFailureIsLoggedNotPropagated(): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('update')->willThrowException(new RuntimeException('DAL kaputt'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        (new MailAttachmentStatusTracker($repo, $logger))
            ->markFailed(self::ORDER_ID, Context::createDefaultContext());
    }
}
