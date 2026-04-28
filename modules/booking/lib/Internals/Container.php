<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals;

use Bitrix\Booking\Internals\Integration\Catalog\SkuDataLoader;
use Bitrix\Booking\Internals\Integration\Catalog\ServiceSkuProvider;
use Bitrix\Booking\Internals\Integration\Crm\ContactSearcher\ContactSearcherService;
use Bitrix\Booking\Internals\Integration\Crm\MyCompanyProvider;
use Bitrix\Booking\Internals\Integration\Crm\WebForm\EventHandler as WebFormEventHandler;
use Bitrix\Booking\Internals\Integration\Crm\WebForm\BookingBuilder;
use Bitrix\Booking\Internals\Integration\Crm\DataLoader\DealDataLoader;
use Bitrix\Booking\Internals\Integration\Crm\ClientAccessProvider;
use Bitrix\Booking\Internals\Integration\Crm\ClientDataProvider;
use Bitrix\Booking\Internals\Integration\Crm\ClientDataRecentProvider;
use Bitrix\Booking\Internals\Integration\Crm\ClientTypeRepository as CrmClientTypeRepository;
use Bitrix\Booking\Internals\Integration\Crm\DataLoader\ClientDataLoader;
use Bitrix\Booking\Internals\Integration\Crm\DealDataProvider;
use Bitrix\Booking\Internals\Integration\Crm\ExternalDataItemExtractor;
use Bitrix\Booking\Internals\Integration\Crm\DealClientSynchronizer;
use Bitrix\Booking\Internals\Integration\Crm\MessageSender;
use Bitrix\Booking\Internals\Integration\Crm\DealService;
use Bitrix\Booking\Internals\Integration\Crm\DataLoader\ProductRowDataLoader;
use Bitrix\Booking\Internals\Integration\Intranet\BookingTool;
use Bitrix\Booking\Internals\Repository\BookingMessageRepositoryInterface;
use Bitrix\Booking\Internals\Repository\BookingSkuRepositoryInterface;
use Bitrix\Booking\Internals\Repository\ClientTypeRepositoryInterface;
use Bitrix\Booking\Internals\Repository\ORM\BookingPaymentRepository;
use Bitrix\Booking\Internals\Repository\ORM\DelayedTaskRepository;
use Bitrix\Booking\Internals\Repository\ORM\Mapper\BookingSkuMapper;
use Bitrix\Booking\Internals\Repository\ORM\Mapper\ClientTypeMapper;
use Bitrix\Booking\Internals\Repository\ORM\Mapper\WaitListItemMapper;
use Bitrix\Booking\Internals\Repository\ORM\ResourceLinkedEntityRepository;
use Bitrix\Booking\Internals\Repository\ORM\ResourceSkuRepository;
use Bitrix\Booking\Internals\Repository\ORM\ResourceSkuYandexRepository;
use Bitrix\Booking\Internals\Repository\WaitListItemRepositoryInterface;
use Bitrix\Booking\Internals\Service\BookingService;
use Bitrix\Booking\Internals\Service\BookingSkuService;
use Bitrix\Booking\Internals\Service\ClientService;
use Bitrix\Booking\Internals\Service\CrmForm\CrmFormService;
use Bitrix\Booking\Internals\Service\CrmForm\ResourceAutoSelectionService;
use Bitrix\Booking\Internals\Service\DealForBookingService;
use Bitrix\Booking\Internals\Service\DelayedTask\DelayedTaskService;
use Bitrix\Booking\Internals\Service\EventForBookingService;
use Bitrix\Booking\Internals\Service\ExternalDataService;
use Bitrix\Booking\Internals\Service\Journal\JournalServiceInterface;
use Bitrix\Booking\Internals\Service\LicenseChecker;
use Bitrix\Booking\Internals\Repository\AdvertisingResourceTypeRepository;
use Bitrix\Booking\Internals\Repository\BookingClientRepositoryInterface;
use Bitrix\Booking\Internals\Repository\BookingRepositoryInterface;
use Bitrix\Booking\Internals\Repository\CounterRepositoryInterface;
use Bitrix\Booking\Internals\Repository\FavoritesRepositoryInterface;
use Bitrix\Booking\Internals\Repository\JournalRepositoryInterface;
use Bitrix\Booking\Internals\Repository\OptionRepositoryInterface;
use Bitrix\Booking\Internals\Repository\ORM\BookingExternalDataRepository;
use Bitrix\Booking\Internals\Repository\ORM\BookingResourceRepository;
use Bitrix\Booking\Internals\Repository\ORM\Mapper\BookingMapper;
use Bitrix\Booking\Internals\Repository\ORM\Mapper\ClientMapper;
use Bitrix\Booking\Internals\Repository\ORM\Mapper\ExternalDataItemMapper;
use Bitrix\Booking\Internals\Repository\ORM\Mapper\ResourceDataMapper;
use Bitrix\Booking\Internals\Repository\ORM\Mapper\ResourceMapper;
use Bitrix\Booking\Internals\Repository\ORM\Mapper\ResourceTypeMapper;
use Bitrix\Booking\Internals\Repository\ResourceRepositoryInterface;
use Bitrix\Booking\Internals\Repository\ResourceSlotRepositoryInterface;
use Bitrix\Booking\Internals\Repository\ResourceTypeRepositoryInterface;
use Bitrix\Booking\Internals\Repository\TransactionHandlerInterface;
use Bitrix\Booking\Internals\Service\Notifications\MessageSender\DummyBaseMessageSender;
use Bitrix\Booking\Internals\Service\Notifications\MessageSender\BookingDataExtractor;
use Bitrix\Booking\Internals\Service\Notifications\MessageSender\MessageSenderPicker;
use Bitrix\Booking\Internals\Service\Notifications\WhatsAppEmergencyService;
use Bitrix\Booking\Internals\Service\Overbooking\OverbookingService;
use Bitrix\Booking\Internals\Service\Overbooking\OverlapPolicy;
use Bitrix\Booking\Internals\Service\ResourceService;
use Bitrix\Booking\Internals\Service\ResourceAvatarService;
use Bitrix\Booking\Internals\Service\ResourceSkuService;
use Bitrix\Booking\Internals\Service\SkuService;
use Bitrix\Booking\Internals\Service\Timezone;
use Bitrix\Booking\Internals\Service\WaitListItemService;
use Bitrix\Booking\Internals\Service\Yandex\AvailableTimeSlotsProvider;
use Bitrix\Booking\Internals\Service\Yandex\ResourceProvider;
use Bitrix\Booking\Internals\Service\Yandex\ServiceProvider;
use Bitrix\Main\Access\BaseAccessController;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Booking\Internals\Service\Gis;
use Bitrix\Booking\Internals\Service\Yandex;

