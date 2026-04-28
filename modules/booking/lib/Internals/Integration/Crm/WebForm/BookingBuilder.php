<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\Crm\WebForm;

use Bitrix\Booking\Entity\Booking\Booking;
use Bitrix\Booking\Entity\Booking\BookingPayment;
use Bitrix\Booking\Entity\Booking\BookingSku;
use Bitrix\Booking\Entity\Booking\BookingSkuCollection;
use Bitrix\Booking\Entity\Booking\BookingSource;
use Bitrix\Booking\Entity\Client\Client;
use Bitrix\Booking\Entity\Client\ClientCollection;
use Bitrix\Booking\Entity\Client\ClientType;
use Bitrix\Booking\Entity\DatePeriod;
use Bitrix\Booking\Entity\ExternalData\ExternalDataCollection;
use Bitrix\Booking\Entity\ExternalData\ExternalDataItem;
use Bitrix\Booking\Entity\Resource\Resource;
use Bitrix\Booking\Entity\Resource\ResourceCollection;
use CCrmOwnerType;
use CCrmOwnerTypeAbbr;
use CCrmProductRow;
use DateTimeImmutable;
use DateTimeZone;

class BookingBuilder
{
	private const MODULE_ID = 'crm';

	private array $timelineBindings = [];

	public function build(array $value, array $crmEntityList, mixed $paymentId = null): Booking
	{
		$this->timelineBindings = [];

		$booking = new Booking();

		if (isset($value['dateFromTs'], $value['dateToTs'], $value['timezone']))
		{
			$booking->setDatePeriod(
				new DatePeriod(
					(new DateTimeImmutable('@' . (int)$value['dateFromTs']))
						->setTimezone(new DateTimeZone((string)$value['timezone'])),
					(new DateTimeImmutable('@' . (int)$value['dateToTs']))
						->setTimezone(new DateTimeZone((string)$value['timezone'])),
				),
			);
		}

		$resources = isset($value['resources']) && is_array($value['resources']) ? $value['resources'] : [];
		$crmEntityProductRowMap = $this->getCrmEntityProductRowMap($crmEntityList);

		$resourceCollection = new ResourceCollection();
		foreach ($resources as $resource)
		{
			if (!isset($resource['id']))
			{
				continue;
			}

			$resourceCollection->add((new Resource())->setId((int)$resource['id']));

			if (isset($resource['skus']))
			{
				$skus = array_map(
					static fn(array $sku) => (new BookingSku())
						->setId((int)$sku['id'])
						->setProductRowId(
							isset($crmEntityProductRowMap[(int)$sku['id']])
								? (int)$crmEntityProductRowMap[(int)$sku['id']]
								: null
						),
					$resource['skus'],
				);

				$booking->setSkuCollection(new BookingSkuCollection(...$skus));
			}
		}

		$booking->setResourceCollection($resourceCollection);

		$booking
			->setSource(BookingSource::CrmForm)
			->setClientCollection($this->getClientCollectionFromEntityList($crmEntityList))
			->setExternalDataCollection($this->getExternalDataCollectionFromEntityList($crmEntityList))
		;

		if (!$booking->getSkuCollection()->isEmpty() && $paymentId)
		{
			$booking->setPayment((new BookingPayment())->setId((int)$paymentId));
		}

		return $booking;
	}

	public function getTimelineBindings(): array
	{
		return $this->timelineBindings;
	}

	private function getClientCollectionFromEntityList(array $crmEntityList): ClientCollection
	{
		$clientCollection = new ClientCollection();

		foreach ($crmEntityList as $crmEntity)
		{
			if ($crmEntity['ENTITY_TYPE'] === CCrmOwnerType::ContactName)
			{
				$clientCollection->add(
					(new Client())
						->setId((int)$crmEntity['ENTITY_ID'])
						->setType(
							(new ClientType())
								->setModuleId(self::MODULE_ID)
								->setCode(CCrmOwnerType::ContactName)
						)
				);

				$this->timelineBindings[] = [
					'OWNER_TYPE_ID' => CCrmOwnerType::Contact,
					'OWNER_ID' => (int)$crmEntity['ENTITY_ID'],
				];
			}

			if ($crmEntity['ENTITY_TYPE'] === CCrmOwnerType::CompanyName)
			{
				$clientCollection->add(
					(new Client())
						->setId((int)$crmEntity['ENTITY_ID'])
						->setType(
							(new ClientType())
								->setModuleId(self::MODULE_ID)
								->setCode(CCrmOwnerType::CompanyName)
						)
				);

				$this->timelineBindings[] = [
					'OWNER_TYPE_ID' => CCrmOwnerType::Company,
					'OWNER_ID' => (int)$crmEntity['ENTITY_ID'],
				];
			}
		}

		return $clientCollection;
	}

	private function getExternalDataCollectionFromEntityList(array $crmEntityList): ExternalDataCollection
	{
		$externalDataCollection = new ExternalDataCollection();

		foreach ($crmEntityList as $crmEntity)
		{
			if (
				$crmEntity['ENTITY_TYPE'] === CCrmOwnerType::DealName
				|| mb_strpos($crmEntity['ENTITY_TYPE'], CCrmOwnerType::DynamicTypePrefixName) === 0
			)
			{
				$externalDataCollection->add(
					(new ExternalDataItem())
						->setModuleId(self::MODULE_ID)
						->setValue((string)$crmEntity['ENTITY_ID'])
						->setEntityTypeId($crmEntity['ENTITY_TYPE'])
				);

				$this->timelineBindings[] = [
					'OWNER_TYPE_ID' => CCrmOwnerType::ResolveID($crmEntity['ENTITY_TYPE']),
					'OWNER_ID' => (int)$crmEntity['ENTITY_ID'],
				];
			}
		}

		return $externalDataCollection;
	}

	private function getCrmEntityProductRowMap(array $crmEntityList): array
	{
		$foundCrmEntity = null;
		foreach ($crmEntityList as $crmEntity)
		{
			if (
				$crmEntity['ENTITY_TYPE'] === CCrmOwnerType::DealName
				|| mb_strpos($crmEntity['ENTITY_TYPE'], CCrmOwnerType::DynamicTypePrefixName) === 0
			)
			{
				$foundCrmEntity = $crmEntity;
				break;
			}
		}

		if (!$foundCrmEntity)
		{
			return [];
		}

		$productRowsList = CCrmProductRow::GetList(
			arFilter: [
				'OWNER_TYPE' => CCrmOwnerTypeAbbr::ResolveByTypeID(
					CCrmOwnerType::ResolveID($foundCrmEntity['ENTITY_TYPE'])
				),
				'OWNER_ID' => (int)$foundCrmEntity['ENTITY_ID'],
			],
			arSelectFields: ['ID', 'PRODUCT_ID'],
		);

		$result = [];
		while ($productRow = $productRowsList->fetch())
		{
			$result[(int)$productRow['PRODUCT_ID']] = (int)$productRow['ID'];
		}

		return $result;
	}
}
