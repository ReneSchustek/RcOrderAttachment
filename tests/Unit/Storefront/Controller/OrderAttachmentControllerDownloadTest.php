<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Tests\Unit\Storefront\Controller;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use Ruhrcoder\RcOrderAttachment\Core\Content\OrderAttachment\OrderAttachmentCollection;
use Ruhrcoder\RcOrderAttachment\Core\Content\OrderAttachment\OrderAttachmentEntity;
use Ruhrcoder\RcOrderAttachment\Service\Config\PluginConfigProvider;
use Ruhrcoder\RcOrderAttachment\Service\OrderAttachmentDownloadService;
use Ruhrcoder\RcOrderAttachment\Service\OrderAttachmentLoader;
use Ruhrcoder\RcOrderAttachment\Service\OrderAttachmentUploadService;
use Ruhrcoder\RcOrderAttachment\Service\RateLimit\UploadRateLimiter;
use Ruhrcoder\RcOrderAttachment\Service\Session\PendingUploadStore;
use Ruhrcoder\RcOrderAttachment\Storefront\Controller\OrderAttachmentController;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Content\Media\MediaService;
use Shopware\Core\Framework\Api\Context\SystemSource;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Der Abruf eines Anhangs aus dem Kundenkonto. Wie im Admin-Pfad ist ein Anhang, dessen Datei die
 * Aufbewahrungsfrist schon gelöscht hat, für den Kunden nicht da: 404, nicht 500.
 */
final class OrderAttachmentControllerDownloadTest extends TestCase
{
    private const ATTACHMENT_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testADeletedFileIsNotFoundForTheCustomer(): void
    {
        $attachment = new OrderAttachmentEntity();
        $attachment->setId(self::ATTACHMENT_ID);
        $attachment->setUniqueIdentifier(self::ATTACHMENT_ID);
        $attachment->setOrderId('00000000000000000000000000000000');
        $attachment->setOrderVersionId('00000000000000000000000000000000');
        $attachment->setMediaId('cccccccccccccccccccccccccccccccc');
        $attachment->setMedia(null);

        $response = $this->controller([$attachment])->accountDownload(self::ATTACHMENT_ID, $this->customerContext());

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
        self::assertStringContainsString('notFound', (string) $response->getContent());
    }

    public function testAnUnknownAttachmentIsNotFound(): void
    {
        $response = $this->controller([])->accountDownload(self::ATTACHMENT_ID, $this->customerContext());

        self::assertSame(Response::HTTP_NOT_FOUND, $response->getStatusCode());
    }

    /**
     * @param list<OrderAttachmentEntity> $entities
     */
    private function controller(array $entities): OrderAttachmentController
    {
        // Der Abruf braucht nur den Download-Dienst; die übrigen Dienste werden nicht berührt.
        return new OrderAttachmentController(
            (new ReflectionClass(OrderAttachmentUploadService::class))->newInstanceWithoutConstructor(),
            new OrderAttachmentDownloadService(new OrderAttachmentLoader($this->repository($entities)), $this->createMock(MediaService::class), new NullLogger()),
            (new ReflectionClass(PendingUploadStore::class))->newInstanceWithoutConstructor(),
            (new ReflectionClass(PluginConfigProvider::class))->newInstanceWithoutConstructor(),
            new UploadRateLimiter(new ArrayAdapter(), new NullLogger()),
            new NullLogger(),
        );
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

    private function customerContext(): SalesChannelContext
    {
        $customer = new CustomerEntity();
        $customer->setId('dddddddddddddddddddddddddddddddd');
        $customer->setGuest(false);

        $context = $this->createMock(SalesChannelContext::class);
        $context->method('getCustomer')->willReturn($customer);
        $context->method('getContext')->willReturn(new Context(new SystemSource()));

        return $context;
    }
}