class Container
{
	public static function instance(): Container
	{
		return self::getService('booking.container');
	}

	private static function getService(string $name): mixed
	{
		$locator = ServiceLocator::getInstance();

		if ($locator->has($name))
		{
			return $locator->get($name);
		}

		$prefix = 'booking.';
		if (mb_strpos($name, $prefix) !== 0)
		{
			$name = $prefix . $name;
		}

		return $locator->has($name)
			? $locator->get($name)
			: null
		;
	}

	public static function getTransactionHandler(): TransactionHandlerInterface
	{
		return self::getService('booking.transaction.handler');
	}

	public static function getBookingRepository(): BookingRepositoryInterface
	{
		return self::getService('booking.booking.repository');
	}

	public static function getBookingRepositoryMapper(): BookingMapper
	{
		return self::getService('booking.booking.repository.mapper');
	}

	public static function getResourceRepository(): ResourceRepositoryInterface
	{
		return self::getService('booking.resource.repository');
	}

	public static function getResourceRepositoryMapper(): ResourceMapper
	{
		return self::getService('booking.resource.repository.mapper');
	}

	public static function getClientRepositoryMapper(): ClientMapper
	{
		return self::getService('booking.client.repository.mapper');
	}

	public static function getExternalDataItemRepositoryMapper(): ExternalDataItemMapper
	{
		return self::getService('booking.external.data.item.repository.mapper');
	}

