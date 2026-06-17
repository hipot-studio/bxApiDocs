<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\Crm;

use Bitrix\Booking\Entity\Booking\Booking;
use Bitrix\Booking\Entity\Client\Client;
use Bitrix\Booking\Entity\ExternalData\ExternalDataItem;
use Bitrix\Main\Loader;
use CCrmOwnerType;

class CrmBindingsBuilder
{
	private const MODULE_ID = 'crm';

	/**
	 * @return list<array{OWNER_TYPE_ID: int, OWNER_ID: int}>
	 */
	public function getBindingsFromBooking(Booking $booking): array
	{
		if (!Loader::includeModule('crm'))
		{
			return [];
		}

		$bindings = [];

		$primaryClient = $booking->getPrimaryClient();
		if ($primaryClient !== null)
		{
			$binding = $this->buildClientBinding($primaryClient);
			if ($binding !== null)
			{
				$bindings[] = $binding;
			}
		}

		foreach ($booking->getExternalDataCollection() as $externalDataItem)
		{
			$binding = $this->buildExternalDataBinding($externalDataItem);
			if ($binding !== null)
			{
				$bindings[] = $binding;
			}
		}

		return $bindings;
	}

	/**
	 * @return list<array{OWNER_TYPE_ID: int, OWNER_ID: int}>
	 */
	public function getExternalDataBindingsFromBooking(Booking $booking): array
	{
		if (!Loader::includeModule('crm'))
		{
			return [];
		}

		$bindings = [];
		foreach ($booking->getExternalDataCollection() as $externalDataItem)
		{
			$binding = $this->buildExternalDataBinding($externalDataItem);
			if ($binding !== null)
			{
				$bindings[] = $binding;
			}
		}

		return $bindings;
	}

	/**
	 * @return array{OWNER_TYPE_ID: int, OWNER_ID: int}|null
	 */
	private function buildClientBinding(Client $client): array|null
	{
		if ($client->getType()?->getModuleId() !== self::MODULE_ID)
		{
			return null;
		}

		$ownerTypeId = match ($client->getType()?->getCode())
		{
			CCrmOwnerType::ContactName => CCrmOwnerType::Contact,
			CCrmOwnerType::CompanyName => CCrmOwnerType::Company,
			default => null,
		};

		if ($ownerTypeId === null || $client->getId() === null)
		{
			return null;
		}

		return [
			'OWNER_TYPE_ID' => $ownerTypeId,
			'OWNER_ID' => $client->getId(),
		];
	}

	/**
	 * @return array{OWNER_TYPE_ID: int, OWNER_ID: int}|null
	 */
	private function buildExternalDataBinding(ExternalDataItem $item): array|null
	{
		if ($item->getModuleId() !== self::MODULE_ID)
		{
			return null;
		}

		$entityTypeId = $item->getEntityTypeId();
		$value = $item->getValue();
		if ($entityTypeId === null || $value === null)
		{
			return null;
		}

		$isDeal = $entityTypeId === CCrmOwnerType::DealName;
		$isDynamicType = mb_strpos($entityTypeId, CCrmOwnerType::DynamicTypePrefixName) === 0;
		if (!$isDeal && !$isDynamicType)
		{
			return null;
		}

		$ownerTypeId = CCrmOwnerType::ResolveID($entityTypeId);
		if ($ownerTypeId === CCrmOwnerType::Undefined)
		{
			return null;
		}

		return [
			'OWNER_TYPE_ID' => $ownerTypeId,
			'OWNER_ID' => (int)$value,
		];
	}
}
