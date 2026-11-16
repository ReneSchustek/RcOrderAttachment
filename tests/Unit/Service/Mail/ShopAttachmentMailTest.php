<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit\Service\Mail;

use PHPUnit\Framework\TestCase;
use Ruhrcoder\RcOrderAttachment\Service\Mail\ShopAttachmentMail;

/**
 * Empfänger und Größengrenze der Mail an den Shop.
 */
final class ShopAttachmentMailTest extends TestCase
{
    public function testFilesUpToTheLimitAreAttached(): void
    {
        self::assertTrue(ShopAttachmentMail::fitsIntoMail(20480 * 1024, 20480));
        self::assertFalse(ShopAttachmentMail::fitsIntoMail(20480 * 1024 + 1, 20480));
    }

    public function testZeroMeansNeverAttach(): void
    {
        self::assertFalse(ShopAttachmentMail::fitsIntoMail(1, 0));
    }

    public function testTheConfiguredRecipientWins(): void
    {
        self::assertSame('anhang@example.com', ShopAttachmentMail::recipient(' anhang@example.com ', 'shop@example.com'));
    }

    public function testFallsBackToTheShopAddress(): void
    {
        self::assertSame('shop@example.com', ShopAttachmentMail::recipient('', 'shop@example.com'));
        self::assertSame('shop@example.com', ShopAttachmentMail::recipient('keine adresse', 'shop@example.com'));
    }

    public function testWithoutAnyAddressThereIsNoRecipient(): void
    {
        self::assertNull(ShopAttachmentMail::recipient('', ''));
    }
}