	public static function getResourceDataRepositoryMapper(): ResourceDataMapper
	{
		return self::getService('booking.resource.data.repository.mapper');
	}

	public static function getResourceTypeAccessController(): BaseAccessController
	{
		return self::getService('booking.resource.type.access.controller');
	}

	public static function getResourceAccessController(): BaseAccessController
	{
		return self::getService('booking.resource.access.controller');
	}

	public static function getResourceTypeRepository(): ResourceTypeRepositoryInterface
	{
		return self::getService('booking.resource.type.repository');
	}

	public static function getAdvertisingTypeRepository(): AdvertisingResourceTypeRepository
	{
		return self::getService('booking.advertising.type.repository');
	}

	public static function getResourceTypeRepositoryMapper(): ResourceTypeMapper
	{
		return self::getService('booking.resource.repository.type.mapper');
	}

	public static function getJournalRepository(): JournalRepositoryInterface
	{
		return self::getService('booking.journal.repository');
	}

	public static function getJournalService(): JournalServiceInterface
	{
		return self::getService('booking.journal.service');
	}

	public static function getResourceSlotRepository(): ResourceSlotRepositoryInterface
	{
		return self::getService('booking.resource.slot.repository');
	}

	public static function getBookingResourceRepository(): BookingResourceRepository
	{
		return self::getService('booking.booking.resource.repository');
	}

	public static function getFavoritesRepository(): FavoritesRepositoryInterface
	{
		return self::getService('booking.favorites.repository');
	}

	public static function getBookingClientRepository(): BookingClientRepositoryInterface
	{
		return self::getService('booking.client.repository');
	}

	public static function getBookingSkuRepository(): BookingSkuRepositoryInterface
	{
		return self::getService('booking.sku.repository');
	}

	public static function getBookingSkuMapper(): BookingSkuMapper
	{
		return self::getService('booking.sku.mapper');
	}

	public static function getBookingExternalDataRepository(): BookingExternalDataRepository
	{
		return self::getService('booking.external.data.repository');
	}

	public static function getCounterRepository(): CounterRepositoryInterface
	{
		return self::getService('booking.counter.repository');
	}

	public static function getOptionRepository(): OptionRepositoryInterface
	{
		return self::getService('booking.option.repository');
	}

	public static function getWaitListItemRepositoryMapper(): WaitListItemMapper
	{
		return self::getService('booking.wait.list.item.repository.mapper');
	}

	public static function getWaitListItemRepository(): WaitListItemRepositoryInterface
	{
		return self::getService('booking.wait.list.item.repository');
	}

	public static function getClientTypeRepository(): ClientTypeRepositoryInterface
	{
		return self::getService('booking.client.type.repository');
	}

	public static function getClientTypeRepositoryMapper(): ClientTypeMapper
	{
		return self::getService('booking.client.type.repository.mapper');
	}

	public static function getBookingService(): BookingService
	{
		return self::getService('booking.internals.booking.service');
	}

	public static function getClientService(): ClientService
	{
		return self::getService('booking.internals.client.service');
	}

	public static function getBookingSkuService(): BookingSkuService
	{
		return self::getService('booking.internals.sku.service');
	}

	public static function getExternalDataService(): ExternalDataService
	{
		return self::getService('booking.internals.external.data.service');
	}

	public static function getResourceService(): ResourceService
	{
		return self::getService('booking.internals.resource.service');
	}

	public static function getResourceAvatarService(): ResourceAvatarService
	{
		return self::getService('booking.internals.resource.avatar.service');
	}

	public static function getWaitListItemService(): WaitListItemService
	{
		return self::getService('booking.internals.wait.list.item.service');
	}

	public static function getBookingMessageRepository(): BookingMessageRepositoryInterface
	{
		return self::getService('booking.message.repository');
	}

	public static function getOverBookingOverlapPolicy(): OverlapPolicy
	{
		return self::getService('booking.overbooking.overlap.policy');
	}

	public static function getOverbookingService(): OverbookingService
	{
		return self::getService('booking.internals.overbooking.service');
	}

