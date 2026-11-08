<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Address\Requisite;

use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Address\Requisite\RequisiteAddressDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Address\Requisite\RequisiteAddressIdentifierDto;
use Bitrix\Crm\V2\Public\Entity\Address\Address;

final class Mapper
{
	private const FIELDS = [
		'requisiteId',
		'typeId',
		'address1',
		'address2',
		'city',
		'postalCode',
		'region',
		'province',
		'country',
		'countryCode',
	];

	public function mapToDto(
		Address $address,
		int $requisiteId,
		int $addressTypeId,
		array $selectedFields = [],
		array $scopeFields = [],
	): RequisiteAddressDto
	{
		$dto = new RequisiteAddressDto();
		$selectedFields = $selectedFields ?: ($scopeFields ?: self::FIELDS);

		foreach (self::FIELDS as $field)
		{
			if (in_array($field, $selectedFields, true))
			{
				$dto->{$field} = match ($field)
				{
					'requisiteId' => $requisiteId,
					'typeId' => $addressTypeId,
					'address1' => $address->getAddress1(),
					'address2' => $address->getAddress2(),
					'city' => $address->getCity(),
					'postalCode' => $address->getPostalCode(),
					'region' => $address->getRegion(),
					'province' => $address->getProvince(),
					'country' => $address->getCountry(),
					'countryCode' => $address->getCountryCode(),
				};
			}
		}

		return $dto;
	}

	public function mapToIdentifierDto(int $requisiteId, int $addressTypeId): RequisiteAddressIdentifierDto
	{
		$dto = new RequisiteAddressIdentifierDto();
		$dto->requisiteId = $requisiteId;
		$dto->typeId = $addressTypeId;

		return $dto;
	}

	public function mapToChanges(RequisiteAddressDto $dto, array $fields): Address
	{
		$address = new Address();
		foreach (array_keys($fields) as $field)
		{
			match ($field) {
				'address1' => $address->setAddress1($dto->address1),
				'address2' => $address->setAddress2($dto->address2),
				'city' => $address->setCity($dto->city),
				'postalCode' => $address->setPostalCode($dto->postalCode),
				'region' => $address->setRegion($dto->region),
				'province' => $address->setProvince($dto->province),
				'country' => $address->setCountry($dto->country),
				'countryCode' => $address->setCountryCode($dto->countryCode),
				default => null,
			};
		}

		return $address;
	}
}
