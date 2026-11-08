<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Infrastructure\Rest\Dto\Mapping;

use Bitrix\Catalog\Infrastructure\Rest\Dto\ContractorDto;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Dto\Mapping\Mapper;

final class ContractorMapper extends Mapper
{
	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		$collection = new DtoCollection(ContractorDto::class);
		$emptyFields = $fields === [];

		foreach ($items as $rawItem)
		{
			if (!is_array($rawItem))
			{
				continue;
			}

			$dto = new ContractorDto();
			if ($emptyFields || in_array('id', $fields, true) || array_key_exists('id', $fields))
			{
				$id = $rawItem['id'] ?? $rawItem['ID'] ?? null;
				$dto->id = $id !== null ? (int)$id : null;
			}
			if ($emptyFields || in_array('companyName', $fields, true) || array_key_exists('companyName', $fields))
			{
				$name = $rawItem['companyName'] ?? $rawItem['COMPANY'] ?? null;
				$dto->companyName = $name !== null ? (string)$name : null;
			}

			$collection->add($dto);
		}

		return $collection;
	}
}
