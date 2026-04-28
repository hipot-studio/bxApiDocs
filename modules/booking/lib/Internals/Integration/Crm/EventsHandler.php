<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\Crm;

use Bitrix\Booking\Internals\Container;
use Bitrix\Crm\Item;
use Bitrix\Main\Event;
use CCrmOwnerType;

class EventsHandler
{
	private const MODULE_ID = 'crm';

	public static function onContactDelete(int $contactId): void
	{
		self::onClientDelete(CCrmOwnerType::ContactName, $contactId);
	}

	public static function onCompanyDelete(int $companyId): void
	{
		self::onClientDelete(CCrmOwnerType::CompanyName, $companyId);
	}

	private static function onClientDelete(string $entityTypeName, int $entityId): void
	{
		$clientType = Container::getClientTypeRepository()->get($entityTypeName, self::MODULE_ID);
		if (!$clientType)
		{
			return;
		}

		Container::getBookingClientRepository()->unLinkByFilter([
			'=CLIENT_TYPE_ID' => (int)$clientType->getId(),
			'=CLIENT_ID' => $entityId,
		]);
	}

	public static function onDealDelete(int $dealId): void
	{
		Container::getBookingExternalDataRepository()->unLinkByFilter([
			'=MODULE_ID' => self::MODULE_ID,
			'=ENTITY_TYPE_ID' => CCrmOwnerType::DealName,
			'=VALUE' => (string)$dealId,
		]);
	}

	public static function onDynamicItemDelete(Event $event): void
	{
		$id = (string)$event->getParameter('id');
		if (!$id)
		{
			return;
		}

		/** @var Item $item */
		$item = $event->getParameter('item');

		if (!$item instanceof Item)
		{
			return;
		}

		$entityTypeId = CCrmOwnerType::ResolveName($item->getEntityTypeId());
		if (!$entityTypeId)
		{
			return;
		}

		Container::getBookingExternalDataRepository()->unLinkByFilter([
			'=MODULE_ID' => self::MODULE_ID,
			'=ENTITY_TYPE_ID' => $entityTypeId,
			'=VALUE' => $id,
		]);
	}

	public static function onCrmBookingFormFilled(Event $event): string
	{
		return Container::getWebFormEventHandler()->handle($event);
	}
}
