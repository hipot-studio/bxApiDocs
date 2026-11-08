<?php

namespace Bitrix\Main\Infrastructure\Rest\Dto\Mapping;

use Bitrix\Main\Infrastructure\Rest\Dto\UserFieldTypeDto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\Mapping\Mapper;

class UserFieldTypeMapper extends Mapper
{
	private static array $itemFieldMapping = [
		'userTypeId' => 'USER_TYPE_ID',
		'description' => 'DESCRIPTION',
	];

	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		$collection = new DtoCollection(UserFieldTypeDto::class);

		foreach ($items as $rawItem)
		{
			if (!is_array($rawItem))
			{
				continue;
			}

			$collection->add($this->mapItem($rawItem, $fields));
		}

		return $collection;
	}

	private function mapItem(array $rawItem, array $fields): UserFieldTypeDto
	{
		$dto = new UserFieldTypeDto();
		$emptyFields = empty($fields);

		foreach (self::$itemFieldMapping as $dtoField => $dataField)
		{
			if (!$emptyFields && !in_array($dtoField, $fields, true) && !array_key_exists($dtoField, $fields))
			{
				continue;
			}

			$value = $rawItem[$dataField] ?? null;
			$dto->{$dtoField} = $value !== null ? (string)$value : null;
		}

		return $dto;
	}
}