	public static function getEventForBookingService(): EventForBookingService
	{
		return self::getService('booking.internals.event_for_booking.service');
	}

	public static function getDelayedTaskRepository(): DelayedTaskRepository
	{
		return self::getService('booking.internals.delayed_task.repository');
	}

	public static function getDelayedTaskService(): DelayedTaskService
	{
		return self::getService('booking.internals.delayed_task.service');
	}

	public static function getResourceLinkedEntityRepository(): ResourceLinkedEntityRepository
	{
		return self::getService('booking.internals.resource_linked_entity.repository');
	}

	public static function getYandexResourceProvider(): ResourceProvider
	{
		return self::getService('booking.internals.service.yandex.resource.provider');
	}

	public static function getYandexServiceProvider(): ServiceProvider
	{
		return self::getService('booking.internals.service.yandex.service.provider');
	}

	public static function getYandexAvailableTimeSlotsProvider(): AvailableTimeSlotsProvider
	{
		return self::getService('booking.internals.service.yandex.available.time.slots.provider');
	}

	public static function getYandexAvailableDatesProvider(): Yandex\AvailableDatesProvider
	{
		return self::getService('booking.internals.service.yandex.available.dates.provider');
	}

	public static function getYandexDeleteBookingService(): Yandex\DeleteBookingService
	{
		return self::getService('booking.internals.service.yandex.delete.booking.service');
	}

	public static function getYandexCreateBookingService(): Yandex\CreateBookingService
	{
		return self::getService('booking.internals.service.yandex.create.booking.service');
	}

	public static function getYandexUpdateBookingService(): Yandex\UpdateBookingService
	{
		return self::getService('booking.internals.service.yandex.update.booking.service');
	}

	public static function getYandexFindResourceService(): Yandex\FindResourceService
	{
		return self::getService('booking.internals.service.yandex.find.resource.service');
	}

	public static function getCrmContactSearcherService(): ContactSearcherService
	{
		return self::getService(ContactSearcherService::class);
	}

	public static function getCrmDealService(): DealService
	{
		return self::getService('booking.internals.integration.crm.deal.service');
	}

	public static function getCatalogServiceSkuProvider(): ServiceSkuProvider
	{
		return self::getService('booking.internals.integration.catalog.service.sku.provider');
	}

	public static function getYandexBookingProvider(): Yandex\BookingProvider
	{
		return self::getService('booking.internals.service.yandex.booking.provider');
	}

	public static function getYandexCompanyFeedProvider(): Yandex\CompanyFeedProvider
	{
		return self::getService('booking.internals.service.yandex.company.feed.provider');
	}

	public static function getYandexCompanyRepository(): Yandex\CompanyRepository
	{
		return self::getService('booking.internals.service.yandex.company.repository');
	}

	public static function getYandexApiClient(): Yandex\ApiClient
	{
		return self::getService('booking.internals.service.yandex.api.client');
	}

	public static function getYandexAccount(): Yandex\Account
	{
		return self::getService('booking.internals.service.yandex.account');
	}

	public static function getYandexCompanyFeedSender(): Yandex\CompanyFeedSender
	{
		return self::getService('booking.internals.service.yandex.company.feed.sender');
	}

	public static function getSkuDataLoader(): SkuDataLoader
	{
		return self::getService('booking.internals.integration.catalog.sku.data.loader');
	}

	public static function getDealDataProvider(): DealDataProvider
	{
		return self::getService(DealDataProvider::class);
	}

	public static function getCrmExternalDataItemExtractor(): ExternalDataItemExtractor
	{
		return self::getService(ExternalDataItemExtractor::class);
	}

	public static function getCrmMessageSender(): MessageSender
	{
		return self::getService(MessageSender::class);
	}

	public static function getCrmDealClientSynchronizer(): DealClientSynchronizer
	{
		return self::getService(DealClientSynchronizer::class);
	}

	public static function getCrmClientDataLoader(): ClientDataLoader
	{
		return self::getService(ClientDataLoader::class);
	}

	public static function getCrmClientAccessProvider(): ClientAccessProvider
	{
		return self::getService(ClientAccessProvider::class);
	}

