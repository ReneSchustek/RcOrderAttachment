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
 * Unit-Tests für den Mail-Anhang-Status-Tracker. Prüft, dass das interne
 * Custom-Field auf `order` korrekt geschrieben wird, dass ungültige Order-IDs
 * keinen DAL-Roundtrip verursachen und dass DAL-Fehler nicht den Aufrufpfad
 * (Subscriber/Handler) brechen.
 */
final class MailAttachmentStatusTrackerTest extends TestCase
{
    private const ORDER_ID = '1234567890abcdef1234567890abcdef';

    /**
     * Was: `markAttached` mit einer gültigen Order-ID.
     * Warum: Der Mail-Versand soll im Custom-Field als „attached" markiert
     *        werden — die Telemetrie ist Pflicht für Operator-Sichtbarkeit.
     * Erwartet: Genau ein `update`-Aufruf mit dem Status `attached` im
     *           Custom-Field-Payload.
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
     * Was: `markPartialFailure` mit gültiger Order-ID.
     * Warum: Bei mindestens einem fehlgeschlagenen Anhang muss der Operator
     *        unterscheiden können, ob die Mail komplett oder nur teilweise
     *        gefehlschlagen ist (Auswirkung auf Kunden-Kommunikation).
     * Erwartet: `update`-Aufruf mit Status `partial_failure`.
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
     * Was: `markFailed` mit ungültiger (Nicht-UUID) Order-ID.
     * Warum: Ein Aufrufer könnte versehentlich Müll übergeben — die DAL würde
     *        eine `InvalidUuidException` werfen und den ohnehin schon
     *        fehlgeschlagenen Mail-Versand zweimal kaputtmachen.
     * Erwartet: Kein `update`-Aufruf, kein Throw — Guard greift früh.
     */
    public function testInvalidOrderIdIsIgnored(): void
    {
        $repo = $this->createMock(EntityRepository::class);
        $repo->expects(self::never())->method('update');

        (new MailAttachmentStatusTracker($repo, new NullLogger()))
            ->markFailed('not-a-uuid', Context::createDefaultContext());
    }

    /**
     * Was: DAL-Repository wirft beim Schreiben des Status eine Exception.
     * Warum: Das Status-Schreiben ist Best-Effort-Telemetrie — wenn die DAL
     *        ausfällt, darf der Mail-Pfad (Subscriber/Handler) nicht
     *        mitsterben. Sonst würde ein einzelner DAL-Hiccup die ganze
     *        Bestätigungs-Mail-Pipeline reißen.
     * Erwartet: Keine Exception nach außen, Warning-Log mit Kontext.
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