	public static function getCrmClientDataRecentProvider(): ClientDataRecentProvider
	{
		return self::getService(ClientDataRecentProvider::class);
	}

	public static function getCrmClientTypeRepository(): CrmClientTypeRepository
	{
		return self::getService(CrmClientTypeRepository::class);
	}

	public static function getCrmClientDataProvider(): ClientDataProvider
	{
		return self::getService(ClientDataProvider::class);
	}

	public static function getCrmDealDataLoader(): DealDataLoader
	{
		return self::getService('booking.internals.integration.crm.deal.data.loader');
	}

	public static function getIntranetBookingTool(): BookingTool
	{
		return self::getService('booking.internals.integration.intranet.booking.tool');
	}

	public static function getResourceSkuRepository(): ResourceSkuRepository
	{
		return self::getService('booking.internals.repository.resource.sku');
	}

	public static function getResourceSkuYandexRepository(): ResourceSkuYandexRepository
	{
		return self::getService('booking.internals.repository.resource.sku.yandex');
	}

	public static function getResourceSkuService(): ResourceSkuService
	{
		return self::getService('booking.internals.service.resource.sku.service');
	}

	public static function getSkuService(): SkuService
	{
		return self::getService('booking.internals.service.sku.service');
	}

	public static function getYandexResourceSkuRelationsService(): Yandex\ResourceSkuRelationsService
	{
		return self::getService('booking.internals.service.yandex.resource.sku.relations.service');
	}

	public static function getYandexStatusService(): Yandex\StatusService
	{
		return self::getService('booking.internals.yandex.service.status');
	}

	public static function getYandexAvailabilityService(): Yandex\AvailabilityService
	{
		return self::getService(Yandex\AvailabilityService::class);
	}

	public static function getTimezoneService(): Timezone
	{
		return self::getService('booking.internals.service.timezone');
	}

	public static function getYandexIntegrationService(): Yandex\IntegrationService
	{
		return self::getService(Yandex\IntegrationService::class);
	}

	public static function getGisIntegrationService(): Gis\IntegrationService
	{
		return self::getService('booking.internals.service.gis.integration.service');
	}

	public static function getYandexCompanyFeedHashService(): Yandex\CompanyFeedHashService
	{
		return self::getService(Yandex\CompanyFeedHashService::class);
	}

	public static function getProductRowDataLoader(): ProductRowDataLoader
	{
		return self::getService(ProductRowDataLoader::class);
	}

	public static function getDealForBookingService(): DealForBookingService
	{
		return self::getService(DealForBookingService::class);
	}

	public static function getBookingPaymentRepository(): BookingPaymentRepository
	{
		return self::getService(BookingPaymentRepository::class);
	}

	public static function getWhatsAppEmergencyService(): WhatsAppEmergencyService
	{
		return self::getService(WhatsAppEmergencyService::class);
	}

	public static function getCrmFormService(): CrmFormService
	{
		return self::getService(CrmFormService::class);
	}

	public static function getCrmFormResourceAutoSelectionService(): ResourceAutoSelectionService
	{
		return self::getService(ResourceAutoSelectionService::class);
	}

	public static function getWebFormBookingBuilder(): BookingBuilder
	{
		return self::getService(BookingBuilder::class);
	}

	public static function getWebFormEventHandler(): WebFormEventHandler
	{
		return self::getService(WebFormEventHandler::class);
	}

	public static function getBookingDataExtractor(): BookingDataExtractor
	{
		return self::getService(BookingDataExtractor::class);
	}

	public static function getMyCompanyProvider(): MyCompanyProvider
	{
		return self::getService(MyCompanyProvider::class);
	}

	public static function getMessageSenderPicker(): MessageSenderPicker
	{
		return self::getService(MessageSenderPicker::class);
	}

	public static function getDummyBaseMessageSender(): DummyBaseMessageSender
	{
		return self::getService(DummyBaseMessageSender::class);
	}

	public static function getLicenseChecker(): LicenseChecker
	{
		return self::getService(LicenseChecker::class);
	}
}
